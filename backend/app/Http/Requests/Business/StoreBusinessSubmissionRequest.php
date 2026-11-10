<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

class StoreBusinessSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route-level `role:business_owner` middleware already gates access;
        // this just makes the allow-list a structural property of the
        // request class rather than a per-controller convention.
        return true;
    }

    public function rules(): array
    {
        return [
            'business_name' => 'required|string|max:255',
            'type' => 'required|in:accommodation,food_beverage,retail_product',
            'description' => 'required|string|min:50',
            'address' => 'required|string|max:500',
            'municipality_id' => 'required|exists:municipalities,id',
            'barangay_id' => 'nullable|exists:barangays,id',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'contact_number' => 'required|string|max:50',
            'operating_hours' => 'nullable|string|max:255',
            // The permit is collected once, at registration. Only owners who registered
            // before that (no registration permit on file) still upload it here.
            'business_permit' => ($this->registrationPermitPath() ? 'nullable|' : 'required|').OwnerApplicationRules::PERMIT_RULE,
            'image' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:5120',
        ];
    }

    /** The permit from the owner's approved registration, if any. */
    public function registrationPermitPath(): ?string
    {
        $application = $this->user()?->ownerApplication;

        return $application?->status === 'approved' ? $application->business_permit_path : null;
    }
}
