<?php

namespace App\Http\Controllers\Api\Tourist;

use App\Http\Controllers\Controller;
use App\Models\Itinerary;
use App\Services\ItineraryDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Saving, printing and sharing a tourist's own itinerary. Every action looks the itinerary up
 * through the signed-in tourist, so someone else's id is simply not found.
 */
class ItineraryDocumentController extends Controller
{
    public function __construct(private ItineraryDocumentService $documents) {}

    private function owned(Request $request, int $id): Itinerary
    {
        return $request->user()->itineraries()->findOrFail($id);
    }

    private function ownedWithDetails(Request $request, int $id): Itinerary
    {
        return $request->user()->itineraries()->with(ItineraryController::relations())->findOrFail($id);
    }

    /** Pressing Save again changes nothing: the itinerary is one record, saved once. */
    public function save(Request $request, int $id): JsonResponse
    {
        $itinerary = $this->owned($request, $id);

        Itinerary::whereKey($itinerary->id)->whereNull('saved_at')->update(['saved_at' => now()]);

        return response()->json(['saved_at' => $itinerary->refresh()->saved_at]);
    }

    /** The printable document as one self-contained page, also what is kept for offline use. */
    public function document(Request $request, int $id): JsonResponse
    {
        $itinerary = $this->ownedWithDetails($request, $id);

        return response()->json([
            'html' => $this->documents->html($itinerary),
            'dates' => $this->documents->datesLabel($itinerary),
        ]);
    }

    public function pdf(Request $request, int $id): Response
    {
        $itinerary = $this->ownedWithDetails($request, $id);

        return $this->documents->pdf($itinerary)->download($this->documents->filename($itinerary));
    }

    public function shareStatus(Request $request, int $id): JsonResponse
    {
        return response()->json(['share_token' => $this->owned($request, $id)->share_token]);
    }

    /** Turns the link on. Asking again returns the same link, so links already sent keep working. */
    public function share(Request $request, int $id): JsonResponse
    {
        $itinerary = $this->owned($request, $id);

        Itinerary::whereKey($itinerary->id)->whereNull('share_token')->update(['share_token' => Str::random(48)]);

        return response()->json(['share_token' => $itinerary->refresh()->share_token]);
    }

    /** Turns the link off: it stops working for everyone it was sent to. */
    public function unshare(Request $request, int $id): JsonResponse
    {
        $this->owned($request, $id)->forceFill(['share_token' => null])->save();

        return response()->json(['message' => 'This itinerary is private again.']);
    }
}
