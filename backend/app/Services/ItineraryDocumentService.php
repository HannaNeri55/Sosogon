<?php

namespace App\Services;

use App\Models\Itinerary;
use App\Models\ItineraryItem;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;
use Throwable;

/**
 * The itinerary as a standalone document: one template, used for the PDF, for printing and for
 * the copy kept offline. It only reads the saved itinerary. Images are embedded so the document
 * needs nothing else to open.
 */
class ItineraryDocumentService
{
    private const IMAGE_WIDTH = 360;

    /** Mirrors frontend/src/utils/transportGuidance.js: label, Google Maps mode, link text, time wording. */
    private const TRANSPORT = [
        'private_car' => ['Private Car', 'driving', 'Driving directions', 'drive'],
        'rented_vehicle' => ['Rented Vehicle', 'driving', 'Driving directions', 'drive'],
        'motorcycle' => ['Motorcycle', 'driving', 'Road directions', 'by road (car estimate)'],
        'public_transport' => ['Public Transportation', 'transit', 'Public transport in Google Maps', null],
        'no_preference' => ['No Preference', '', 'Directions in Google Maps', 'by car (estimate)'],
    ];

    private const STAY_BADGES = [
        '/^Arrive and check in at/' => 'Arrival & check-in',
        '/^Arrive at/' => 'Arrival',
        '/^Check in at/' => 'Check-in',
        '/^Check out of/' => 'Check-out',
        '/^Back to .+ to rest/' => 'Rest at your stay',
        '/^Free time at/' => 'Free time at your stay',
    ];

    /** @var array<string, string|null> */
    private array $embedded = [];

    public function html(Itinerary $itinerary, bool $shared = false): string
    {
        return view('itineraries.document', $this->viewData($itinerary, $shared))->render();
    }

    public function pdf(Itinerary $itinerary, bool $shared = false): DomPdf
    {
        $pdf = Pdf::loadView('itineraries.document', $this->viewData($itinerary, $shared))->setPaper('a4');
        $pdf->render();

        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $canvas->page_text(
            42,
            $canvas->get_height() - 28,
            'SOSOGON · '.Str::limit($itinerary->title, 70).' · Page {PAGE_NUM} of {PAGE_COUNT}',
            $dompdf->getFontMetrics()->getFont('DejaVu Sans'),
            7.5,
            [0.42, 0.45, 0.5],
        );

        return $pdf;
    }

    public function filename(Itinerary $itinerary): string
    {
        return (Str::slug($itinerary->title) ?: 'itinerary').'-sosogon.pdf';
    }

    public function datesLabel(Itinerary $itinerary): ?string
    {
        if (! $itinerary->start_date) {
            return null;
        }
        $end = $itinerary->end_date ?? $itinerary->start_date->copy()->addDays(max((int) $itinerary->num_days - 1, 0));

        return $itinerary->start_date->equalTo($end)
            ? $itinerary->start_date->format('F j, Y')
            : $itinerary->start_date->format('F j, Y').' – '.$end->format('F j, Y');
    }

    private function viewData(Itinerary $itinerary, bool $shared): array
    {
        $guide = self::TRANSPORT[$itinerary->transport_preference] ?? self::TRANSPORT['no_preference'];
        $travelers = max((int) $itinerary->travelers, 1);

        return [
            'itinerary' => $itinerary,
            'logo' => $this->embedLogo(),
            'dates' => $this->datesLabel($itinerary),
            'towns' => $itinerary->municipalities->pluck('name')->whenEmpty(
                fn ($names) => $names->push($itinerary->municipality?->name)->filter()
            )->implode(', '),
            'transportLabel' => $guide[0],
            'travelers' => $travelers,
            'cost' => $itinerary->estimated_cost_max === null ? null : $this->pesoRange($itinerary->estimated_cost_min, $itinerary->estimated_cost_max),
            'stays' => $this->stays($itinerary),
            'days' => $this->days($itinerary, $guide),
            'preferences' => $this->preferences($itinerary, $shared),
            'generatedAt' => now()->timezone('Asia/Manila'),
        ];
    }

    /** Interests and answers from the form. What the tourist typed themselves is left out of shared copies. */
    private function preferences(Itinerary $itinerary, bool $shared): array
    {
        $rows = array_filter([
            'Interests' => collect($itinerary->interests ?? [])->map(fn ($i) => $this->label($i))->implode(', '),
            'Travel pace' => $this->label($itinerary->travel_pace),
            'Budget' => $this->label($itinerary->budget),
            'Food' => $this->label($itinerary->food_preference),
            'Stay type' => $this->label($itinerary->accommodation_type),
        ]);
        if (! $shared && filled($itinerary->additional_preferences)) {
            $rows['Your notes'] = $itinerary->additional_preferences;
        }

        return $rows;
    }

    private function label(mixed $value): string
    {
        $value = is_array($value) ? implode(', ', $value) : trim((string) $value);

        return preg_match('/^[a-z_]+$/', $value) ? Str::headline($value) : $value;
    }

    private function stays(Itinerary $itinerary): array
    {
        // Itineraries from before stays existed have one accommodation for every night.
        $stays = $itinerary->stays->isNotEmpty() || ! $itinerary->accommodation
            ? $itinerary->stays
            : collect([(object) [
                'accommodation' => $itinerary->accommodation,
                'municipality' => $itinerary->accommodation->municipality,
                'night_days' => range(1, max((int) $itinerary->num_days - 1, 1)),
                'cost_min' => $itinerary->accommodation_cost_min,
                'cost_max' => $itinerary->accommodation_cost_max,
            ]]);

        return $stays->filter(fn ($stay) => $stay->accommodation)->map(function ($stay) {
            $place = $stay->accommodation;
            $nights = $stay->night_days ?? [];

            return [
                'name' => $place->name,
                'town' => $stay->municipality?->name ?? $place->municipality?->name,
                'nights' => $nights === [] ? 'No night planned here' : (count($nights) === 1 ? 'Night after Day '.$nights[0] : 'Nights after Days '.implode(', ', $nights)),
                'address' => $place->address,
                'phone' => $place->contact_number,
                'cost' => (float) $stay->cost_max > 0 ? $this->pesoRange($stay->cost_min, $stay->cost_max) : null,
                'image' => $this->embedImage($place),
            ];
        })->values()->all();
    }

    private function days(Itinerary $itinerary, array $guide): array
    {
        $stays = $itinerary->stays;

        return $itinerary->items->groupBy('day_number')->sortKeys()->map(function ($stops, $dayNumber) use ($itinerary, $guide, $stays) {
            $rows = $this->withLunchBreaks($stops->values()->all());
            $overnight = $stays->first(fn ($stay) => in_array((int) $dayNumber, $stay->night_days ?? [], true));

            return [
                'number' => (int) $dayNumber,
                'date' => $itinerary->start_date?->copy()->addDays((int) $dayNumber - 1)->format('l, F j, Y'),
                'town' => $overnight?->municipality?->name,
                'overnight' => $overnight?->accommodation?->name,
                'rows' => collect($rows)->values()->map(function ($row, $index) use ($rows, $guide) {
                    $next = $rows[$index + 1]['stop'] ?? null;

                    return [
                        'stop' => $this->stop($row['stop']),
                        'lunch' => $row['lunch'] ? $this->stop($row['lunch']) : null,
                        'leg' => $next ? $this->leg($row['stop'], $next, $guide) : null,
                    ];
                })->all(),
            ];
        })->values()->all();
    }

    /** A lunch break that falls inside the visit listed before it is shown under that visit. */
    private function withLunchBreaks(array $stops): array
    {
        $rows = [];
        foreach ($stops as $stop) {
            $last = array_key_last($rows);
            if ($last !== null && ! $rows[$last]['lunch'] && $this->isLunchDuring($stop, $rows[$last]['stop'])) {
                $rows[$last]['lunch'] = $stop;
            } else {
                $rows[] = ['stop' => $stop, 'lunch' => null];
            }
        }

        return $rows;
    }

    private function isLunchDuring(ItineraryItem $stop, ItineraryItem $host): bool
    {
        if ($stop->item_type !== ItineraryItem::TYPE_FOOD_BEVERAGE || ! str_starts_with((string) $stop->notes, 'Lunch break')) {
            return false;
        }
        [$start, $end] = $this->slotTimes($stop->time_slot);
        [$hostStart, $hostEnd] = $this->slotTimes($host->time_slot);

        return $start !== null && $hostStart !== null && $end !== null && $hostEnd !== null && $start >= $hostStart && $end <= $hostEnd;
    }

    /** "7:30 AM – 11:30 AM" as minutes after midnight. */
    private function slotTimes(?string $slot): array
    {
        $parts = array_map('trim', explode('–', (string) $slot));
        $minutes = function (?string $time) {
            try {
                return filled($time) ? Carbon::parse($time)->secondsSinceMidnight() / 60 : null;
            } catch (Throwable) {
                return null;
            }
        };

        return [$minutes($parts[0] ?? null), $minutes($parts[1] ?? null)];
    }

    private function place(ItineraryItem $stop): ?Model
    {
        return match ($stop->item_type) {
            ItineraryItem::TYPE_DESTINATION => $stop->destination,
            ItineraryItem::TYPE_ACCOMMODATION => $stop->accommodation,
            ItineraryItem::TYPE_FOOD_BEVERAGE => $stop->foodBeverage,
            ItineraryItem::TYPE_RETAIL_SHOP => $stop->retailShop,
            default => null,
        };
    }

    private function badge(ItineraryItem $stop): string
    {
        $notes = (string) $stop->notes;

        if ($stop->item_type === ItineraryItem::TYPE_FOOD_BEVERAGE) {
            return preg_match('/^(Breakfast|Lunch|Dinner)/', $notes, $m) ? $m[1] : 'Food stop';
        }
        if ($stop->item_type === ItineraryItem::TYPE_RETAIL_SHOP) {
            return 'Souvenir shopping';
        }
        if ($stop->item_type === ItineraryItem::TYPE_ACCOMMODATION) {
            if (preg_match('/^(Breakfast|Lunch|Dinner) at/', $notes, $m)) {
                return $m[1].' at your stay';
            }
            foreach (self::STAY_BADGES as $pattern => $badge) {
                if (preg_match($pattern, $notes)) {
                    return $badge;
                }
            }

            return 'Free day';
        }

        $part = preg_match('/^(Morning|Afternoon)/', $notes, $m) ? $m[1] : null;

        return collect([$part, $stop->destination?->category?->name ?? 'Destination'])->filter()->implode(' · ');
    }

    private function stop(ItineraryItem $stop): array
    {
        $place = $this->place($stop);
        $isVisit = $stop->item_type === ItineraryItem::TYPE_DESTINATION;
        $description = $isVisit ? trim((string) $place?->description) : '';
        $picks = $stop->item_type === ItineraryItem::TYPE_FOOD_BEVERAGE ? $stop->dishes : ($stop->item_type === ItineraryItem::TYPE_RETAIL_SHOP ? $stop->souvenirs : collect());

        return [
            'name' => $place?->name ?? 'Listing no longer available',
            'town' => $place?->municipality?->name,
            'badge' => $this->badge($stop),
            'slot' => $stop->time_slot,
            'minutes' => (int) $stop->duration_minutes,
            'description' => $description,
            'notes' => $this->notesWithoutDescription((string) $stop->notes, $description),
            'reason' => $stop->reason,
            'preferenceNote' => $stop->preference_note,
            'activities' => $stop->activities ?? [],
            'picks' => $picks->map(fn ($pick) => [
                'name' => $pick->name,
                'price' => (float) $pick->price,
                'quantity' => (int) ($pick->pivot->quantity ?? 1),
            ])->all(),
            'costNote' => $stop->cost_note,
            'cost' => (float) $stop->cost_max > 0 ? $this->pesoRange($stop->cost_min, $stop->cost_max) : null,
            'image' => $isVisit ? $this->embedImage($place) : null,
        ];
    }

    /** Older itineraries began a visit's notes with its description, which is already printed above them. */
    private function notesWithoutDescription(string $notes, string $description): string
    {
        $repeated = '';
        foreach (preg_split('/(?<=[.!?])\s+/', preg_replace('/\s+/', ' ', $description)) as $sentence) {
            $next = trim($repeated.' '.$sentence);
            if ($description === '' || ! str_starts_with($notes, $next)) {
                break;
            }
            $repeated = $next;
        }

        return trim(substr($notes, strlen($repeated)));
    }

    /** The trip to the next stop, worded as on the itinerary page. */
    private function leg(ItineraryItem $from, ItineraryItem $to, array $guide): ?array
    {
        [, $travelMode, $linkLabel, $timeWord] = $guide;
        $origin = $this->coordinates($from);
        $destination = $this->coordinates($to);
        $km = (float) $to->distance_from_prev_km;
        $minutes = (int) $to->travel_time_from_prev;

        if (! $origin || ! $destination || $origin === $destination) {
            $facts = array_values(array_filter([$km > 0 ? "{$km} km" : null, $minutes > 0 ? "~{$minutes} min to next stop" : null]));

            return $facts === [] ? null : ['facts' => $facts, 'url' => null, 'linkLabel' => null];
        }

        $measured = str_contains((string) $to->notes, 'driving time from Google Maps');
        $facts = [];
        if ($km > 0) {
            $facts[] = $measured ? "{$km} km by road (Google Maps)" : "{$km} km apart (straight line)";
        }
        if ($timeWord && $minutes > 0) {
            $facts[] = $measured && $timeWord === 'drive'
                ? "~{$minutes} min drive (Google Maps)"
                : "~{$minutes} min ".($timeWord === 'drive' ? 'drive (estimate)' : $timeWord);
        }

        return [
            'facts' => $facts,
            'url' => "https://www.google.com/maps/dir/?api=1&origin={$origin}&destination={$destination}".($travelMode ? "&travelmode={$travelMode}" : ''),
            'linkLabel' => $linkLabel,
        ];
    }

    private function coordinates(ItineraryItem $stop): ?string
    {
        $place = $this->place($stop);
        $lat = (float) $place?->latitude;
        $lng = (float) $place?->longitude;

        return $lat !== 0.0 && $lng !== 0.0 ? "{$lat},{$lng}" : null;
    }

    private function pesoRange(mixed $min, mixed $max): string
    {
        $peso = fn ($value) => '₱'.number_format((float) $value);

        return (float) $min > 0 && (float) $min !== (float) $max ? $peso($min).' – '.$peso($max) : $peso($max);
    }

    private function embedLogo(): ?string
    {
        $path = resource_path('images/sosogon-logo.png');

        return is_file($path) ? 'data:image/png;base64,'.base64_encode(file_get_contents($path)) : null;
    }

    /** The place's first photo as a small embedded JPEG, or null when it can't be read. */
    private function embedImage(?Model $place): ?string
    {
        $image = $place?->images?->first();
        $path = $image?->thumbnail_path ?: $image?->image_path;
        if (! $path || Str::startsWith($path, ['http://', 'https://'])) {
            return null;
        }

        return $this->embedded[$path] ??= $this->readImage($path);
    }

    private function readImage(string $path): ?string
    {
        try {
            $bytes = Storage::disk('public')->get($path);
            if (! $bytes) {
                return null;
            }

            return 'data:image/jpeg;base64,'.base64_encode((string) Image::read($bytes)->scaleDown(width: self::IMAGE_WIDTH)->toJpeg(70));
        } catch (Throwable) {
            return null;
        }
    }
}
