<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Barangay;
use App\Models\Category;
use App\Models\Municipality;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    public function municipalities(): JsonResponse
    {
        return response()->json(Municipality::orderBy('name')->get());
    }

    public function barangays(int $id): JsonResponse
    {
        return response()->json(
            Barangay::where('municipality_id', $id)->orderBy('name')->get()
        );
    }

    public function categories(Request $request): JsonResponse
    {
        $query = Category::query();

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        return response()->json($query->orderBy('name')->get());
    }
}
