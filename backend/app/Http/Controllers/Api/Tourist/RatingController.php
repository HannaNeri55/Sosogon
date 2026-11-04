<?php

namespace App\Http\Controllers\Api\Tourist;

use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\Rating;
use App\Models\RetailProduct;
use App\Models\RetailShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class RatingController extends Controller
{
    private array $typeMap = [
        'destination' => Destination::class,
        'accommodation' => Accommodation::class,
        'food_beverage' => FoodBeverage::class,
        'retail_product' => RetailProduct::class,
        'retail_shop' => RetailShop::class,
    ];

    private function refreshAggregates(Model $model): void
    {
        if (method_exists($model, 'recalculateRating')) {
            $model->recalculateRating();

            return;
        }

        // Retail shops and products have no stored aggregate columns; they are computed on read.
        if (! Schema::hasColumn($model->getTable(), 'average_rating')) {
            return;
        }

        $model->average_rating = $model->ratings()->visible()->avg('score') ?? 0;
        $model->total_reviews = $model->ratings()->visible()->count();
        $model->save();
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'rateable_type' => 'required|in:destination,accommodation,food_beverage,retail_product,retail_shop',
            'rateable_id' => 'required|integer',
            'score' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        $modelClass = $this->typeMap[$validated['rateable_type']];
        $rateable = $modelClass::findOrFail($validated['rateable_id']);
        // Hidden sample listings can't be reviewed through the API either.
        abort_if($rateable->is_sample && ! config('listings.show_samples'), 404);

        $existing = Rating::where('user_id', $request->user()->id)
            ->where('rateable_type', $modelClass)
            ->where('rateable_id', $validated['rateable_id'])
            ->first();

        if ($existing) {
            return response()->json(['message' => 'You have already rated this item.'], 422);
        }

        $rating = Rating::create([
            'user_id' => $request->user()->id,
            'rateable_type' => $modelClass,
            'rateable_id' => $validated['rateable_id'],
            'score' => $validated['score'],
            'comment' => $validated['comment'] ?? null,
        ]);

        // Recalculate average
        $this->refreshAggregates($modelClass::find($validated['rateable_id']));

        return response()->json(['message' => 'Rating submitted.', 'rating' => $rating->load('user')], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $rating = Rating::where('user_id', $request->user()->id)->findOrFail($id);

        $validated = $request->validate([
            'score' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        $rating->update($validated);

        // Recalculate
        $model = $rating->rateable;
        if ($model) {
            $this->refreshAggregates($model);
        }

        return response()->json(['message' => 'Rating updated.', 'rating' => $rating]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $rating = Rating::where('user_id', $request->user()->id)->findOrFail($id);
        $model = $rating->rateable;
        $rating->delete();

        if ($model) {
            $this->refreshAggregates($model);
        }

        return response()->json(['message' => 'Rating deleted.']);
    }

    public function myReviews(Request $request): JsonResponse
    {
        $reviews = Rating::where('user_id', $request->user()->id)
            ->with('rateable:id,name')
            ->orderByDesc('created_at')
            ->paginate(10);

        return response()->json($reviews);
    }
}
