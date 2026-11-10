<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Domains\Booking\Models\Booking
 */
class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $showMargin = self::canSeeMargin($request);

        return [
            'id' => $this->id,
            'booking_reference' => $this->booking_reference,
            'booking_code' => $this->booking_code,
            'channel' => $this->channel,
            'module' => $this->module,
            'tenant_id' => $this->tenant_id,
            'tenant_name' => $this->whenLoaded('tenant', fn () => $this->tenant?->name),
            'created_by_user_id' => $this->created_by_user_id,
            'created_by_name' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'created_by_email' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->email),
            'is_international' => (bool) $this->is_international,
            'supplier_code' => $this->supplier_code,
            'supplier_booking_ref' => $this->supplier_booking_ref,
            'ticket_numbers' => $this->metadata['ticket_numbers'] ?? [],
            'status' => $this->status,
            'gross_amount' => (string) $this->gross_amount,
            // The supplier cost and the margin earned on it are never sent to
            // a traveller (customers, sub-agents, staff users) — only to
            // people who run margins (see canSeeMargin()). Search results
            // already withheld them; the booking itself used to leak them.
            'supplier_net_amount' => $this->when($showMargin, fn () => (string) $this->supplier_net_amount),
            'markup_amount' => $this->when($showMargin, fn () => (string) $this->markup_amount),
            'extras_amount' => (string) $this->extras_amount,
            'coupon_code' => $this->coupon_code,
            'discount_amount' => (string) $this->discount_amount,
            'total_amount' => (string) ($this->total_amount && (float) $this->total_amount > 0 ? $this->total_amount : $this->recalculateTotal()),
            'currency' => $this->currency,
            'travel_date_start' => $this->travel_date_start?->toDateString(),
            'travel_date_end' => $this->travel_date_end?->toDateString(),
            'passenger_count' => $this->passenger_count,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'segments' => $this->whenLoaded('segments'),
            'passengers' => $this->whenLoaded('passengers'),
            'ancillaries' => $this->whenLoaded('ancillaries'),
            'status_history' => $this->whenLoaded('statusHistory'),
            // Phase 1 of the booking-ops roadmap — everything that isn't a
            // status change (payment/supplier events, manual staff notes).
            // The frontend merges this with status_history into one
            // chronological timeline; see BookingEvent's own docblock.
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($e) => [
                'id' => $e->id,
                'event_type' => $e->event_type,
                'message' => $e->message,
                'meta' => $e->meta,
                'actor_user_id' => $e->actor_user_id,
                'actor_name' => $e->actor?->name,
                'created_at' => $e->created_at,
            ])),
            // Phase 2's refund issuance — one row per refund attempt on this
            // booking (see RefundTransaction/RefundService); the frontend
            // uses the most recent 'processing' one to render the
            // "Refund in progress" panel with Complete/Fail actions.
            'refund_transactions' => $this->whenLoaded('refundTransactions', fn () => $this->refundTransactions->map(fn ($r) => [
                'id' => $r->id,
                'method' => $r->method,
                'status' => $r->status,
                'requested_amount' => (string) $r->requested_amount,
                'currency' => $r->currency,
                'previous_booking_status' => $r->previous_booking_status,
                'gateway_reference' => $r->gateway_reference,
                'bank_account_name' => $r->bank_account_name,
                'bank_account_number' => $r->bank_account_number,
                'bank_ifsc' => $r->bank_ifsc,
                'notes' => $r->notes,
                'failure_reason' => $r->failure_reason,
                'initiated_by_name' => $r->initiatedBy?->name,
                'completed_by_name' => $r->completedBy?->name,
                'completed_at' => $r->completed_at,
                'created_at' => $r->created_at,
            ])),
            // Phase 2's last bullet — reschedule/date-change/reissue actions
            // on a TICKETED booking (see BookingAmendment/
            // BookingAmendmentService); the frontend renders these as an
            // "Amendment history" list alongside refund_transactions above.
            'amendments' => $this->whenLoaded('amendments', fn () => $this->amendments->map(fn ($a) => [
                'id' => $a->id,
                'type' => $a->type,
                'previous_travel_date_start' => $a->previous_travel_date_start?->toDateString(),
                'previous_travel_date_end' => $a->previous_travel_date_end?->toDateString(),
                'new_travel_date_start' => $a->new_travel_date_start?->toDateString(),
                'new_travel_date_end' => $a->new_travel_date_end?->toDateString(),
                'previous_supplier_booking_ref' => $a->previous_supplier_booking_ref,
                'new_supplier_booking_ref' => $a->new_supplier_booking_ref,
                'previous_ticket_numbers' => $a->previous_ticket_numbers,
                'new_ticket_numbers' => $a->new_ticket_numbers,
                'fee_amount' => (string) $a->fee_amount,
                'currency' => $a->currency,
                'fee_collection' => $a->fee_collection,
                'initiated_by_name' => $a->initiatedBy?->name,
                'notes' => $a->notes,
                'created_at' => $a->created_at,
            ])),
            'metadata' => $showMargin ? $this->metadata : \Illuminate\Support\Arr::except($this->metadata ?? [], ['catalog_amount', 'reseller_markup_amount']),
            'created_at' => $this->created_at,
        ];
    }

    /** Super admin (pricing.view_margin) and anyone who manages margin rules. */
    public static function canSeeMargin(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && ($user->hasPermission('pricing.view_margin') || $user->hasPermission('markup.manage'));
    }
}
