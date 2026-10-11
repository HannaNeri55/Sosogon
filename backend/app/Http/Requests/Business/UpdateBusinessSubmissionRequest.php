<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBusinessSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'business_name' => 'sometimes|string|max:255',
            'description' => 'sometimes|string|min:50',
            'address' => 'sometimes|string|max:500',
            'contact_number' => 'sometimes|string|max:20',
            'operating_hours' => 'nullable|string|max:255',
        ];
    }
}
