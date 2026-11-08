<?php

namespace App\Http\Requests\Business;

use App\Http\Requests\Business\Concerns\ValidatesAccommodationOption;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAccommodationRoomRequest extends FormRequest
{
    use ValidatesAccommodationOption;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'room_number' => 'sometimes|string|max:255',
        ] + $this->optionRules();
    }
}
