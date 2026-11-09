<?php

namespace App\Http\Requests\Business\Concerns;

use App\Models\AccommodationRoom;
use App\Support\AmenityList;
use Illuminate\Validation\Rule;

/**
 * The fields of one accommodation option. Only a name is needed: everything else applies only to
 * some properties. A price of 0 means no price was given, never that the option is free.
 */
trait ValidatesAccommodationOption
{
    protected function prepareForValidation(): void
    {
        $merge = [];
        if ($this->exists('room_features')) {
            // A form sends an empty value to clear the list.
            $merge['room_features'] = AmenityList::clean($this->input('room_features') ?: []);
        }
        foreach (['price', 'price_max'] as $field) {
            if ($this->exists($field) && is_numeric($this->input($field)) && (float) $this->input($field) <= 0) {
                $merge[$field] = null;
            }
        }
        if ($this->input('pricing_mode') === 'contact') {
            $merge['price'] = null;
            $merge['price_max'] = null;
        }
        $this->merge($merge);
    }

    protected function optionRules(): array
    {
        return [
            'unit_type' => ['nullable', 'string', Rule::in(AccommodationRoom::UNIT_TYPES)],
            'category' => 'nullable|string|max:50',
            'unit_count' => 'nullable|integer|min:1|max:999',
            'max_guests' => 'nullable|integer|min:1|max:200',
            'bedrooms' => 'nullable|integer|min:0|max:100',
            'bed_configuration' => 'nullable|string|max:255',
            'size_sqm' => 'nullable|numeric|min:1|max:99999',
            'pricing_mode' => ['nullable', 'string', Rule::in(AccommodationRoom::PRICING_MODES)],
            'price' => 'nullable|numeric|min:0|max:999999.99',
            'price_max' => 'nullable|numeric|max:999999.99|gte:price',
            'price_note' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
            'notes' => 'nullable|string|max:2000',
            'breakfast_included' => 'nullable|boolean',

            'room_features' => 'sometimes|array|max:'.AmenityList::MAX_UNIT,
            'room_features.*' => 'required|string|max:100',

            'images' => 'nullable|array|max:10',
            'images.*' => 'image|mimes:jpeg,jpg,png,webp|max:5120',
        ];
    }

    public function attributes(): array
    {
        return ['room_features' => 'amenities', 'room_features.*' => 'amenity'];
    }
}
