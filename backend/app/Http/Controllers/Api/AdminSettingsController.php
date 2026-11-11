<?php

namespace App\Http\Controllers\Api;

use App\Domains\Booking\Services\GstService;
use App\Domains\Notifications\Models\NotificationLog;
use App\Domains\Notifications\Models\NotificationTemplate;
use App\Domains\Notifications\Services\NotificationService;
use App\Domains\Settings\Models\InvoiceDocument;
use App\Domains\Settings\Services\SettingsService;
use App\Domains\Settings\Services\SystemStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin settings: tax and invoices, login security, monitoring, message
 * templates, the GST document register and system health. Everything
 * here takes effect immediately, without a code release.
 */
class AdminSettingsController extends Controller
{
    protected const SERVICES = ['flight', 'hotel', 'bus', 'cab', 'holiday', 'insurance', 'visa', 'cruise', 'activity'];

    public function __construct(protected SettingsService $settings)
    {
    }

    public function index(Request $request)
    {
        $this->staffOnly($request->user());

        return response()->json(['data' => [
            'tax' => $this->settings->get('tax'),
            'security' => $this->settings->get('security'),
            'monitoring' => $this->settings->get('monitoring'),
            'fraud' => $this->settings->get('fraud'),
            'platform' => $this->settings->get('platform'),
            'currency' => $this->settings->get('currency'),
            'options' => [
                'currencies' => \App\Domains\Payments\Services\CurrencyService::SUPPORTED,
                'base_currency' => app(\App\Domains\Payments\Services\CurrencyService::class)->base(),
                'live_rates' => rescue(fn () => app(\App\Domains\Payments\Services\CurrencyService::class)->rates(), [], false),
                'services' => self::SERVICES,
                'states' => GstService::STATES,
                'roles' => [User::ROLE_SUPER_ADMIN, User::ROLE_STAFF, User::ROLE_PARTNER_ADMIN, User::ROLE_AGENT, User::ROLE_SUB_AGENT, User::ROLE_STAFF_USER, User::ROLE_CUSTOMER],
            ],
        ]]);
    }

    public function update(Request $request, string $group)
    {
        $user = $request->user();
        $this->staffOnly($user);
        abort_unless(in_array($group, ['tax', 'security', 'monitoring', 'platform', 'currency', 'fraud'], true), 404);

        $rules = match ($group) {
            'tax' => [
                'home_state' => ['sometimes', Rule::in(array_keys(GstService::STATES))],
                'gst_rates' => ['sometimes', 'array'],
                'gst_rates.*' => ['numeric', 'min:0', 'max:28'],
                'sac_codes' => ['sometimes', 'array'],
                'sac_codes.*' => ['string', 'regex:/^\d{4,8}$/'],
                'invoice_prefix' => ['sometimes', 'string', 'regex:/^[A-Za-z0-9]{1,5}$/'],
                'credit_note_prefix' => ['sometimes', 'string', 'regex:/^[A-Za-z0-9]{1,5}$/'],
                'tcs' => ['sometimes', 'array'],
                'tcs.enabled' => ['sometimes', 'boolean'],
                'tcs.threshold' => ['sometimes', 'numeric', 'min:0'],
                'tcs.rate_below' => ['sometimes', 'numeric', 'min:0', 'max:50'],
                'tcs.rate_above' => ['sometimes', 'numeric', 'min:0', 'max:50'],
                'tcs.require_pan' => ['sometimes', 'boolean'],
                'tds' => ['sometimes', 'array'],
                'tds.enabled' => ['sometimes', 'boolean'],
                'tds.rate' => ['sometimes', 'numeric', 'min:0', 'max:30'],
                'tds.rate_no_pan' => ['sometimes', 'numeric', 'min:0', 'max:30'],
                'tds.threshold' => ['sometimes', 'numeric', 'min:0'],
            ],
            'security' => [
                'login_max_attempts' => ['sometimes', 'integer', 'min:3', 'max:20'],
                'login_lockout_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
                'staff_session_hours' => ['sometimes', 'integer', 'min:1', 'max:720'],
                'customer_session_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
                'require_2fa_roles' => ['sometimes', 'array'],
                'require_2fa_roles.*' => ['string', Rule::in([User::ROLE_SUPER_ADMIN, User::ROLE_STAFF, User::ROLE_PARTNER_ADMIN, User::ROLE_AGENT, User::ROLE_SUB_AGENT, User::ROLE_STAFF_USER])],
            ],
            'platform' => [
                'home_country' => ['sometimes', 'string', 'size:2'],
                'search_cache_seconds' => ['sometimes', 'integer', 'min:0', 'max:3600'],
                'supplier_failure_threshold' => ['sometimes', 'integer', 'min:1', 'max:50'],
                'supplier_cooldown_seconds' => ['sometimes', 'integer', 'min:5', 'max:3600'],
                'hold_expiry_minutes' => ['sometimes', 'integer', 'min:0', 'max:10080'],
                'ticketing_alert_hours' => ['sometimes', 'integer', 'min:1', 'max:720'],
                'maintenance' => ['sometimes', 'array'],
                'maintenance.enabled' => ['sometimes', 'boolean'],
                'maintenance.message' => ['sometimes', 'nullable', 'string', 'max:500'],
            ],
            'currency' => [
                'enabled' => ['sometimes', 'array', 'min:1'],
                'enabled.*' => ['string', Rule::in(\App\Domains\Payments\Services\CurrencyService::SUPPORTED)],
                'fx_margin_percent' => ['sometimes', 'numeric', 'min:0', 'max:10'],
                'fixed_rates' => ['sometimes', 'array'],
                'fixed_rates.*' => ['nullable', 'numeric', 'min:0'],
                'country_currency' => ['sometimes', 'array'],
                'country_currency.*' => ['string', Rule::in(\App\Domains\Payments\Services\CurrencyService::SUPPORTED)],
            ],
            'fraud' => [
                'enabled' => ['sometimes', 'boolean'],
                'review_score' => ['sometimes', 'integer', 'min:10', 'max:100'],
                'high_value_amount' => ['sometimes', 'numeric', 'min:0'],
                'new_account_hours' => ['sometimes', 'integer', 'min:0', 'max:720'],
                'max_bookings_per_contact_day' => ['sometimes', 'integer', 'min:1', 'max:100'],
                'max_failed_payments_day' => ['sometimes', 'integer', 'min:1', 'max:50'],
                'max_bookings_per_traveller_day' => ['sometimes', 'integer', 'min:1', 'max:100'],
                'blocked_email_domains' => ['sometimes', 'array', 'max:200'],
                'blocked_email_domains.*' => ['string', 'max:100', 'regex:/^[a-z0-9.-]+\\.[a-z]{2,}$/i'],
            ],
            'monitoring' => [
                'alert_emails' => ['sometimes', 'array', 'max:10'],
                'alert_emails.*' => ['email'],
                'backup_keep_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            ],
        };

        $data = $request->validate($rules);
        if (isset($data['gst_rates'])) {
            $data['gst_rates'] = array_intersect_key(array_map('floatval', $data['gst_rates']), array_flip(self::SERVICES));
        }
        if (isset($data['sac_codes'])) {
            $data['sac_codes'] = array_intersect_key($data['sac_codes'], array_flip(self::SERVICES));
        }
        if (isset($data['home_country'])) {
            $data['home_country'] = strtoupper($data['home_country']);
        }
        if (isset($data['fixed_rates'])) {
            $data['fixed_rates'] = collect($data['fixed_rates'])->mapWithKeys(fn ($v, $k) => [strtoupper($k) => $v])
                ->filter(fn ($v, $k) => in_array($k, \App\Domains\Payments\Services\CurrencyService::SUPPORTED, true) && is_numeric($v) && $v > 0)
                ->map(fn ($v) => (float) $v)->all();
            \Illuminate\Support\Facades\Cache::forget('currency_rates.'.app(\App\Domains\Payments\Services\CurrencyService::class)->base());
        }
        if (isset($data['country_currency'])) {
            $data['country_currency'] = collect($data['country_currency'])->mapWithKeys(fn ($v, $k) => [strtoupper($k) => strtoupper($v)])
                ->filter(fn ($v, $k) => preg_match('/^[A-Z]{2}$/', $k))->all();
        }
        if ($group === 'currency' && (isset($data['fixed_rates']) || isset($data['country_currency']))) {
            // Maps replace whole (a removed country/rate must go away).
            if ($row = \App\Domains\Settings\Models\AppSetting::query()->where('key', 'currency')->first()) {
                $v = (array) $row->value;
                foreach (['fixed_rates', 'country_currency'] as $k) {
                    if (array_key_exists($k, $data)) {
                        unset($v[$k]);
                    }
                }
                $row->forceFill(['value' => $v])->saveQuietly();
            }
        }
        foreach (['invoice_prefix', 'credit_note_prefix'] as $k) {
            if (isset($data[$k])) {
                $data[$k] = strtoupper($data[$k]);
            }
        }

        return response()->json(['data' => $this->settings->put($group, $data, $user->id)]);
    }

    /* ---------------- Change history ---------------- */

    public function auditLogs(Request $request)
    {
        $user = $request->user();
        $q = \App\Domains\Audit\Models\AuditLog::query()->with('user:id,name,email,role')->latest('id');
        if (! $user->isPlatformStaff()) {
            abort_unless($user->role === User::ROLE_PARTNER_ADMIN && $user->tenant, 403);
            $q->whereIn('tenant_id', app(\App\Domains\Tenancy\Services\TenantService::class)->getDescendantIdsRobust($user->tenant));
        }
        $f = $request->validate([
            'type' => ['nullable', 'string', 'max:60'], 'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'q' => ['nullable', 'string', 'max:100'],
        ]);
        $q->when($f['type'] ?? null, fn ($q, $v) => $q->where('subject_type', $v))
            ->when($f['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<', \Carbon\Carbon::parse($v)->addDay()))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where('label', 'like', '%'.$v.'%'));
        $page = $q->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn ($l) => [
                'id' => $l->id, 'at' => $l->created_at?->toIso8601String(), 'action' => $l->action,
                'type' => $l->subject_type, 'subject_id' => $l->subject_id, 'label' => $l->label, 'changes' => $l->changes, 'ip' => $l->ip,
                'user' => $l->user ? ['id' => $l->user->id, 'name' => $l->user->name, 'email' => $l->user->email] : null,
            ]),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
            'types' => array_map('class_basename', array_keys(\App\Domains\Audit\Services\AuditTrail::WATCHED)),
        ]);
    }

    /* ---------------- Message templates ---------------- */

    public function templates(Request $request)
    {
        $tenant = $this->templateTenant($request->user());
        $notify = app(NotificationService::class);
        $events = [];

        foreach (NotificationService::events() as $key => $event) {
            if ($event['audience'] === 'staff' && $tenant) {
                continue;
            }
            $channels = [];
            foreach ($event['channels'] as $channel) {
                $own = NotificationTemplate::query()->where('tenant_id', $tenant?->id)->where('event', $key)->where('channel', $channel)->first();
                $current = $own ? ['subject' => $own->subject, 'body' => $own->body, 'provider_template_id' => $own->provider_template_id, 'source' => 'edited']
                    : ($notify->templateFor($key, $channel, $tenant) ?? ['subject' => null, 'body' => '', 'provider_template_id' => null, 'source' => 'default']);
                $channels[$channel] = $current + ['is_active' => $own ? $own->is_active : true, 'is_edited' => (bool) $own];
            }
            $events[] = ['event' => $key, 'label' => $event['label'], 'audience' => $event['audience'], 'channels' => $channels];
        }

        return response()->json(['data' => [
            'events' => $events,
            'placeholders' => config('notification_templates.placeholders'),
        ]]);
    }

    public function saveTemplate(Request $request)
    {
        $tenant = $this->templateTenant($request->user());
        $data = $request->validate([
            'event' => ['required', Rule::in(array_keys(NotificationService::events()))],
            'channel' => ['required', Rule::in(['email', 'sms', 'whatsapp'])],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'provider_template_id' => ['nullable', 'string', 'max:100'],
            'is_active' => ['required', 'boolean'],
        ]);

        $row = NotificationTemplate::query()->updateOrCreate(
            ['tenant_id' => $tenant?->id, 'event' => $data['event'], 'channel' => $data['channel']],
            $data,
        );

        return response()->json(['data' => $row]);
    }

    /** Back to the default text (or the parent account's). */
    public function resetTemplate(Request $request, string $event, string $channel)
    {
        $tenant = $this->templateTenant($request->user());
        NotificationTemplate::query()->where('tenant_id', $tenant?->id)->where('event', $event)->where('channel', $channel)->delete();

        return response()->json(['message' => 'Back to the default text.']);
    }

    /** Sends a sample of a message with made-up booking details. */
    public function testTemplate(Request $request)
    {
        $user = $request->user();
        $tenant = $this->templateTenant($user);
        $data = $request->validate([
            'event' => ['required', Rule::in(array_keys(NotificationService::events()))],
            'channel' => ['required', Rule::in(['email', 'sms', 'whatsapp'])],
            'to' => ['required', 'string', 'max:191'],
        ]);
        $brand = \App\Support\TenantMailBranding::resolve($tenant ?? $user->tenant);
        $vars = [
            'customer_name' => $user->name, 'booking_code' => 'FMT-FL-000123', 'service' => 'Flight', 'trip' => 'Chennai → Dubai',
            'travel_date' => now()->addDays(10)->format('d M Y'), 'amount' => 'INR 18,450.00', 'refund_amount' => 'INR 12,000.00',
            'ticket_numbers' => 'PNR AB12CD', 'status' => 'ticketed', 'reason' => 'Supplier timeout (sample)',
            'brand_name' => $brand['brand_name'], 'support_email' => $brand['support_email'], 'support_phone' => $brand['support_phone'] ?? '',
            'manage_url' => rtrim((string) config('app.frontend_url'), '/').'/my-trips',
        ];

        $log = app(NotificationService::class)->send($data['event'], $data['channel'], $data['to'], $vars, $tenant ?? $user->tenant);
        abort_if(! $log, 422, $data['channel'] === 'email' ? 'This message is turned off.' : 'No '.strtoupper($data['channel']).' provider is connected yet (Notification gateways), or this message is turned off.');

        return response()->json(['data' => ['status' => $log->status, 'error' => $log->error, 'provider' => $log->provider]]);
    }

    public function logs(Request $request)
    {
        $user = $request->user();
        $tenant = $this->templateTenant($user);
        $rows = NotificationLog::withoutTenantScope()->when($tenant, fn ($q) => $q->where('tenant_id', $tenant->id))
            ->latest('id')->limit(100)->get()
            ->map(fn ($l) => [
                'id' => $l->id, 'event' => $l->template_key, 'channel' => $l->channel, 'provider' => $l->provider,
                'recipient' => self::mask((string) $l->recipient), 'status' => $l->status, 'error' => $l->error,
                'booking_id' => $l->booking_id, 'created_at' => $l->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $rows]);
    }

    /* ---------------- GST register ---------------- */

    public function invoiceRegister(Request $request)
    {
        $this->staffOnly($request->user());
        $fy = $request->query('financial_year', \App\Domains\Settings\Services\TaxService::financialYear(now()));
        $docs = InvoiceDocument::query()->with('booking.tenant', 'originalInvoice')->where('financial_year', $fy)->orderBy('type')->orderBy('sequence')->get();

        $rows = $docs->map(function (InvoiceDocument $d) {
            $b = $d->booking;
            $buyer = GstService::gstin($b?->tenant?->business_registration_no);

            return [
                'number' => $d->number,
                'type' => $d->type === InvoiceDocument::TYPE_INVOICE ? 'Invoice' : 'Credit note',
                'date' => $d->issued_at->toDateString(),
                'against' => $d->originalInvoice?->number,
                'booking' => $b?->booking_code ?? $b?->booking_reference,
                'service' => $b?->module,
                'buyer_gstin' => $buyer,
                'sac' => $d->details['sac'] ?? null,
                'gst_rate' => $d->details['gst_rate'] ?? null,
                'taxable_value' => round((float) $d->amount - (float) $d->tax_amount - (float) $d->tcs_amount, 2),
                'gst' => (float) $d->tax_amount,
                'tcs' => (float) $d->tcs_amount,
                'total' => (float) $d->amount,
            ];
        });

        if ($request->query('format') === 'csv') {
            $out = fopen('php://temp', 'r+');
            fputcsv($out, ['Number', 'Type', 'Date', 'Against invoice', 'Booking', 'Service', 'Buyer GSTIN', 'SAC', 'GST rate %', 'Taxable value', 'GST', 'TCS', 'Total']);
            foreach ($rows as $r) {
                fputcsv($out, array_values($r));
            }
            rewind($out);

            return response(stream_get_contents($out), 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => "attachment; filename=\"gst-register-{$fy}.csv\"",
            ]);
        }

        return response()->json(['data' => $rows, 'financial_year' => $fy]);
    }

    public function systemStatus(Request $request)
    {
        $this->staffOnly($request->user());

        return response()->json(['data' => app(SystemStatus::class)->report(true)]);
    }

    protected function staffOnly(User $user): void
    {
        abort_unless($user->isPlatformStaff(), 403, 'Only platform admins can change these settings.');
    }

    /** Platform staff edit platform texts; a white-label partner admin edits their own. */
    protected function templateTenant(User $user): ?\App\Domains\Tenancy\Models\Tenant
    {
        if ($user->isPlatformStaff()) {
            return null;
        }
        abort_unless($user->role === User::ROLE_PARTNER_ADMIN, 403, 'Only admins can edit messages.');

        return $user->tenant;
    }

    protected static function mask(string $to): string
    {
        if (str_contains($to, '@')) {
            [$a, $d] = explode('@', $to, 2);

            return substr($a, 0, 2).str_repeat('*', max(1, strlen($a) - 2)).'@'.$d;
        }

        return str_repeat('*', max(0, strlen($to) - 4)).substr($to, -4);
    }
}
