<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $announcements = Announcement::published()
            ->orderByDesc('is_pinned')
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request, 10, 50));

        return response()->json($announcements);
    }
}
