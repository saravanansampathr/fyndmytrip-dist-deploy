<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Domains\Inventory\Models\BusInventoryItem;

class BusInventoryItemController extends BaseInventoryItemController
{
    protected function modelClass(): string
    {
        return BusInventoryItem::class;
    }

    protected function rules(bool $isUpdate): array
    {
        return $this->commonRules($isUpdate) + [
            'origin' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:100'],
            'destination' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:100'],
            'travel_date' => ['nullable', 'date'],
            'departure_time' => ['nullable', 'date_format:H:i'],
            'operator_name' => ['nullable', 'string', 'max:150'],
            'bus_type' => ['nullable', 'string', 'max:100'],
            'boarding_point' => ['nullable', 'string', 'max:150'],
            'dropping_point' => ['nullable', 'string', 'max:150'],
            'bus_brand' => ['nullable', 'string', 'max:100'],
        ];
    }

    protected function searchableColumns(): array
    {
        return ['title', 'origin', 'destination', 'operator_name'];
    }
}
