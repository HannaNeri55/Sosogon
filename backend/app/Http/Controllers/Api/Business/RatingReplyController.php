<?php

namespace App\Http\Controllers\Api\Business;

use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\FoodBeverage;
use App\Models\Rating;
use App\Models\RatingReply;
use App\Models\RetailShop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lets a Business Owner respond to reviews on listings they own. The reply is
 * stored in rating_replies; the Tourist's rating row is only ever read here.
 */
class RatingReplyController extends Controller
{
    /** Listing types a Business Owner can own (each has a user_id owner column). */
    private const OWNED_TYPES = [Accommodation::class, FoodBeverage::class, RetailShop::class];

    /**
     * Reviews on the signed-in owner's listings, with a summary of all of them (average, how
     * many of each star rating, how many were answered, how many came in the last 30 days).
     * The list can be narrowed by ?rating=1–5, ?filter=replied|unreplied and ?search= (the
     * reviewer or the review text), and ordered by ?sort=newest|oldest|highest|lowest.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'filter' => 'nullable|in:all,unreplied,replied',
            'rating' => 'nullable|integer|between:1,5',
            'sort' => 'nullable|in:newest,oldest,highest,lowest',
            'search' => 'nullable|string|max:100',
        ]);

        $owned = Rating::whereHasMorph(
            'rateable',
            self::OWNED_TYPES,
            fn ($query) => $query->where('user_id', $request->user()->id)
        );

        $total = (clone $owned)->count();
        $replied = (clone $owned)->has('reply')->count();
        $byRating = (clone $owned)->selectRaw('score, count(*) as n')->groupBy('score')->pluck('n', 'score');

        $reviews = (clone $owned)
            ->when($request->filter === 'unreplied', fn ($query) => $query->doesntHave('reply'))
            ->when($request->filter === 'replied', fn ($query) => $query->has('reply'))
            ->when($request->filled('rating'), fn ($query) => $query->where('score', (int) $request->rating))
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->search.'%';
                $query->where(fn ($q) => $q->where('comment', 'like', $term)->orWhereHas('user', fn ($u) => $u->where('name', 'like', $term)));
            })
            ->with(['user:id,name', 'reply', 'rateable:id,name', 'latestReport']);
        match ($request->input('sort', 'newest')) {
            'oldest' => $reviews->oldest()->orderBy('id'),
            'highest' => $reviews->orderByDesc('score')->latest(),
            'lowest' => $reviews->orderBy('score')->latest(),
            default => $reviews->latest()->orderByDesc('id'),
        };

        return response()->json($reviews->paginate(10)->toArray() + [
            'summary' => [
                'total' => $total,
                'replied' => $replied,
                'unreplied' => $total - $replied,
                'response_rate' => $total ? (int) round($replied / $total * 100) : 0,
                'average' => round((float) (clone $owned)->avg('score'), 1),
                'by_rating' => collect(range(1, 5))->mapWithKeys(fn ($star) => [$star => (int) ($byRating[$star] ?? 0)]),
                'last_30_days' => (clone $owned)->where('created_at', '>=', now()->subDays(30))->count(),
            ],
        ]);
    }

    public function store(Request $request, int $ratingId): JsonResponse
    {
        $rating = $this->ownedRating($request, $ratingId);
        $validated = $request->validate(['body' => 'required|string|max:1000']);

        if ($rating->reply()->exists()) {
            return response()->json(['message' => 'You have already replied to this review.'], 422);
        }

        $reply = RatingReply::create([
            'rating_id' => $rating->id,
            'user_id' => $request->user()->id,
            'body' => $validated['body'],
        ]);

        return response()->json(['message' => 'Reply posted.', 'reply' => $reply], 201);
    }

    public function update(Request $request, int $ratingId): JsonResponse
    {
        $rating = $this->ownedRating($request, $ratingId);
        $validated = $request->validate(['body' => 'required|string|max:1000']);

        $reply = $rating->reply()->where('user_id', $request->user()->id)->firstOrFail();
        $reply->update(['body' => $validated['body']]);

        return response()->json(['message' => 'Reply updated.', 'reply' => $reply]);
    }

    /** The rating, provided its listing belongs to the signed-in owner; 403 otherwise. */
    private function ownedRating(Request $request, int $ratingId): Rating
    {
        $rating = Rating::with('rateable')->findOrFail($ratingId);

        if (! $rating->rateable || (int) $rating->rateable->user_id !== (int) $request->user()->id) {
            abort(403, 'You can only reply to reviews of your own listing.');
        }

        return $rating;
    }
}
