<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFoodBeverageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|string|min:50',
            'website' => 'nullable|string|max:500',
            'images' => 'nullable|array|max:100',
            'images.*' => 'image|mimes:jpeg,jpg,png,webp|max:5120',
        ];
    }

    public function messages(): array
    {
        return [
            'images.array' => 'Please upload your photos as a gallery.',
            'images.max' => 'You can upload a maximum of 100 photos per request.',
            'images.*.image' => 'Each uploaded file must be a valid image.',
            'images.*.mimes' => 'Photos must be JPG, JPEG, PNG, or WEBP.',
            'images.*.max' => 'Each photo must not exceed 5 MB.',
        ];
    }
}
