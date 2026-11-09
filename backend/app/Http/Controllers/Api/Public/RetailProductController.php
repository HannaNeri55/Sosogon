<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\RetailProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RetailProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = RetailProduct::approved()
            ->with(['municipality', 'barangay', 'images']);

        if ($request->filled('municipality_id')) {
            $query->where('municipality_id', $request->municipality_id);
        }

        if ($request->filled('barangay_id')) {
            $query->where('barangay_id', $request->barangay_id);
        }

        if ($request->filled('product_type')) {
            $query->where('product_type', $request->product_type);
        }

        if ($request->filled('q')) {
            $search = $request->q;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $sortBy = $request->input('sort', 'name');
        $sortDir = $request->input('dir', 'asc');
        $allowedSorts = ['name', 'created_at'];

        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortDir === 'desc' ? 'desc' : 'asc');
        } else {
            $query->orderBy('name');
        }

        $perPage = min($request->input('per_page', 12), 50);

        return response()->json($query->paginate($perPage));
    }

    public function show(int $id): JsonResponse
    {
        $product = RetailProduct::approved()
            ->with(['municipality', 'barangay', 'images', 'ratings' => fn ($query) => $query->visible()->with('user:id,name')])
            ->findOrFail($id);

        $product->increment('view_count');
        $product->setAttribute('average_rating', round((float) $product->ratings->avg('score'), 2));
        $product->setAttribute('total_reviews', $product->ratings->count());

        return response()->json($product);
    }
}
