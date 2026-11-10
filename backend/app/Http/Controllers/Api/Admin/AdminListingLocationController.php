<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\ActivityLog;
use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\RetailShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin view and correction of the exact map location (address, latitude, longitude)
 * of destinations, accommodations, food & beverage places and shops. Owners can't
 * change coordinates after submitting, so a correction made here stays.
 */
class AdminListingLocationController extends Controller
{
    /** Listing type in the URL => model. */
    private const TYPES = [
        'destination' => Destination::class,
        'accommodation' => Accommodation::class,
        'food_beverage' => FoodBeverage::class,
        'retail_shop' => RetailShop::class,
    ];

    public function index(Request $request, string $type): JsonResponse
    {
        $model = $this->model($type);
        $query = $model::query()
            ->with('municipality:id,name')
            ->select(['id', 'name', 'municipality_id', 'address', 'latitude', 'longitude', 'status', 'updated_at']);

        if ($request->filled('q')) {
            $query->where('name', 'like', '%'.$request->q.'%');
        }

        $perPage = $this->perPage($request, 50, 200);

        return response()->json($query->orderBy('name')->paginate($perPage));
    }

    public function update(Request $request, string $type, int $id): JsonResponse
    {
        $listing = $this->model($type)::findOrFail($id);
        $validated = $request->validate([
            'address' => 'nullable|string|max:500',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        $before = ['latitude' => $listing->latitude, 'longitude' => $listing->longitude, 'address' => $listing->address];
        $listing->forceFill([
            'latitude' => round((float) $validated['latitude'], 7),
            'longitude' => round((float) $validated['longitude'], 7),
            'address' => array_key_exists('address', $validated) && $validated['address'] !== null ? $validated['address'] : $listing->address,
        ])->save();

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'action' => 'updated_listing_location',
            'description' => sprintf(
                'Set the map location of %s "%s" from %s, %s to %s, %s',
                str_replace('_', ' ', $type), $listing->name,
                $before['latitude'] ?? '—', $before['longitude'] ?? '—', $listing->latitude, $listing->longitude
            ),
            'model_type' => $listing::class,
            'model_id' => $listing->id,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => 'Location saved.',
            'listing' => $listing->only(['id', 'name', 'address', 'latitude', 'longitude']),
        ]);
    }

    /** @return class-string<Model> */
    private function model(string $type): string
    {
        abort_unless(array_key_exists($type, self::TYPES), 404);

        return self::TYPES[$type];
    }
}
