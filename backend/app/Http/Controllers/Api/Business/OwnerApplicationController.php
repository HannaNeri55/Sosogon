<?php

namespace App\Http\Controllers\Api\Business;

use App\Http\Controllers\Concerns\StreamsBusinessPermit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Business\OwnerApplicationRules;
use App\Models\BusinessOwnerApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The signed-in owner's own registration application. Always scoped to
 * $request->user(); there is no ID in these routes, so another owner's
 * application or permit can't be requested.
 */
class OwnerApplicationController extends Controller
{
    use StreamsBusinessPermit;

    public function show(Request $request): JsonResponse
    {
        $application = $request->user()->ownerApplication()->with(['municipality:id,name', 'barangay:id,name'])->first();

        return response()->json([
            'account_status' => $request->user()->status,
            // Null for owners who registered before applications existed.
            'application' => $application,
        ]);
    }

    public function permit(Request $request): StreamedResponse
    {
        $application = $request->user()->ownerApplication()->firstOrFail();

        return $this->streamPermit($application->business_permit_path, 'business-permit');
    }

    /** Only a rejected application can be corrected and resubmitted; it goes back to pending review. */
    public function resubmit(Request $request): JsonResponse
    {
        $application = $request->user()->ownerApplication()->firstOrFail();

        if ($application->status !== 'rejected') {
            return response()->json(['message' => 'Only a rejected application can be resubmitted.'], 409);
        }

        $validated = $request->validate(OwnerApplicationRules::forResubmission());
        $oldPermit = $application->business_permit_path;
        $newPermit = $request->hasFile('business_permit_file')
            ? $request->file('business_permit_file')->store('business-permits/registrations', 'local')
            : null;

        try {
            DB::transaction(function () use ($application, $validated, $newPermit) {
                $application->fill([
                    'business_name' => $validated['business_name'],
                    'business_address' => $validated['business_address'],
                    'municipality_id' => $validated['municipality_id'],
                    'barangay_id' => $validated['barangay_id'] ?? null,
                    'business_permit_number' => $validated['business_permit_number'],
                    'business_permit_path' => $newPermit ?? $application->business_permit_path,
                ]);
                // The previous rejection_reason is kept so the reviewer sees what was asked.
                $application->forceFill(['status' => 'pending', 'resubmitted_at' => now(), 'reviewed_by' => null, 'reviewed_at' => null])->save();
            });
        } catch (\Throwable $e) {
            if ($newPermit) {
                Storage::disk('local')->delete($newPermit);
            }

            throw $e;
        }

        if ($newPermit && $oldPermit && $oldPermit !== $newPermit && ! $this->permitInUse($oldPermit)) {
            Storage::disk('local')->delete($oldPermit);
        }

        return response()->json([
            'message' => 'Application resubmitted. The Tourism Office will review it again.',
            'application' => $application->fresh(['municipality:id,name', 'barangay:id,name']),
        ]);
    }

    private function permitInUse(string $path): bool
    {
        return BusinessOwnerApplication::where('business_permit_path', $path)->exists()
            || DB::table('business_submissions')->where('business_permit_path', $path)->exists();
    }
}
