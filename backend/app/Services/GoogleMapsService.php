<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class GoogleMapsService
{
    private string $apiKey;

    public function __construct()
    {
        // An unset GOOGLE_MAPS_API_KEY is null; treat it like an empty key (haversine estimates).
        $this->apiKey = config('services.google_maps.api_key') ?? '';
    }

    /**
     * Get distance matrix between destinations.
     * Returns simplified array of from/to pairs with duration and distance, each marked with
     * its source: 'google' (a driving route) or 'estimate' (straight-line distance).
     * Falls back to haversine calculation if API key not configured.
     */
    public function getDistanceMatrix(Collection $destinations): array
    {
        $results = [];

        // If no API key, use haversine estimates
        if (empty($this->apiKey)) {
            return $this->estimateDistances($destinations);
        }

        // Limit to first 10 destinations for API efficiency
        $subset = $destinations->take(10);
        $origins = $subset->map(fn ($d) => "{$d->latitude},{$d->longitude}")->implode('|');
        $destCoords = $subset->map(fn ($d) => "{$d->latitude},{$d->longitude}")->implode('|');

        try {
            $response = Http::timeout(10)->get('https://maps.googleapis.com/maps/api/distancematrix/json', [
                'origins' => $origins,
                'destinations' => $destCoords,
                'mode' => 'driving',
                'key' => $this->apiKey,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $ids = $subset->pluck('id')->toArray();

                foreach ($data['rows'] as $i => $row) {
                    foreach ($row['elements'] as $j => $element) {
                        if ($i === $j) {
                            continue;
                        }
                        if ($element['status'] === 'OK') {
                            $results[] = [
                                'from' => $ids[$i],
                                'to' => $ids[$j],
                                'duration_min' => (int) ceil($element['duration']['value'] / 60),
                                'distance_km' => round($element['distance']['value'] / 1000, 1),
                                'source' => 'google',
                            ];
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // Fallback to estimates
            return $this->estimateDistances($destinations);
        }

        return $results ?: $this->estimateDistances($destinations);
    }

    /**
     * Fallback: Estimate distances using haversine formula.
     */
    private function estimateDistances(Collection $destinations): array
    {
        $results = [];
        $items = $destinations->take(10)->values();

        for ($i = 0; $i < $items->count(); $i++) {
            for ($j = $i + 1; $j < $items->count(); $j++) {
                $dist = $this->haversine(
                    $items[$i]->latitude,
                    $items[$i]->longitude,
                    $items[$j]->latitude,
                    $items[$j]->longitude
                );

                // Estimate driving time at 25km/h avg (rural roads)
                $timeMin = (int) ceil(($dist / 25) * 60);

                $results[] = [
                    'from' => $items[$i]->id,
                    'to' => $items[$j]->id,
                    'duration_min' => $timeMin,
                    'distance_km' => round($dist, 1),
                    // Straight-line distance, not a road route.
                    'source' => 'estimate',
                ];
            }
        }

        return $results;
    }

    private function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Geocode an address to lat/lng.
     */
    public function geocode(string $address): ?array
    {
        if (empty($this->apiKey)) {
            return null;
        }

        try {
            $response = Http::timeout(10)->get('https://maps.googleapis.com/maps/api/geocode/json', [
                'address' => $address.', Sorsogon, Philippines',
                'key' => $this->apiKey,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                if (! empty($data['results'])) {
                    $location = $data['results'][0]['geometry']['location'];

                    return ['latitude' => $location['lat'], 'longitude' => $location['lng']];
                }
            }
        } catch (\Exception $e) {
            return null;
        }

        return null;
    }
}
