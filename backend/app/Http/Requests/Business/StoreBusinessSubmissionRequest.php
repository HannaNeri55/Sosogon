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
            'contact_number' => 'required|string|max:20',
            'operating_hours' => 'nullable|string|max:255',
            'business_permit' => 'required|file|mimes:pdf,doc,docx|max:10240',
            'image' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:5120',
        ];
    }
}
