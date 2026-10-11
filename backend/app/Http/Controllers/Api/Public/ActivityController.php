<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use Illuminate\Http\JsonResponse;

class ActivityController extends Controller
{
    public function show(int $id): JsonResponse
    {
        $activity = Activity::with('destination:id,name')->findOrFail($id);

        return response()->json($activity);
    }
}
