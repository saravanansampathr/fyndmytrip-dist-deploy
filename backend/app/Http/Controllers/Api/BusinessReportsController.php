<?php

namespace App\Http\Controllers\Api;

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Services\GstService;
use App\Domains\Commission\Models\CommissionCredit;
use App\Domains\Settings\Models\InvoiceDocument;
use App\Domains\Settings\Services\TaxService;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Business reports: profit by booking, sales by agent / partner /
 * supplier / service / month, cancellations, search-to-booking funnel,
 * and the tax returns data (GSTR-1, TCS and TDS registers). Every report
 * also downloads as CSV (?format=csv).
 */
class BusinessReportsController extends Controller
{
    protected const SOLD = [Booking::STATUS_CONFIRMED, Booking::STATUS_TICKETED];

    protected const CANCELLED = [Booking::STATUS_CANCELLED, Booking::STATUS_REFUND_PROCESSING, Booking::STATUS_REFUNDED];

    public function __construct(protected TenantService $tenants) {}

    /** Sale, supplier cost, margin, commission and GST per booking or group. */
    public function profit(Request $request)
    {
        $user = $request->user();
        abort_unless($user->isPlatformStaff() || $user->hasPermission('pricing.view_margin'), 403, 'Only staff who may see margins can open the profit report.');
        $f = $this->filters($request);
        $group = $request->validate(['group_by' => ['nullable', 'in:booking,agent,partner,supplier,service,month']])['group_by'] ?? 'booking';

        $bookings = $this->bookings($user, $f)->whereIn('status', self::SOLD)->with('tenant:id,name,type,parent_tenant_id')->get();
        $gst = InvoiceDocument::query()->where('type', InvoiceDocument::TYPE_INVOICE)->whereIn('booking_id', $bookings->pluck('id'))->pluck('tax_amount', 'booking_id');

        $rows = $bookings->map(function (Booking $b) use ($gst) {
            $tax = (float) ($gst[$b->id] ?? 0);
            $margin = (float) $b->markup_amount;

            return [
                'booking' => $b->booking_code ?? $b->booking_reference,
                'date' => $b->created_at?->toDateString(),
                'service' => $b->module,
                'agent' => $b->tenant?->name,
                'partner' => $this->partnerName($b->tenant),
                'supplier' => $b->supplier_code,
                'sale' => round((float) $b->total_amount, 2),
                'supplier_cost' => round((float) $b->supplier_net_amount, 2),
                'margin' => round($margin, 2),
                'gst_on_margin' => round($tax, 2),
                'discount' => round((float) $b->discount_amount, 2),
                'commission_paid' => round((float) $b->commission_amount, 2),
                'net_profit' => round($margin - $tax - (float) $b->commission_amount - (float) $b->discount_amount, 2),
            ];
        });

        if ($group !== 'booking') {
            $key = ['agent' => 'agent', 'partner' => 'partner', 'supplier' => 'supplier', 'service' => 'service', 'month' => 'date'][$group];
            $rows = $rows->groupBy(fn ($r) => $group === 'month' ? substr((string) $r['date'], 0, 7) : ($r[$key] ?? '—'))
                ->map(fn (Collection $g, $k) => ['group' => $k ?: '—', 'bookings' => $g->count()] + $this->sum($g, ['sale', 'supplier_cost', 'margin', 'gst_on_margin', 'discount', 'commission_paid', 'net_profit']))
                ->sortByDesc('sale')->values();
        }

        $totals = ['bookings' => $bookings->count()] + $this->sum($group === 'booking' ? $rows : $rows, ['sale', 'supplier_cost', 'margin', 'gst_on_margin', 'discount', 'commission_paid', 'net_profit']);

        return $this->respond($request, "profit-{$group}", $rows, $totals);
    }

    /** Bookings and value by service, agent, partner or supplier (no cost data). */
    public function sales(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('booking.view'), 403);
        $f = $this->filters($request);
        $group = $request->validate(['group_by' => ['nullable', 'in:agent,partner,supplier,service,month,channel']])['group_by'] ?? 'service';
        $bookings = $this->bookings($user, $f)->with('tenant:id,name,type,parent_tenant_id')->get();

        $rows = $bookings->groupBy(fn (Booking $b) => match ($group) {
            'agent' => $b->tenant?->name, 'partner' => $this->partnerName($b->tenant), 'supplier' => $b->supplier_code,
            'month' => $b->created_at?->format('Y-m'), 'channel' => $b->channel, default => $b->module,
        } ?: '—')->map(function (Collection $g, $k) {
            $sold = $g->whereIn('status', self::SOLD);
            $cancelled = $g->whereIn('status', self::CANCELLED);

            return [
                'group' => $k, 'bookings' => $g->count(), 'confirmed' => $sold->count(), 'cancelled' => $cancelled->count(),
                'failed' => $g->where('status', Booking::STATUS_FAILED)->count(),
                'sales_value' => round($sold->sum(fn ($b) => (float) $b->total_amount), 2),
                'passengers' => (int) $sold->sum('passenger_count'),
                'average_booking' => $sold->count() ? round($sold->sum(fn ($b) => (float) $b->total_amount) / $sold->count(), 2) : 0,
            ];
        })->sortByDesc('sales_value')->values();

        return $this->respond($request, "sales-{$group}", $rows, ['bookings' => $bookings->count()] + $this->sum($rows, ['confirmed', 'cancelled', 'failed', 'sales_value', 'passengers']));
    }

    /** Cancelled and refunded bookings with the money returned. */
    public function cancellations(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('booking.view'), 403);
        $f = $this->filters($request);
        $bookings = $this->bookings($user, $f)->whereIn('status', array_merge(self::CANCELLED, [Booking::STATUS_FAILED]))->with('tenant:id,name')->get();
        $refunds = DB::table('refund_transactions')->whereIn('booking_id', $bookings->pluck('id'))->where('status', 'completed')->selectRaw('booking_id, SUM(requested_amount) as refunded')->groupBy('booking_id')->get()->keyBy('booking_id');

        $rows = $bookings->map(fn (Booking $b) => [
            'booking' => $b->booking_code ?? $b->booking_reference, 'booked_on' => $b->created_at?->toDateString(),
            'service' => $b->module, 'account' => $b->tenant?->name, 'status' => $b->status,
            'amount' => round((float) $b->total_amount, 2), 'refunded' => round((float) ($refunds[$b->id]->refunded ?? 0), 2),
            'kept' => round((float) $b->total_amount - (float) ($refunds[$b->id]->refunded ?? 0), 2),
            'reason' => data_get($b->metadata, 'cancellation_reason') ?? data_get($b->metadata, 'failure_reason'),
        ]);

        return $this->respond($request, 'cancellations', $rows, ['bookings' => $rows->count()] + $this->sum($rows, ['amount', 'refunded', 'kept']));
    }

    /** Searches → bookings started → confirmed, per service. */
    public function funnel(Request $request)
    {
        $user = $request->user();
        abort_unless($user->isPlatformStaff(), 403);
        $f = $this->filters($request);
        $searches = DB::table('recent_searches')->whereBetween('created_at', [$f['from'], $f['to']])->selectRaw('module, COUNT(*) as n')->groupBy('module')->pluck('n', 'module');
        $bookings = $this->bookings($user, $f)->get()->groupBy('module');

        $rows = collect(Tenant::ALL_MODULES)->map(function ($m) use ($searches, $bookings) {
            $all = $bookings->get($m, collect());
            $s = (int) ($searches[$m] ?? 0);
            $sold = $all->whereIn('status', self::SOLD)->count();

            return ['service' => $m, 'searches' => $s, 'bookings_started' => $all->count(), 'confirmed' => $sold,
                'search_to_booking_percent' => $s ? round($sold * 100 / $s, 1) : null,
                'started_to_confirmed_percent' => $all->count() ? round($sold * 100 / $all->count(), 1) : null];
        })->values();

        return $this->respond($request, 'funnel', $rows, $this->sum($rows, ['searches', 'bookings_started', 'confirmed']), 'Searches are counted for signed-in users only.');
    }

    /** GSTR-1 data for a month: B2B, B2C (small) and credit notes. */
    public function gstr1(Request $request)
    {
        abort_unless($request->user()->isPlatformStaff(), 403);
        $d = $request->validate(['month' => ['required', 'date_format:Y-m'], 'section' => ['nullable', 'in:b2b,b2cs,cdnr,cdnur,hsn']]);
        $start = Carbon::parse($d['month'].'-01')->startOfMonth();
        $docs = InvoiceDocument::query()->with('booking.tenant', 'originalInvoice')->whereBetween('issued_at', [$start, $start->copy()->endOfMonth()])->get();
        $tax = app(TaxService::class);
        $master = Tenant::query()->where('type', Tenant::TYPE_MASTER)->first();

        $lines = $docs->map(function (InvoiceDocument $doc) use ($tax, $master) {
            $b = $doc->booking;
            // The buyer is the booking's own account (agent / partner); a
            // customer booking directly on the main site is B2C.
            $buyer = $b?->tenant && $b->tenant->type !== Tenant::TYPE_MASTER ? GstService::gstin($b->tenant->business_registration_no) : null;
            $seller = GstService::gstin($master?->business_registration_no);
            $sellerState = $seller ? substr($seller, 0, 2) : $tax->homeState();
            $pos = $buyer ? substr($buyer, 0, 2) : $sellerState;
            $rate = (float) ($doc->details['gst_rate'] ?? 18);
            $gst = (float) $doc->tax_amount;
            $taxable = $rate > 0 ? round($gst * 100 / $rate, 2) : 0.0;
            $inter = $pos !== $sellerState;

            return [
                'type' => $doc->type, 'number' => $doc->number, 'date' => $doc->issued_at->format('d-M-Y'), 'against' => $doc->originalInvoice?->number,
                'buyer_gstin' => $buyer, 'buyer_name' => $b?->tenant?->name, 'place_of_supply' => $pos.'-'.(GstService::STATES[$pos] ?? ''),
                'document_value' => round((float) $doc->amount, 2), 'rate' => $rate, 'taxable_value' => $taxable,
                'igst' => $inter ? round($gst, 2) : 0.0, 'cgst' => $inter ? 0.0 : round($gst / 2, 2), 'sgst' => $inter ? 0.0 : round($gst - round($gst / 2, 2), 2),
                'sac' => $doc->details['sac'] ?? null,
            ];
        });

        $inv = $lines->where('type', InvoiceDocument::TYPE_INVOICE);
        $cn = $lines->where('type', InvoiceDocument::TYPE_CREDIT_NOTE);
        $sections = [
            'b2b' => $inv->whereNotNull('buyer_gstin')->values(),
            'b2cs' => $inv->whereNull('buyer_gstin')->groupBy(fn ($l) => $l['place_of_supply'].'|'.$l['rate'])
                ->map(fn ($g, $k) => ['place_of_supply' => explode('|', $k)[0], 'rate' => (float) explode('|', $k)[1], 'type' => 'OE'] + $this->sum($g, ['taxable_value', 'igst', 'cgst', 'sgst']))->values(),
            'cdnr' => $cn->whereNotNull('buyer_gstin')->values(),
            'cdnur' => $cn->whereNull('buyer_gstin')->values(),
            'hsn' => $lines->groupBy('sac')->map(fn ($g, $sac) => ['sac' => $sac, 'documents' => $g->count()] + $this->sum($g->map(function ($l) {
                $k = $l['type'] === InvoiceDocument::TYPE_CREDIT_NOTE ? -1 : 1;

                return ['taxable_value' => $k * $l['taxable_value'], 'igst' => $k * $l['igst'], 'cgst' => $k * $l['cgst'], 'sgst' => $k * $l['sgst']];
            }), ['taxable_value', 'igst', 'cgst', 'sgst']))->values(),
        ];

        if ($request->query('format') === 'csv') {
            $section = $d['section'] ?? 'b2b';

            return $this->csv("gstr1-{$d['month']}-{$section}.csv", $sections[$section]);
        }

        return response()->json(['data' => $sections, 'meta' => ['month' => $d['month'], 'note' => 'Taxable value is the service fee (margin) on which GST is charged. Upload through your GST software or the GSTN offline tool; have your CA review before filing.']]);
    }

    /** TCS collected on overseas packages, for the quarterly 27EQ return. */
    public function tcs(Request $request)
    {
        abort_unless($request->user()->isPlatformStaff(), 403);
        [$from, $to, $label] = $this->quarter($request);
        $rows = Booking::withoutTenantScope()->whereBetween('created_at', [$from, $to])->whereIn('status', array_merge(self::SOLD, self::CANCELLED))->get()
            ->filter(fn (Booking $b) => (float) data_get($b->metadata, 'tcs_amount', 0) > 0)
            ->map(fn (Booking $b) => [
                'booking' => $b->booking_code ?? $b->booking_reference, 'date' => $b->created_at->toDateString(), 'status' => $b->status,
                'customer_email' => $b->contact_email, 'pan' => data_get($b->metadata, 'tcs_pan') ?: 'NOT GIVEN',
                'amount' => round((float) $b->total_amount - (float) data_get($b->metadata, 'tcs_amount', 0), 2),
                'tcs_rate' => (float) data_get($b->metadata, 'tcs_rate', 0), 'tcs' => round((float) data_get($b->metadata, 'tcs_amount', 0), 2),
            ])->values();

        return $this->respond($request, "tcs-{$label}", $rows, $this->sum($rows, ['amount', 'tcs']));
    }

    /** TDS deducted from agent commission, for the quarterly 26Q return. */
    public function tds(Request $request)
    {
        abort_unless($request->user()->isPlatformStaff(), 403);
        [$from, $to, $label] = $this->quarter($request);
        $rows = CommissionCredit::query()->whereBetween('created_at', [$from, $to])->get()->map(function (CommissionCredit $c) {
            $b = Booking::withoutTenantScope()->with('tenant:id,name,pan')->find($c->booking_id);

            return [
                'date' => Carbon::parse($c->created_at)->toDateString(), 'agent' => $b?->tenant?->name, 'pan' => $c->pan ?: ($b?->tenant?->pan ?: 'NOT GIVEN'),
                'booking' => $b?->booking_code ?? $b?->booking_reference, 'commission' => round((float) ($c->gross_amount ?? $c->amount), 2),
                'tds_rate' => (float) $c->tds_rate, 'tds' => round((float) $c->tds_amount, 2), 'paid_to_wallet' => round((float) $c->amount, 2),
            ];
        });

        return $this->respond($request, "tds-{$label}", $rows, $this->sum($rows, ['commission', 'tds', 'paid_to_wallet']));
    }

    /* ---------- helpers ---------- */

    protected function filters(Request $request): array
    {
        $d = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'module' => ['nullable', 'string'],
            'tenant_id' => ['nullable', 'integer'], 'format' => ['nullable', 'in:json,csv'],
        ]);

        return [
            'from' => isset($d['from']) ? Carbon::parse($d['from'])->startOfDay() : now()->startOfMonth(),
            'to' => isset($d['to']) ? Carbon::parse($d['to'])->endOfDay() : now()->endOfDay(),
            'module' => $d['module'] ?? null, 'tenant_id' => $d['tenant_id'] ?? null,
        ];
    }

    protected function bookings(User $user, array $f)
    {
        $q = Booking::withoutTenantScope()->whereBetween('created_at', [$f['from'], $f['to']])->whereNotIn('status', [Booking::STATUS_CART]);
        if ($f['module']) {
            $q->where('module', $f['module']);
        }
        $scope = null;
        if ($f['tenant_id']) {
            $t = Tenant::query()->findOrFail($f['tenant_id']);
            $scope = $this->tenants->getDescendantIdsRobust($t);
        }
        if (! $user->isPlatformStaff()) {
            $own = $this->tenants->getDescendantIdsRobust($user->tenant);
            abort_if($scope && array_diff($scope, $own), 403, 'That account is outside your own.');
            $scope ??= $own;
        }

        return $scope ? $q->whereIn('tenant_id', $scope) : $q;
    }

    protected function partnerName(?Tenant $t): ?string
    {
        for ($i = 0; $t && $i < 10; $i++) {
            if ($t->type === Tenant::TYPE_WHITE_LABEL || $t->type === Tenant::TYPE_MASTER) {
                return $t->name;
            }
            $t = $t->parent_tenant_id ? Tenant::query()->find($t->parent_tenant_id) : null;
        }

        return null;
    }

    protected function quarter(Request $request): array
    {
        $d = $request->validate(['financial_year' => ['nullable', 'regex:/^\d{4}-\d{2}$/'], 'quarter' => ['nullable', 'integer', 'between:1,4']]);
        $fy = $d['financial_year'] ?? TaxService::financialYear(now());
        $q = (int) ($d['quarter'] ?? (int) ceil(((now()->month + 8) % 12 + 1) / 3));
        $start = Carbon::create((int) substr($fy, 0, 4), 4, 1)->addMonths(($q - 1) * 3)->startOfDay();

        return [$start, $start->copy()->addMonths(3)->subSecond(), "{$fy}-Q{$q}"];
    }

    protected function sum(Collection $rows, array $keys): array
    {
        return collect($keys)->mapWithKeys(fn ($k) => [$k => round((float) $rows->sum($k), 2)])->all();
    }

    protected function respond(Request $request, string $name, Collection $rows, array $totals, ?string $note = null)
    {
        if ($request->query('format') === 'csv') {
            return $this->csv("{$name}.csv", $rows, $totals);
        }

        return response()->json(['data' => $rows->values(), 'totals' => $totals, 'meta' => array_filter(['note' => $note])]);
    }

    protected function csv(string $file, Collection $rows, ?array $totals = null)
    {
        $out = fopen('php://temp', 'r+');
        $first = $rows->first();
        if ($first) {
            fputcsv($out, array_map(fn ($k) => ucfirst(str_replace('_', ' ', $k)), array_keys($first)));
            foreach ($rows as $r) {
                fputcsv($out, array_map(fn ($v) => is_array($v) ? json_encode($v) : $v, array_values($r)));
            }
            if ($totals) {
                fputcsv($out, array_map(fn ($k) => $k === array_key_first($first) ? 'Total' : ($totals[$k] ?? ''), array_keys($first)));
            }
        }
        rewind($out);

        return response(stream_get_contents($out), 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => "attachment; filename=\"{$file}\""]);
    }
}
