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

    /** The About page's cards: every town with its photo, in name order. */
    public function profiles(): JsonResponse
    {
        return response()->json(Municipality::orderBy('name')->get()->map(fn (Municipality $town) => [
            'id' => $town->id,
            'name' => $town->name,
            'lgu_type' => $town->lgu_type,
            'photo' => $town->representativePhoto(),
        ]));
    }

    /** One town's About-page profile: contact details, geography and history. */
    public function profile(int $id): JsonResponse
    {
        return response()->json(self::publicProfile(Municipality::findOrFail($id)));
    }

    public static function publicProfile(Municipality $town): array
    {
        return [
            'id' => $town->id,
            'name' => $town->name,
            'lgu_type' => $town->lgu_type,
            'lgu_label' => $town->lguLabel(),
            'photo' => $town->representativePhoto(),
            'contact_number' => $town->contact_number,
            'email' => $town->email,
            'address' => $town->address,
            'other_contacts' => $town->other_contacts,
            'geography' => $town->geography,
            'boundaries' => $town->boundaries,
            'name_origin' => $town->name_origin,
            'history' => $town->history,
        ];
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
