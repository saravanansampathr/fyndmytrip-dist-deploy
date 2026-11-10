<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Domains\Booking\Models\HotelGroupBookingRequest
 */
class HotelGroupBookingRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'city' => $this->city,
            'check_in_date' => $this->check_in_date?->toDateString(),
            'check_out_date' => $this->check_out_date?->toDateString(),
            'rooms_requested' => $this->rooms_requested,
            'guest_count' => $this->guest_count,
            'room_type_preference' => $this->room_type_preference,
            'meal_plan_preference' => $this->meal_plan_preference,
            'contact_name' => $this->contact_name,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'remarks' => $this->remarks,
            'status' => $this->status,

            'quoted_rate_per_room' => $this->quoted_rate_per_room !== null ? (string) $this->quoted_rate_per_room : null,
            'quoted_total_fare' => $this->quoted_total_fare !== null ? (string) $this->quoted_total_fare : null,
            'deposit_amount' => $this->deposit_amount !== null ? (string) $this->deposit_amount : null,
            'balance_due' => in_array($this->status, ['deposit_paid', 'rooming_list_submitted', 'finalized'], true) ? $this->balanceDue() : null,
            'quote_remarks' => $this->quote_remarks,
            'quoted_by' => $this->whenLoaded('quotedBy', fn () => $this->quotedBy ? ['id' => $this->quotedBy->id, 'name' => $this->quotedBy->name] : null),
            'quoted_at' => $this->quoted_at?->toIso8601String(),
            'rate_hold_expires_at' => $this->rate_hold_expires_at?->toDateString(),
            'rooming_list_deadline' => $this->rooming_list_deadline?->toDateString(),

            'deposit_paid_at' => $this->deposit_paid_at?->toIso8601String(),

            'finalized_by' => $this->whenLoaded('finalizedBy', fn () => $this->finalizedBy ? ['id' => $this->finalizedBy->id, 'name' => $this->finalizedBy->name] : null),
            'finalized_at' => $this->finalized_at?->toIso8601String(),
            'final_booking_reference' => $this->final_booking_reference,

            'rejection_reason' => $this->rejection_reason,

            'requested_by' => $this->whenLoaded('requestedBy', fn () => [
                'id' => $this->requestedBy->id,
                'name' => $this->requestedBy->name,
                'email' => $this->requestedBy->email,
            ]),
            'guests' => HotelGroupBookingGuestResource::collection($this->whenLoaded('guests')),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
