<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Domains\Inventory\Models\CruiseInventoryItem;

class CruiseInventoryItemController extends BaseInventoryItemController
{
    protected function modelClass(): string
    {
        return CruiseInventoryItem::class;
    }

    protected function rules(bool $isUpdate): array
    {
        return $this->commonRules($isUpdate) + [
            'cruise_line' => ['nullable', 'string', 'max:150'],
            'ship_name' => ['nullable', 'string', 'max:150'],
            'departure_port' => ['nullable', 'string', 'max:100'],
            'arrival_port' => ['nullable', 'string', 'max:100'],
            'departure_date' => ['nullable', 'date'],
            'nights' => ['nullable', 'integer', 'min:1'],
            'cabin_type' => ['nullable', 'string', 'max:100'],
        ];
    }

    protected function searchableColumns(): array
    {
        return ['title', 'cruise_line', 'ship_name', 'departure_port', 'arrival_port'];
    }
}
