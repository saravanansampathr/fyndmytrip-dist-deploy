<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Domains\Inventory\Models\InsuranceInventoryItem;

class InsuranceInventoryItemController extends BaseInventoryItemController
{
    protected function modelClass(): string
    {
        return InsuranceInventoryItem::class;
    }

    protected function rules(bool $isUpdate): array
    {
        return $this->commonRules($isUpdate) + [
            'plan_name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:150'],
            'provider_name' => ['nullable', 'string', 'max:150'],
            'coverage_amount' => ['nullable', 'numeric', 'min:0'],
            'policy_term_days' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function searchableColumns(): array
    {
        return ['title', 'plan_name', 'provider_name'];
    }
}
