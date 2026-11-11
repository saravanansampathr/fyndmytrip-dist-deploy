<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Domains\Wallet\Models\Wallet
 */
class WalletResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'owner_type' => $this->owner_type,
            'owner_id' => $this->owner_id,
            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? [
                'id' => $this->owner->id,
                'name' => $this->owner->name,
                'email' => $this->owner->email ?? null,
            ] : null),
            'balance' => (string) $this->balance,
            'currency' => $this->currency,
            'status' => $this->status,
            // Credit fields — see Wallet::debitFloor()/creditUsed()/
            // creditAvailable()/availableToSpend() for what each derived
            // value means; 0 across the board for a wallet with no credit
            // granted, so this is additive and changes nothing for anyone
            // who hasn't been given credit.
            'credit_limit' => (string) $this->credit_limit,
            'credit_terms' => $this->credit_terms,
            'credit_next_due_at' => $this->credit_next_due_at?->toDateString(),
            'credit_status' => $this->credit_status,
            'credit_used' => $this->creditUsed(),
            'credit_available' => $this->creditAvailable(),
            'available_to_spend' => $this->availableToSpend(),
        ];
    }
}
