<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Domains\Inventory\Models\FlightInventoryItem;

class FlightInventoryItemController extends BaseInventoryItemController
{
    protected function modelClass(): string
    {
        return FlightInventoryItem::class;
    }

    protected function rules(bool $isUpdate): array
    {
        return $this->commonRules($isUpdate) + [
            'origin' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:10'],
            'destination' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:10'],
            'travel_date' => ['nullable', 'date'],
            'departure_time' => ['nullable', 'date_format:H:i'],
            'airline' => ['nullable', 'string', 'max:150'],
            'flight_number' => ['nullable', 'string', 'max:20'],
            'cabin_class' => ['nullable', 'in:'.implode(',', FlightInventoryItem::CABIN_CLASSES)],
            'aircraft_type' => ['nullable', 'string', 'max:100'],
            'duration_minutes' => ['nullable', 'integer', 'min:0'],
            'baggage_allowance_kg' => ['nullable', 'integer', 'min:0'],
            'is_refundable' => ['nullable', 'boolean'],
            'meal_included' => ['nullable', 'boolean'],
        ];
    }

    protected function searchableColumns(): array
    {
        return ['title', 'origin', 'destination', 'airline', 'flight_number'];
    }
}
