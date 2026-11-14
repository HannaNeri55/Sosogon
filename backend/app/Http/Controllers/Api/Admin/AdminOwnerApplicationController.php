<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\StreamsBusinessPermit;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\BusinessOwnerApplication;
use App\Support\AdminScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin review of Business Owner registrations and their business permits. A municipal admin
 * sees and decides only the registrations of businesses in their municipality (AdminScope).
 */
class AdminOwnerApplicationController extends Controller
{
    use StreamsBusinessPermit;

    public function index(Request $request): JsonResponse
    {
        $query = BusinessOwnerApplication::with(['user:id,name,email,phone,status,created_at', 'municipality:id,name', 'barangay:id,name', 'reviewer:id,name']);
        AdminScope::apply($query, $request->user());

        if (in_array($request->input('status'), ['pending', 'approved', 'rejected'], true)) {
            $query->where('status', $request->input('status'));
        }

        return response()->json($query->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'rejected' THEN 1 ELSE 2 END")->latest('updated_at')->get());
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $application = BusinessOwnerApplication::with(['user:id,name,email,phone,status,created_at', 'municipality:id,name', 'barangay:id,name', 'reviewer:id,name'])->findOrFail($id);
        AdminScope::authorizeRecord($request->user(), $application);

        return response()->json($application);
    }

    public function permit(Request $request, int $id): StreamedResponse
    {
        $application = BusinessOwnerApplication::findOrFail($id);
        AdminScope::authorizeRecord($request->user(), $application);

        return $this->streamPermit($application->business_permit_path, "business-permit-application-{$application->id}");
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $application = DB::transaction(function () use ($request, $id) {
            $application = BusinessOwnerApplication::whereKey($id)->lockForUpdate()->firstOrFail();
            AdminScope::authorizeRecord($request->user(), $application);
            abort_if($application->status !== 'pending', 409, 'Only a pending application can be approved.');

            $application->forceFill(['status' => 'approved', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()])->save();
            $application->user->forceFill(['status' => 'active'])->save();
            $this->log($request, 'approved_owner_application', "Approved Business Owner registration: {$application->business_name}", $application);

            return $application;
        });

        return response()->json(['message' => 'Registration approved. The owner can now sign in and submit their listing.', 'application' => $application->fresh(['user:id,name,email,status'])]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['reason' => 'required|string|min:10|max:1000']);

        $application = DB::transaction(function () use ($request, $id, $validated) {
            $application = BusinessOwnerApplication::whereKey($id)->lockForUpdate()->firstOrFail();
            AdminScope::authorizeRecord($request->user(), $application);
            abort_if($application->status !== 'pending', 409, 'Only a pending application can be rejected.');

            // The account stays pending (it was never activated); the owner can sign in to correct and resubmit.
            $application->forceFill([
                'status' => 'rejected',
                'rejection_reason' => trim($validated['reason']),
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ])->save();
            $this->log($request, 'rejected_owner_application', "Rejected Business Owner registration: {$application->business_name}", $application);

            return $application;
        });

        return response()->json(['message' => 'Registration rejected. The owner can see the reason and resubmit.', 'application' => $application]);
    }

    private function log(Request $request, string $action, string $description, BusinessOwnerApplication $application): void
    {
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'description' => $description,
            'model_type' => BusinessOwnerApplication::class,
            'model_id' => $application->id,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }
}
