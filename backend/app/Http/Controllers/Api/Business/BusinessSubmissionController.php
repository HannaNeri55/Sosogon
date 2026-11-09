<?php

namespace App\Http\Controllers\Api\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\StoreBusinessSubmissionRequest;
use App\Http\Requests\Business\UpdateBusinessSubmissionRequest;
use App\Models\Accommodation;
use App\Models\BusinessOwnerApplication;
use App\Models\BusinessSubmission;
use App\Models\FoodBeverage;
use App\Models\RetailShop;
use App\Services\ImageOptimizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BusinessSubmissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $submissions = BusinessSubmission::where('user_id', $request->user()->id)
            ->with(['municipality', 'barangay'])
            ->orderByDesc('created_at')
            ->paginate(10);

        return response()->json($submissions);
    }

    public function store(StoreBusinessSubmissionRequest $request, ImageOptimizationService $images): JsonResponse
    {
        $validated = $request->validated();

        $user = $request->user();

        if ($user->business_type === null) {
            $user->update(['business_type' => $validated['type']]);
        } elseif ($validated['type'] !== $user->business_type) {
            return response()->json([
                'message' => "You are registered as a {$user->business_type} business and can only submit listings of that type.",
            ], 422);
        }

        // Cap total submissions per account to bound upload storage/abuse from a single user.
        $existingSubmissions = BusinessSubmission::where('user_id', $user->id)->count();
        if ($existingSubmissions >= 20) {
            return response()->json([
                'message' => 'You have reached the maximum number of business submissions for this account. Please contact the Tourism Office if you need to submit more.',
            ], 429);
        }

        // Business permits contain sensitive personal/government ID data — store them on
        // the private disk, never the public one. Access is only via downloadPermit()
        // below, which checks ownership/admin role before streaming the file.
        // Reuse the registration permit (same private file, nothing uploaded twice)
        // unless the owner explicitly attached one.
        $permitPath = $request->hasFile('business_permit')
            ? $request->file('business_permit')->store("business-permits/{$request->user()->id}", 'local')
            : $request->registrationPermitPath();

        // Store listing photo, if provided
        $imagePath = null;
        if ($request->hasFile('image')) {
            $paths = $images->store($request->file('image'), "business-submissions/{$request->user()->id}");
            $imagePath = $paths['image_path'];
        }

        $submission = BusinessSubmission::create([
            'user_id' => $request->user()->id,
            'business_name' => $validated['business_name'],
            'type' => $validated['type'],
            'description' => $validated['description'],
            'address' => $validated['address'],
            'municipality_id' => $validated['municipality_id'],
            'barangay_id' => $validated['barangay_id'] ?? null,
            'latitude' => $validated['latitude'],
            'longitude' => $validated['longitude'],
            'contact_number' => $validated['contact_number'],
            'operating_hours' => $validated['operating_hours'] ?? null,
            'business_permit_path' => $permitPath,
            'image_path' => $imagePath,
        ]);

        return response()->json(['message' => 'Submission created successfully.', 'submission' => $submission], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $submission = BusinessSubmission::where('user_id', $request->user()->id)
            ->with(['municipality', 'barangay'])
            ->findOrFail($id);

        return response()->json($submission);
    }

    public function downloadPermit(Request $request, int $id): StreamedResponse
    {
        $submission = BusinessSubmission::where('user_id', $request->user()->id)->findOrFail($id);

        abort_unless($submission->business_permit_path, 404);

        return Storage::disk('local')->response($submission->business_permit_path);
    }

    public function update(UpdateBusinessSubmissionRequest $request, int $id): JsonResponse
    {
        $submission = BusinessSubmission::where('user_id', $request->user()->id)->findOrFail($id);

        $validated = $request->validated();

        $needsReReview = $submission->status !== 'pending';

        $submission->update($validated);

        if ($needsReReview) {
            $submission->update([
                'status' => 'pending',
                'admin_notes' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ]);
        }

        return response()->json([
            'message' => $needsReReview
                ? 'Changes saved. Since this listing was already reviewed, an admin needs to approve the update before it goes live.'
                : 'Submission updated.',
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $submission = BusinessSubmission::where('user_id', $request->user()->id)->findOrFail($id);

        // Never delete a permit shared with the registration application or another submission.
        $permit = $submission->business_permit_path;
        $shared = $permit && (
            BusinessOwnerApplication::where('business_permit_path', $permit)->exists()
            || BusinessSubmission::where('business_permit_path', $permit)->whereKeyNot($submission->id)->exists()
        );
        if ($permit && ! $shared) {
            Storage::disk('local')->delete($permit);
        }
        if ($submission->image_path) {
            Storage::disk('public')->delete($submission->image_path);
        }

        if ($submission->listing_id) {
            $modelClass = match ($submission->type) {
                'accommodation' => Accommodation::class,
                'food_beverage' => FoodBeverage::class,
                'retail_product' => RetailShop::class,
            };
            $modelClass::where('id', $submission->listing_id)->delete();
        }

        $submission->delete();

        return response()->json(['message' => 'Submission deleted.']);
    }
}
