<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\RetailShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Nearest-listing lookup shared by the public detail endpoints. Results carry
 * distance_km (straight-line, from stored coordinates) and are sorted nearest
 * first; listings without usable coordinates are never guessed at.
 */
trait FindsNearbyPlaces
{
    /**
     * Every "nearby" section of a detail page: destinations, accommodations, food & beverage
     * and shops within config('nearby.radius_km') of $from, nearest first, never $from itself.
     * Sections stay short or empty rather than being padded with far-away places.
     */
    private function nearbyPlaces(Model $from, ?int $limit = null, int $precision = 1): array
    {
        $radiusKm = (float) config('nearby.radius_km', 10);
        $limit ??= (int) config('nearby.limit', 5);
        $find = function (Builder $query, array $with = ['images', 'municipality']) use ($from, $limit, $radiusKm, $precision) {
            if ($query->getModel() instanceof $from) {
                $query->whereKeyNot($from->getKey());
            }

            return $this->nearest($query, $from, $limit, $with, $radiusKm, $precision);
        };

        return [
            'nearby_radius_km' => $radiusKm,
            'has_coordinates' => $this->hasUsableCoordinates($from),
            'nearby_destinations' => $find(Destination::published(), ['images', 'municipality', 'category']),
            'nearby_accommodations' => $find(Accommodation::approved()),
            'nearby_food_beverages' => $find(FoodBeverage::approved()),
            'nearby_retail_shops' => $find(RetailShop::approved()),
        ];
    }

    /**
     * @param  float|null  $radiusKm  only return places within this distance (null = no limit)
     * @param  int  $precision  decimals kept on distance_km
     */
    private function nearest(
        Builder $query,
        Model $from,
        int $limit = 6,
        array $with = ['images', 'municipality'],
        ?float $radiusKm = null,
        int $precision = 1
    ): Collection {
        if (! $this->hasUsableCoordinates($from)) {
            return collect();
        }

        $query->whereNotNull('latitude')->whereNotNull('longitude');

        if ($radiusKm !== null) {
            // Cheap bounding-box pre-filter in SQL; the exact haversine check follows.
            $lat = (float) $from->latitude;
            $dLat = $radiusKm / 111.32;
            $dLng = $radiusKm / max(0.01, 111.32 * cos(deg2rad($lat)));
            $query->whereBetween('latitude', [$lat - $dLat, $lat + $dLat])
                ->whereBetween('longitude', [(float) $from->longitude - $dLng, (float) $from->longitude + $dLng]);
        }

        return $query
            ->with($with)
            ->get()
            ->filter(fn ($place) => $this->hasUsableCoordinates($place))
            ->each(fn ($place) => $place->setAttribute(
                'distance_km',
                round($this->km($from, $place), $precision)
            ))
            ->when($radiusKm !== null, fn ($places) => $places->filter(fn ($place) => $place->distance_km <= $radiusKm))
            ->sortBy('distance_km')
            ->take($limit)
            ->values();
    }

    /** Latitude/longitude present, in range, and not the 0,0 placeholder. */
    private function hasUsableCoordinates(Model $place): bool
    {
        if ($place->latitude === null || $place->longitude === null) {
            return false;
        }

        $lat = (float) $place->latitude;
        $lng = (float) $place->longitude;

        return abs($lat) <= 90 && abs($lng) <= 180 && ! ($lat == 0.0 && $lng == 0.0);
    }

    /**
     * Calculate approximate straight-line (haversine) distance in kilometers.
     */
    private function km($a, $b): float
    {
        $dLat = deg2rad(
            (float) $b->latitude - (float) $a->latitude
        );

        $dLng = deg2rad(
            (float) $b->longitude - (float) $a->longitude
        );

        $h = sin($dLat / 2) ** 2
            + cos(deg2rad((float) $a->latitude))
            * cos(deg2rad((float) $b->latitude))
            * sin($dLng / 2) ** 2;

        return 6371 * 2 * atan2(
            sqrt($h),
            sqrt(max(0, 1 - $h))
        );
    }
}
