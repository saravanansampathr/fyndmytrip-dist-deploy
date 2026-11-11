<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Domains\Wallet\Models\WalletCreditAuditLog
 */
class WalletCreditAuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'old_credit_limit' => (string) $this->old_credit_limit,
            'new_credit_limit' => (string) $this->new_credit_limit,
            'old_credit_terms' => $this->old_credit_terms,
            'new_credit_terms' => $this->new_credit_terms,
            'old_credit_next_due_at' => $this->old_credit_next_due_at?->toDateString(),
            'new_credit_next_due_at' => $this->new_credit_next_due_at?->toDateString(),
            'old_credit_status' => $this->old_credit_status,
            'new_credit_status' => $this->new_credit_status,
            'remarks' => $this->remarks,
            'changed_by' => $this->whenLoaded('changedBy', fn () => $this->changedBy ? [
                'id' => $this->changedBy->id,
                'name' => $this->changedBy->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
