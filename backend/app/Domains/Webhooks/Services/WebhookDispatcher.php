<?php

namespace App\Domains\Webhooks\Services;

use App\Domains\Booking\Models\Booking;
use App\Domains\Tenancy\Services\TenantService;
use App\Domains\Webhooks\Models\WebhookDelivery;
use App\Domains\Webhooks\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends booking events to partners' webhook URLs. Each POST carries
 *   X-FyndMyTrip-Event, X-FyndMyTrip-Delivery, X-FyndMyTrip-Timestamp and
 *   X-FyndMyTrip-Signature: sha256=HMAC_SHA256(secret, "{timestamp}.{body}")
 * A partner gets events for its own bookings and those of every account
 * under it. Failed sends are retried (1 min, 5 min, 30 min, 2 h, 6 h,
 * 24 h) by the webhooks:retry job; after 20 failures in a row the URL is
 * switched off.
 */
class WebhookDispatcher
{
    public const EVENTS = [
        'booking.confirmed' => 'Booking confirmed (paid)',
        'booking.ticketed' => 'Ticket / voucher issued',
        'booking.cancelled' => 'Booking cancelled',
        'booking.refunded' => 'Refund completed',
        'booking.failed' => 'Booking failed',
    ];

    protected const BACKOFF_MINUTES = [1, 5, 30, 120, 360, 1440];

    public function bookingEvent(Booking $booking, string $to): void
    {
        $event = 'booking.'.$to;
        if (! isset(self::EVENTS[$event]) || ! $booking->tenant) {
            return;
        }
        $tenantIds = app(TenantService::class)->getAncestorIds($booking->tenant);
        $endpoints = WebhookEndpoint::query()->whereIn('tenant_id', $tenantIds)->where('is_active', true)->get()
            ->filter(fn (WebhookEndpoint $e) => in_array($event, (array) $e->events, true));
        if ($endpoints->isEmpty()) {
            return;
        }
        $payload = $this->bookingPayload($booking, $event);
        foreach ($endpoints as $endpoint) {
            $delivery = WebhookDelivery::query()->create([
                'webhook_endpoint_id' => $endpoint->id, 'event' => $event, 'booking_id' => $booking->id,
                'payload' => $payload, 'status' => 'pending', 'next_attempt_at' => now(),
            ]);
            $this->deliver($delivery);
        }
    }

    public function bookingPayload(Booking $booking, string $event): array
    {
        return [
            'event' => $event,
            'occurred_at' => now()->toIso8601String(),
            'data' => [
                'booking_id' => $booking->id,
                'booking_code' => $booking->booking_code,
                'booking_reference' => $booking->booking_reference,
                'service' => $booking->module,
                'status' => $booking->status,
                'total_amount' => (string) $booking->total_amount,
                'currency' => $booking->currency,
                'travel_date' => $booking->travel_date_start?->toDateString(),
                'supplier_reference' => $booking->supplier_booking_ref,
                'ticket_numbers' => data_get($booking->metadata, 'ticket_numbers'),
                'account_id' => $booking->tenant_id,
            ],
        ];
    }

    public function deliver(WebhookDelivery $delivery): bool
    {
        $endpoint = $delivery->endpoint;
        if (! $endpoint) {
            return false;
        }
        $body = json_encode($delivery->payload + ['delivery_id' => $delivery->id], JSON_UNESCAPED_SLASHES);
        $ts = (string) now()->timestamp;
        $code = null;
        $error = null;
        try {
            $res = Http::timeout(10)->withHeaders([
                'X-FyndMyTrip-Event' => $delivery->event,
                'X-FyndMyTrip-Delivery' => (string) $delivery->id,
                'X-FyndMyTrip-Timestamp' => $ts,
                'X-FyndMyTrip-Signature' => 'sha256='.hash_hmac('sha256', $ts.'.'.$body, (string) $endpoint->secret),
                'User-Agent' => 'FyndMyTrip-Webhooks/1.0',
            ])->withBody($body, 'application/json')->post($endpoint->url);
            $code = $res->status();
            $ok = $res->successful();
            $error = $ok ? null : mb_substr('HTTP '.$code.': '.$res->body(), 0, 500);
        } catch (Throwable $e) {
            $ok = false;
            $error = mb_substr($e->getMessage(), 0, 500);
        }

        $attempts = $delivery->attempts + 1;
        if ($ok) {
            $delivery->update(['status' => 'delivered', 'attempts' => $attempts, 'response_code' => $code, 'error' => null, 'next_attempt_at' => null]);
            $endpoint->forceFill(['failures_in_a_row' => 0, 'last_success_at' => now()])->save();

            return true;
        }
        $wait = self::BACKOFF_MINUTES[$attempts - 1] ?? null;
        $delivery->update([
            'status' => $wait ? 'pending' : 'failed', 'attempts' => $attempts, 'response_code' => $code, 'error' => $error,
            'next_attempt_at' => $wait ? now()->addMinutes($wait) : null,
        ]);
        $fails = $endpoint->failures_in_a_row + 1;
        $endpoint->forceFill(['failures_in_a_row' => $fails, 'is_active' => $fails < 20 && $endpoint->is_active])->save();

        return false;
    }

    public function retryDue(): int
    {
        $n = 0;
        WebhookDelivery::query()->with('endpoint')->where('status', 'pending')->where('next_attempt_at', '<=', now())
            ->orderBy('id')->limit(200)->get()->each(function (WebhookDelivery $d) use (&$n) {
                if ($d->endpoint?->is_active) {
                    $this->deliver($d);
                    $n++;
                }
            });

        return $n;
    }
}
