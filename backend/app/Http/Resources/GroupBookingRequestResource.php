<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Domains\Booking\Models\GroupBookingRequest
 */
class GroupBookingRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'trip_type' => $this->trip_type,
            'origin' => $this->origin,
            'destination' => $this->destination,
            'depart_date' => $this->depart_date?->toDateString(),
            'return_date' => $this->return_date?->toDateString(),
            'passenger_count' => $this->passenger_count,
            'cabin_class' => $this->cabin_class,
            'flexible_dates' => $this->flexible_dates,
            'contact_name' => $this->contact_name,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'remarks' => $this->remarks,
            'status' => $this->status,

            'quoted_fare_per_pax' => $this->quoted_fare_per_pax !== null ? (string) $this->quoted_fare_per_pax : null,
            'quoted_total_fare' => $this->quoted_total_fare !== null ? (string) $this->quoted_total_fare : null,
            'deposit_amount' => $this->deposit_amount !== null ? (string) $this->deposit_amount : null,
            'balance_due' => in_array($this->status, ['deposit_paid', 'name_list_submitted', 'finalized'], true) ? $this->balanceDue() : null,
            'quote_remarks' => $this->quote_remarks,
            'quoted_by' => $this->whenLoaded('quotedBy', fn () => $this->quotedBy ? ['id' => $this->quotedBy->id, 'name' => $this->quotedBy->name] : null),
            'quoted_at' => $this->quoted_at?->toIso8601String(),
            'fare_hold_expires_at' => $this->fare_hold_expires_at?->toDateString(),
            'name_list_deadline' => $this->name_list_deadline?->toDateString(),

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
            'passengers' => GroupBookingPassengerResource::collection($this->whenLoaded('passengers')),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
