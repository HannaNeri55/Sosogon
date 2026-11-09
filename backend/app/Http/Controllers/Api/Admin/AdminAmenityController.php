<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\AccommodationRoom;
use App\Models\AmenitySuggestion;
use App\Support\AmenityList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Amenities owners typed themselves, for the Tourism Office to review. Promoting a name only adds
 * it to the suggestions on the owner forms: no listing's amenities change.
 */
class AdminAmenityController extends Controller
{
    public function custom(): JsonResponse
    {
        $known = AmenitySuggestion::pluck('name')->mapWithKeys(fn ($name) => [mb_strtolower($name) => true]);
        $entries = [];
        $count = function (?array $names, string $listing, string $scope) use ($known, &$entries) {
            foreach ($names ?? [] as $name) {
                $key = mb_strtolower((string) $name);
                if ($key === '' || isset($known[$key])) {
                    continue;
                }
                $entries[$key] ??= ['name' => $name, 'property_uses' => 0, 'unit_uses' => 0, 'listings' => []];
                $entries[$key][$scope === 'property' ? 'property_uses' : 'unit_uses']++;
                $entries[$key]['listings'][$listing] = true;
            }
        };

        foreach (Accommodation::get(['id', 'name', 'amenities']) as $stay) {
            $count($stay->amenities, $stay->name, 'property');
        }
        foreach (AccommodationRoom::with('accommodation:id,name')->get(['id', 'accommodation_id', 'room_features']) as $room) {
            $count($room->room_features, $room->accommodation?->name ?? 'Unknown', 'unit');
        }

        return response()->json(collect($entries)
            ->map(fn ($e) => ['listings' => array_keys($e['listings'])] + $e)
            ->sortByDesc(fn ($e) => $e['property_uses'] + $e['unit_uses'])
            ->values());
    }

    public function promote(Request $request): JsonResponse
    {
        $request->merge(['name' => AmenityList::clean([(string) $request->input('name')])[0] ?? '']);
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'scope' => ['required', Rule::in(AmenitySuggestion::SCOPES)],
        ]);

        if (AmenitySuggestion::whereRaw('lower(name) = ?', [mb_strtolower($validated['name'])])->exists()) {
            return response()->json(['message' => 'That amenity is already a suggestion.'], 422);
        }

        $suggestion = AmenitySuggestion::create($validated + ['sort_order' => (AmenitySuggestion::max('sort_order') ?? 0) + 1]);

        return response()->json(['message' => 'Added to the suggestions.', 'suggestion' => $suggestion], 201);
    }
}
