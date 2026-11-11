<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Domains\Inventory\Models\ActivityInventoryItem;

class ActivityInventoryItemController extends BaseInventoryItemController
{
    protected function modelClass(): string
    {
        return ActivityInventoryItem::class;
    }

    protected function rules(bool $isUpdate): array
    {
        return $this->commonRules($isUpdate) + [
            'city' => ['nullable', 'string', 'max:100'],
            'activity_date' => ['nullable', 'date'],
            'provider_name' => ['nullable', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:100'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'meeting_point' => ['nullable', 'string', 'max:191'],
        ];
    }

    protected function searchableColumns(): array
    {
        return ['title', 'city', 'provider_name', 'category'];
    }
}
