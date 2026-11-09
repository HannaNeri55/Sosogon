<?php

namespace Database\Seeders;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\ListingEstimate;
use App\Models\RetailShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Estimated values for details live listings are missing, in listing_estimates only: it never
 * writes to the listings' own columns (so coordinates, owners' entries and approvals stay as
 * they are). Safe to run again: each estimate is created or refreshed in place, and one whose
 * listing has since got a real value is removed.
 *
 * Every rule is based on the values already recorded for similar listings in the database
 * (e.g. museums 8 AM–5 PM, beaches ₱10–70) or, for prices, on the same price bands the
 * itinerary planner already uses (config services.itinerary.price_bands).
 */
class ListingEstimateSeeder extends Seeder
{
    /** Typical opening hours by destination category (from recorded hours of similar places). */
    private const DESTINATION_HOURS = [
        'Beach' => '6:00 AM - 6:00 PM',
        'Mountain' => '6:00 AM - 6:00 PM',
        'Waterfall' => '7:00 AM - 5:00 PM',
        'Church' => '6:00 AM - 6:00 PM',
        'Park' => '6:00 AM - 8:00 PM',
        'Eco-Tourism' => '7:00 AM - 5:00 PM',
        'Island' => '6:00 AM - 5:00 PM',
        'Resort' => '8:00 AM - 6:00 PM',
    ];

    /** Typical entrance fee (₱, "min-max" or one amount) by category (from recorded fees of similar places). */
    private const DESTINATION_FEES = [
        'Beach' => '20-50',
        'Mountain' => '30-50',
        'Waterfall' => '20-50',
        'Church' => '0',
        'Park' => '0-20',
        'Eco-Tourism' => '20-60',
        'Island' => '20-50',
        'Resort' => '50-100',
    ];

    /** Indoor heritage sites keep museum hours; open-air ones (monuments, ruins, plazas) daylight hours. */
    private const INDOOR_HERITAGE = ['museum', 'museo', 'library', 'gallery'];

    private array $counts = [];

    public function run(): void
    {
        foreach (Destination::published()->with('category')->get() as $destination) {
            $this->destination($destination);
        }
        foreach (Accommodation::approved()->get() as $accommodation) {
            $this->accommodation($accommodation);
        }
        foreach (FoodBeverage::approved()->get() as $place) {
            $this->foodPlace($place);
        }
        foreach (RetailShop::approved()->get() as $shop) {
            $this->shop($shop);
        }

        $this->command?->info('Listing estimates: '.json_encode($this->counts));
    }

    private function destination(Destination $destination): void
    {
        $category = (string) $destination->category?->name;
        $indoor = $category === 'Historical' && Str::contains(mb_strtolower($destination->name), self::INDOOR_HERITAGE);

        $hours = $category === 'Historical' ? ($indoor ? '8:00 AM - 5:00 PM' : '6:00 AM - 6:00 PM') : (self::DESTINATION_HOURS[$category] ?? null);
        $this->set($destination, 'operating_hours', $this->blank($destination->operating_hours) ? $hours : null,
            "Typical hours for a {$category}".($category === 'Historical' ? ($indoor ? ' (indoor site)' : ' (open-air site)') : '').' listing, from recorded hours of similar places');

        $fee = $category === 'Historical' ? ($indoor ? '20-50' : '0') : (self::DESTINATION_FEES[$category] ?? null);
        // Never when the destination has its own Fees & Rates list (or fee).
        $this->set($destination, 'entrance_fee', $destination->entrance_fee === null && ! $destination->fees()->exists() ? $fee : null,
            "Typical entrance fee for a {$category} listing, from recorded fees of similar places");
    }

    private function accommodation(Accommodation $stay): void
    {
        $listsCheckIn = (bool) preg_match('/check[\s-]?in/i', (string) $stay->operating_hours);
        $this->set($stay, 'check_in', $listsCheckIn ? null : '2:00 PM', 'Common check-in time in Philippine hotels and inns');
        $this->set($stay, 'check_out', $listsCheckIn ? null : '12:00 PM', 'Common check-out time in Philippine hotels and inns');

        $hasRates = $stay->rooms()->approved()->where('price', '>', 0)->exists();
        $this->set($stay, 'nightly_rate', $hasRates ? null : $this->band('room_per_night', $stay->price_range),
            "Planner price band for a {$stay->price_range} stay");
    }

    private function foodPlace(FoodBeverage $place): void
    {
        $text = mb_strtolower("{$place->cuisine_type} {$place->name} {$place->description}");
        $hours = match (true) {
            Str::contains($text, ['pizza', 'italian']) => '10:00 AM - 9:00 PM',
            Str::contains($text, ['events', 'catering']) => '9:00 AM - 9:00 PM',
            Str::contains($text, ['filipino', 'bicol', 'local food', 'carinderia', 'eatery']) => '7:00 AM - 8:00 PM',
            default => '9:00 AM - 9:00 PM',
        };
        $this->set($place, 'operating_hours', $this->blank($place->operating_hours) ? $hours : null,
            'Typical hours for this kind of food place, from recorded hours of similar places');

        // Only from what the listing's own description says.
        $cuisine = Str::contains(mb_strtolower((string) $place->description), ['filipino', 'bicol', 'local food']) ? 'Filipino' : null;
        $this->set($place, 'cuisine_type', $this->blank($place->cuisine_type) ? $cuisine : null,
            "From the listing's own description");

        $hasPrices = $place->menuItems()->approved()->where('price', '>', 0)->exists();
        $this->set($place, 'meal_price', $hasPrices ? null : $this->band('meal_per_person', $place->price_range),
            "Planner price band for a {$place->price_range} food place, per person");
    }

    private function shop(RetailShop $shop): void
    {
        $hasPrices = $shop->products()->approved()->where('price', '>', 0)->exists();
        [$min, $max] = config('services.itinerary.price_bands.souvenirs_per_person');
        $this->set($shop, 'item_price', $hasPrices ? null : "{$min}-{$max}", 'Planner price band for souvenirs, per person');
    }

    /** Stores $value as the estimate for $field, or removes the estimate when $value is null (a real value exists or no rule applies). */
    private function set(Model $listing, string $field, ?string $value, string $basis): void
    {
        $key = ['estimable_type' => $listing->getMorphClass(), 'estimable_id' => $listing->getKey(), 'field' => $field];
        if ($value === null) {
            ListingEstimate::where($key)->delete();

            return;
        }

        ListingEstimate::updateOrCreate($key, ['value' => $value, 'basis' => $basis]);
        $this->counts[class_basename($listing).".{$field}"] = ($this->counts[class_basename($listing).".{$field}"] ?? 0) + 1;
    }

    private function band(string $key, ?string $level): string
    {
        $bands = config("services.itinerary.price_bands.{$key}");
        [$min, $max] = $bands[$level === 'medium' ? 'moderate' : $level] ?? $bands['moderate'];

        return "{$min}-{$max}";
    }

    private function blank($value): bool
    {
        return trim((string) $value) === '';
    }
}
