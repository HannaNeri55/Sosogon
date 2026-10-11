<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAccommodationRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'room_number' => 'sometimes|string|max:255',
            'category' => 'sometimes|string|in:Standard,Deluxe,Suite,Family Room',
            'price' => 'nullable|numeric|min:0|max:999999.99',
            'description' => 'nullable|string|max:2000',
            'images' => 'nullable|array|max:5',
            'images.*' => 'image|mimes:jpeg,jpg,png,webp|max:5120',
        ];
    }
}
