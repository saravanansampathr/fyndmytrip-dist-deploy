<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Domains\Inventory\Models\HotelInventoryItem;

class HotelInventoryItemController extends BaseInventoryItemController
{
    protected function modelClass(): string
    {
        return HotelInventoryItem::class;
    }

    protected function rules(bool $isUpdate): array
    {
        return $this->commonRules($isUpdate) + [
            'city' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:100'],
            'hotel_name' => ['nullable', 'string', 'max:150'],
            'check_in_date' => ['nullable', 'date'],
            'check_out_date' => ['nullable', 'date', 'after_or_equal:check_in_date'],
            'room_type' => ['nullable', 'string', 'max:100'],
            'star_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'meal_plan' => ['nullable', 'string', 'max:100'],
        ];
    }

    protected function searchableColumns(): array
    {
        return ['title', 'hotel_name', 'city'];
    }
}
