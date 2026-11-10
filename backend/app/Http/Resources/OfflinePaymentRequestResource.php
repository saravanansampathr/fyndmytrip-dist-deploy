<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Domains\Payments\Models\OfflinePaymentRequest
 */
class OfflinePaymentRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'wallet_id' => $this->wallet_id,
            'payment_mode' => $this->payment_mode,
            'amount' => (string) $this->amount,
            'transaction_date' => $this->transaction_date?->toDateString(),
            'transaction_ref_number' => $this->transaction_ref_number,
            'remarks' => $this->remarks,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'requested_by' => $this->whenLoaded('requestedBy', fn () => [
                'id' => $this->requestedBy->id,
                'name' => $this->requestedBy->name,
                'email' => $this->requestedBy->email,
            ]),
            'reviewed_by' => $this->whenLoaded('reviewedBy', fn () => $this->reviewedBy ? [
                'id' => $this->reviewedBy->id,
                'name' => $this->reviewedBy->name,
            ] : null),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($a) => [
                'id' => $a->id,
                'original_filename' => $a->original_filename,
                'mime_type' => $a->mime_type,
                'size_bytes' => $a->size_bytes,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
