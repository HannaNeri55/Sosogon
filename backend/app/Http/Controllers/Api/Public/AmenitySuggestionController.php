<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\AmenitySuggestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The amenity names offered as checkboxes on the owner forms (?scope=property or unit). */
class AmenitySuggestionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $scope = $request->query('scope');

        return response()->json(
            AmenitySuggestion::query()
                ->when(in_array($scope, ['property', 'unit'], true), fn ($q) => $q->whereIn('scope', [$scope, 'both']))
                ->orderBy('sort_order')
                ->orderBy('name')
                ->pluck('name')
        );
    }
}
