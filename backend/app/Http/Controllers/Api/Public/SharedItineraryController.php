<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Api\Tourist\ItineraryController;
use App\Http\Controllers\Controller;
use App\Models\Itinerary;
use App\Services\ItineraryDocumentService;
use App\Services\ItineraryService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * What someone sees through a share link: the plan, read-only. Who owns it and what they
 * privately asked the planner for stay out.
 */
class SharedItineraryController extends Controller
{
    private const PRIVATE_FIELDS = ['user_id', 'additional_preferences', 'ai_response_raw', 'saved_at'];

    public function __construct(private ItineraryDocumentService $documents, private ItineraryService $itineraries) {}

    private function shared(string $token): Itinerary
    {
        return Itinerary::where('share_token', $token)->with(ItineraryController::relations())->firstOrFail();
    }

    public function show(string $token): JsonResponse
    {
        $itinerary = $this->shared($token)->makeHidden(self::PRIVATE_FIELDS);

        return response()->json($itinerary->setAttribute('backup_options', (object) $this->itineraries->backupOptions($itinerary)));
    }

    public function pdf(string $token): Response
    {
        $itinerary = $this->shared($token);

        return $this->documents->pdf($itinerary, shared: true)->download($this->documents->filename($itinerary));
    }
}
