<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccommodationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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

            // Property amenities
            'amenities' => [
                'sometimes',
                'nullable',
                'array',
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
            'name.required' =>
                'Please enter the establishment name.',

            'description.required' =>
                'Please enter the property description.',

            'description.min' =>
                'The description must contain at least 50 characters.',

            'email.email' =>
                'Please enter a valid email address.',

            'price_range.in' =>
                'Please select Budget, Moderate, or Luxury.',

            'amenities.array' =>
                'Please select valid property amenities.',

            'amenities.*.distinct' =>
                'Duplicate amenities are not allowed.',

            'images.array' =>
                'Please upload your photos as a gallery.',

            'images.max' =>
                'You can upload a maximum of 100 photos per request.',

            'images.*.image' =>
                'Each uploaded file must be a valid image.',

            'images.*.mimes' =>
                'Photos must be JPG, JPEG, PNG, or WEBP.',

            'images.*.max' =>
                'Each photo must not exceed 5 MB.',
        ];
    }
}
