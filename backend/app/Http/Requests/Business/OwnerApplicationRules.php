<?php

namespace App\Http\Requests\Business;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Validation for a Business Owner's registration details and business permit,
 * shared by registration and by resubmission after a rejection. The permit
 * rule matches the one listing submissions used (PDF/DOC/DOCX, max 10 MB).
 */
class OwnerApplicationRules
{
    public const PERMIT_RULE = 'file|mimes:pdf,doc,docx|max:10240';

    public static function forRegistration(): array
    {
        $owner = 'required_if:role,business_owner|nullable';

        return [
            'business_name' => "{$owner}|string|max:255",
            'business_address' => "{$owner}|string|max:500",
            'municipality_id' => "{$owner}|integer|exists:municipalities,id",
            // The registration form asks for the barangay too.
            'barangay_id' => ['required_if:role,business_owner', 'nullable', 'integer', 'exists:barangays,id', self::barangayInMunicipality()],
            'business_permit_number' => "{$owner}|string|max:100",
            'business_permit_file' => "{$owner}|".self::PERMIT_RULE,
        ];
    }

    /** Resubmission: every detail can be corrected; a new permit file is optional. */
    public static function forResubmission(): array
    {
        return [
            'business_name' => 'required|string|max:255',
            'business_address' => 'required|string|max:500',
            'municipality_id' => 'required|integer|exists:municipalities,id',
            'barangay_id' => ['nullable', 'integer', 'exists:barangays,id', self::barangayInMunicipality()],
            'business_permit_number' => 'required|string|max:100',
            'business_permit_file' => 'nullable|'.self::PERMIT_RULE,
        ];
    }

    private static function barangayInMunicipality(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $municipalityId = request()->input('municipality_id');
            if ($value && $municipalityId && ! DB::table('barangays')->where('id', $value)->where('municipality_id', $municipalityId)->exists()) {
                $fail('The selected barangay is not in the selected municipality.');
            }
        };
    }
}
