<?php

namespace App\Http\Requests\Business;

use App\Http\Requests\Business\Concerns\ValidatesAccommodationOption;
use Illuminate\Foundation\Http\FormRequest;

class StoreAccommodationRoomRequest extends FormRequest
{
    use ValidatesAccommodationOption;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required_without:room_number|nullable|string|max:255',
            'room_number' => 'required_without:name|nullable|string|max:255',
        ] + $this->optionRules();
    }
}
