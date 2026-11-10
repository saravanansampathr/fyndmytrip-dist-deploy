<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Domains\Support\Models\SupportTicket
 */
class SupportTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'tenant_name' => $this->whenLoaded('tenant', fn () => $this->tenant?->name),
            'booking_id' => $this->booking_id,
            'booking_code' => $this->whenLoaded('booking', fn () => $this->booking?->booking_code ?? $this->booking?->booking_reference),
            'booking_module' => $this->whenLoaded('booking', fn () => $this->booking?->module),
            'booking_status' => $this->whenLoaded('booking', fn () => $this->booking?->status),
            'category' => $this->category,
            'subject' => $this->subject,
            'description' => $this->description,
            'status' => $this->status,
            'priority' => $this->priority,
            'raised_by_user_id' => $this->raised_by_user_id,
            'raised_by_name' => $this->whenLoaded('raisedBy', fn () => $this->raisedBy?->name),
            'assigned_to_user_id' => $this->assigned_to_user_id,
            'assigned_to_name' => $this->whenLoaded('assignedTo', fn () => $this->assignedTo?->name),
            'resolved_at' => $this->resolved_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'replies' => $this->whenLoaded('replies', fn () => $this->replies->map(fn ($r) => [
                'id' => $r->id,
                'user_id' => $r->user_id,
                'user_name' => $r->relationLoaded('user') ? $r->user?->name : null,
                'message' => $r->message,
                'created_at' => $r->created_at,
            ])),
        ];
    }
}
