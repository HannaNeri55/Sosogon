<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ListingDataChecks;
use App\Support\AdminScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Data Checks: problems in public listings that make trips worse or mislead tourists
 * (ListingDataChecks). A municipal admin sees their municipality's listings; provincial admins
 * see all, and each destination problem says whether this admin can fix it or which municipal
 * office manages it. Business listings are fixed by their owners. Read-only.
 */
class AdminDataChecksController extends Controller
{
    public function index(Request $request, ListingDataChecks $checks): JsonResponse
    {
        $admin = $request->user();
        $issues = $checks->run(AdminScope::municipalityIds($admin))->map(function (array $issue) use ($admin) {
            $canFix = $issue['fixed_by'] === 'admin' && $admin->managesMunicipality($issue['municipality_id']);

            return $issue + [
                'can_fix' => $canFix,
                'managed_by' => $issue['fixed_by'] === 'admin' && ! $canFix && $issue['municipality_id'] && AdminScope::hasOwnOffice($issue['municipality_id'])
                    ? AdminScope::officeName($issue['municipality_id'])
                    : null,
            ];
        });

        return response()->json([
            'issues' => $issues->values(),
            'types' => ListingDataChecks::TYPES,
            'severities' => ListingDataChecks::SEVERITIES,
        ]);
    }
}
