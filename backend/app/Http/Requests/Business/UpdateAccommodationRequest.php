<?php

namespace App\Http\Requests\Business;

use App\Models\Accommodation;
use App\Support\AmenityList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccommodationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->exists('amenities')) {
            $this->merge(['amenities' => AmenityList::clean($this->input('amenities'))]);
        }
    }

    public function rules(): array
    {
        return [
            // Basic accommodation information
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],

            'description' => [
                'sometimes',
                'required',
                'string',
                'min:50',
            ],

            // Contact information
            'contact_number' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
            ],

            'email' => [
                'sometimes',
                'nullable',
                'email',
                'max:255',
            ],

            'website' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
            ],

            // Property information
            'operating_hours' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],

            'price_range' => [
                'sometimes',
                'nullable',
                Rule::in([
                    'budget',
                    'moderate',
                    'luxury',
                ]),
            ],

            'check_in_time' => ['sometimes', 'nullable', 'string', 'max:50'],
            'check_out_time' => ['sometimes', 'nullable', 'string', 'max:50'],

            // What the property offers, and what kind of property it is
            'offering_arrangement' => ['sometimes', 'nullable', Rule::in(Accommodation::ARRANGEMENTS)],
            'classifications' => ['sometimes', 'nullable', 'array', 'max:8'],
            'classifications.*' => ['string', Rule::in(Accommodation::CLASSIFICATIONS)],

            // Property amenities: suggestions or the owner's own wording
            'amenities' => [
                'sometimes',
                'nullable',
                'array',
                'max:'.AmenityList::MAX_PROPERTY,
            ],

            'amenities.*' => [
                'string',
                'max:100',
                'distinct',
            ],

            // Accommodation gallery
            'images' => [
                'nullable',
                'array',
                'max:100',
            ],

            'images.*' => [
                'image',
                'mimes:jpeg,jpg,png,webp',
                'max:5120',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Please enter the establishment name.',

            'description.required' => 'Please enter the property description.',

            'description.min' => 'The description must contain at least 50 characters.',

            'email.email' => 'Please enter a valid email address.',

            'price_range.in' => 'Please select Budget, Moderate, or Luxury.',

            'amenities.array' => 'Please select valid property amenities.',

            'amenities.*.distinct' => 'Duplicate amenities are not allowed.',

            'images.array' => 'Please upload your photos as a gallery.',

            'images.max' => 'You can upload a maximum of 100 photos per request.',

            'images.*.image' => 'Each uploaded file must be a valid image.',

            'images.*.mimes' => 'Photos must be JPG, JPEG, PNG, or WEBP.',

            'images.*.max' => 'Each photo must not exceed 5 MB.',
        ];
    }
}
