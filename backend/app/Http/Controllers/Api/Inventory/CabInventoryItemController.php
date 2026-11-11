<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Domains\Inventory\Models\CabInventoryItem;

class CabInventoryItemController extends BaseInventoryItemController
{
    protected function modelClass(): string
    {
        return CabInventoryItem::class;
    }

    protected function rules(bool $isUpdate): array
    {
        return $this->commonRules($isUpdate) + [
            'origin' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:100'],
            'destination' => ['nullable', 'string', 'max:100'],
            'travel_date' => ['nullable', 'date'],
            'provider_name' => ['nullable', 'string', 'max:150'],
            'car_type' => ['nullable', 'string', 'max:100'],
            'included_km' => ['nullable', 'integer', 'min:0'],
            'extra_km_fare' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    protected function searchableColumns(): array
    {
        return ['title', 'origin', 'destination', 'provider_name'];
    }
}
