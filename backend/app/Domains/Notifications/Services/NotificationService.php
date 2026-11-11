<?php

namespace App\Domains\Notifications\Services;

use App\Domains\Booking\Models\Booking;
use App\Domains\Notifications\Models\NotificationGatewayConfig;
use App\Domains\Notifications\Models\NotificationLog;
use App\Domains\Notifications\Models\NotificationTemplate;
use App\Domains\Settings\Services\SettingsService;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\TenantMailBranding;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends booking messages by email, SMS and WhatsApp using the texts admins
 * edit (Admin settings → Messages) and the providers they connect
 * (Notification gateways). A channel with no provider connected is skipped
 * quietly, except email, which falls back to the server's own mail
 * settings. Nothing here can make a booking fail: every error is logged
 * to notification_logs instead.
 */
class NotificationService
{
    public function __construct(protected ChannelSender $sender, protected SettingsService $settings)
    {
    }

    /** @return array<string, array{label: string, audience: string, channels: string[]}> */
    public static function events(): array
    {
        $out = [];
        foreach (config('notification_templates.events') as $key => $def) {
            $out[$key] = [
                'label' => $def['label'],
                'audience' => $def['audience'],
                'channels' => array_values(array_intersect(['email', 'sms', 'whatsapp'], array_keys($def))),
            ];
        }

        return $out;
    }

    /**
     * The template in force for this tenant: its own, else the nearest
     * parent's (a white-label partner's agents get the partner's texts),
     * else the platform's edited one, else the built-in default.
     */
    public function templateFor(string $event, string $channel, ?Tenant $tenant): ?array
    {
        $chain = [];
        for ($t = $tenant, $guard = 0; $t && $guard < 10; $t = $t->parent, $guard++) {
            $chain[] = $t->id;
        }

        $rows = NotificationTemplate::query()->where('event', $event)->where('channel', $channel)
            ->where(fn ($q) => $q->whereIn('tenant_id', $chain ?: [0])->orWhereNull('tenant_id'))->get();

        foreach ([...$chain, null] as $tid) {
            $row = $rows->first(fn ($r) => $r->tenant_id === $tid);
            if ($row) {
                return $row->is_active ? ['subject' => $row->subject, 'body' => $row->body, 'provider_template_id' => $row->provider_template_id, 'source' => $tid ? 'tenant' : 'platform'] : null;
            }
        }

        $default = config("notification_templates.events.{$event}.{$channel}");

        return $default ? ['subject' => $default['subject'] ?? null, 'body' => $default['body'], 'provider_template_id' => null, 'source' => 'default'] : null;
    }

    public static function render(?string $text, array $vars): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn ($m) => (string) ($vars[$m[1]] ?? ''), (string) $text);
    }

    public function bookingVars(Booking $booking, array $extra = []): array
    {
        $tenant = $booking->tenant;
        $brand = TenantMailBranding::resolve($tenant);
        $meta = $booking->metadata ?? [];
        $lead = $meta['travelers'][0]['name'] ?? $booking->passengers()->value('name') ?? null;
        $trip = $meta['package_name'] ?? $meta['product_name'] ?? $meta['hotel_name'] ?? $meta['route_summary']
            ?? trim(($meta['origin'] ?? '').(! empty($meta['destination']) ? ' → '.$meta['destination'] : ''))
            ?: ucfirst($booking->module);
        $tickets = $meta['ticket_numbers'] ?? [];

        return array_merge([
            'customer_name' => $lead ?: ($booking->createdBy?->name ?? 'Traveller'),
            'booking_code' => $booking->booking_code ?? $booking->booking_reference,
            'service' => ucfirst($booking->module),
            'trip' => $trip,
            'travel_date' => $booking->travel_date_start?->format('d M Y') ?? '',
            'amount' => $booking->currency.' '.number_format((float) $booking->total_amount, 2),
            'refund_amount' => '',
            'ticket_numbers' => is_array($tickets) && $tickets ? implode(', ', $tickets) : ($booking->supplier_booking_ref ?? $booking->booking_code ?? ''),
            'status' => $booking->status,
            'reason' => '',
            'brand_name' => $brand['brand_name'],
            'support_email' => $brand['support_email'],
            'support_phone' => $brand['support_phone'] ?? '',
            'manage_url' => rtrim((string) ($tenant?->custom_domain ? 'https://'.$tenant->custom_domain : config('app.frontend_url', 'https://demo.fyndmytrip.in')), '/').'/my-trips',
        ], $extra);
    }

    /** Sends one booking event on every channel that has a template and a recipient. */
    public function sendForBooking(string $event, Booking $booking, array $extra = []): void
    {
        try {
            $vars = $this->bookingVars($booking, $extra);
            $email = $booking->contact_email ?: $booking->createdBy?->email;
            $phone = $booking->contact_phone ?: $booking->createdBy?->phone;

            foreach (['email' => $email, 'sms' => $phone, 'whatsapp' => $phone] as $channel => $to) {
                if ($to) {
                    $this->send($event, $channel, $to, $vars, $booking->tenant, $booking->id);
                }
            }
        } catch (Throwable $e) {
            Log::warning("Notification {$event} for booking {$booking->id} failed: ".$e->getMessage());
        }
    }

    /** Emails the alert list set in Admin settings → Monitoring. */
    public function alertStaff(string $event, array $vars, ?int $bookingId = null): void
    {
        foreach ((array) $this->settings->value('monitoring', 'alert_emails', []) as $to) {
            if (filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $this->send($event, 'email', $to, $vars, null, $bookingId);
            }
        }
    }

    public function send(string $event, string $channel, string $to, array $vars, ?Tenant $tenant, ?int $bookingId = null): ?NotificationLog
    {
        $template = $this->templateFor($event, $channel, $tenant);
        if (! $template) {
            return null;
        }

        $gateway = $this->gatewayFor($channel, $tenant);
        if (! $gateway && $channel !== 'email') {
            return null; // no SMS/WhatsApp provider connected yet
        }

        $log = NotificationLog::query()->create([
            'tenant_id' => $tenant?->id ?? Tenant::query()->where('type', Tenant::TYPE_MASTER)->value('id'),
            'booking_id' => $bookingId,
            'channel' => $channel,
            'provider' => $gateway?->provider ?? 'mail',
            'recipient' => $to,
            'template_key' => $event,
            'status' => 'queued',
        ]);

        // Provider templates (WhatsApp/DLT) take values by position: the
        // order the placeholders appear in the text.
        preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', (string) $template['body'], $m);
        $vars['__ordered'] = array_map(fn ($k) => (string) ($vars[$k] ?? ''), $m[1]);

        try {
            $messageId = $this->sender->send(
                $channel,
                $gateway,
                $to,
                self::render($template['subject'], $vars),
                self::render($template['body'], $vars),
                $template['provider_template_id'] ?? ($gateway?->dlt_template_ids[$event] ?? null),
                $vars,
                TenantMailBranding::resolve($tenant),
            );
            $log->forceFill(['status' => 'sent', 'provider_message_id' => $messageId ? substr($messageId, 0, 100) : null, 'sent_at' => now()])->save();
        } catch (Throwable $e) {
            $log->forceFill(['status' => 'failed', 'error' => substr($e->getMessage(), 0, 2000)])->save();
            Log::warning("Notification {$event}/{$channel} failed: ".$e->getMessage());
        }

        return $log;
    }

    protected function gatewayFor(string $channel, ?Tenant $tenant): ?NotificationGatewayConfig
    {
        $ids = [];
        for ($t = $tenant, $guard = 0; $t && $guard < 10; $t = $t->parent, $guard++) {
            $ids[] = $t->id;
        }
        $rows = NotificationGatewayConfig::query()->where('channel', $channel)->where('is_active', true)
            ->where(fn ($q) => $q->whereIn('tenant_id', $ids ?: [0])->orWhereNull('tenant_id'))->get();

        foreach ([...$ids, null] as $tid) {
            if ($row = $rows->first(fn ($r) => $r->tenant_id === $tid)) {
                return $row;
            }
        }

        return null;
    }
}
