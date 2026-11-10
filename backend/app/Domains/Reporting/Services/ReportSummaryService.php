<?php

namespace App\Domains\Reporting\Services;

use App\Domains\Booking\Models\Booking;
use App\Domains\Payments\Models\PaymentTransaction;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The Overview report's aggregation query, extracted out of
 * ReportsController::resolveSummaryData() so it has exactly one caller-
 * independent home: the HTTP controller (summary()/streamSummaryPdf()/
 * streamSummaryXlsx()) and the scheduled-report console command
 * (SendScheduledReports, which has no Request to resolve a date range or
 * tenant scope from — just a tenant_id and a frequency) both need this
 * exact computation, and a second hand-copied version of ~150 lines of
 * SUM()/GROUP BY SQL is exactly how this codebase's one broken, half-
 * finished duplicate of this same method ended up shipped earlier —
 * never again on purpose.
 */
class ReportSummaryService
{
    /**
     * @param  array<int>|null  $tenantIds  null means platform-wide (no tenant filter at all).
     */
    public function summarize(?array $tenantIds, Carbon $from, Carbon $to): array
    {
        $bookingsQuery = $this->scopedBookings($tenantIds)
            ->whereBetween('created_at', [$from, $to]);

        $totals = (clone $bookingsQuery)
            ->selectRaw('
                COUNT(*) as bookings_count,
                COALESCE(SUM(gross_amount), 0) as gross_amount,
                COALESCE(SUM(total_amount), 0) as total_amount,
                COALESCE(SUM(markup_amount), 0) as markup_amount,
                COALESCE(SUM(commission_amount), 0) as commission_amount,
                COALESCE(SUM(discount_amount), 0) as discount_amount,
                COALESCE(SUM(supplier_net_amount), 0) as supplier_net_amount
            ')
            ->first();

        // Accounting/P&L — Revenue is total_amount (what the customer actually
        // paid, after markup and discount); COGS is supplier_net_amount (what
        // went out to the supplier); gross margin is markup_amount. Net profit
        // extends the existing by-channel profit_amount formula (markup minus
        // commission, below) to also subtract discount_amount, since a coupon
        // comes straight out of the platform's own margin, not the supplier's
        // net — same reasoning Booking::recalculateTotal()'s docblock gives
        // for building total_amount up from gross_amount + extras - discount.
        $netProfitAmount = BigDecimal::of((string) $totals->markup_amount)
            ->minus((string) $totals->commission_amount)
            ->minus((string) $totals->discount_amount)
            ->toScale(2, RoundingMode::HALF_UP);

        $profitMarginPct = ((float) $totals->total_amount) > 0
            ? number_format(((float) (string) $netProfitAmount) / ((float) $totals->total_amount) * 100, 2, '.', '')
            : '0.00';

        $bookingsCount = (int) $totals->bookings_count;

        $byStatus = (clone $bookingsQuery)
            ->select('status', DB::raw('COUNT(*) as count'), DB::raw('COALESCE(SUM(total_amount), 0) as total_amount'))
            ->groupBy('status')
            ->get();

        $byModule = (clone $bookingsQuery)
            ->select('module', DB::raw('COUNT(*) as count'), DB::raw('COALESCE(SUM(total_amount), 0) as total_amount'))
            ->groupBy('module')
            ->get();

        // Platform Roadmap Phase 7 — "Revenue and profit, split by channel...
        // needs to break out revenue and profit by B2B, agent, sub-agent,
        // API, whitelabel, and direct customer, so it's clear which channel
        // is actually profitable versus just high-volume." markup_amount is
        // what the platform charges over net fare on a booking; commission_amount
        // (real since CommissionService started crediting it — see Phase 7's
        // commission tracking bullet) is what gets paid back out to the
        // channel that sold it. profit_amount = markup - commission: what the
        // platform actually keeps from that channel's business, as opposed to
        // total_amount, which is gross revenue and says nothing about margin.
        $byChannel = (clone $bookingsQuery)
            ->select(
                'channel',
                DB::raw('COUNT(*) as count'),
                DB::raw('COALESCE(SUM(total_amount), 0) as total_amount'),
                DB::raw('COALESCE(SUM(markup_amount), 0) as markup_amount'),
                DB::raw('COALESCE(SUM(commission_amount), 0) as commission_amount'),
            )
            ->groupBy('channel')
            ->get();

        $trend = (clone $bookingsQuery)
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'), DB::raw('COALESCE(SUM(total_amount), 0) as total_amount'))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();

        // Top tenants only makes sense once there is more than one tenant in
        // scope — a lone agent looking at their own report doesn't need a
        // "top tenants" breakdown of themselves.
        $topTenants = [];

        if ($tenantIds === null || count($tenantIds) > 1) {
            $rows = (clone $bookingsQuery)
                ->select('tenant_id', DB::raw('COUNT(*) as count'), DB::raw('COALESCE(SUM(total_amount), 0) as total_amount'))
                ->groupBy('tenant_id')
                ->orderByDesc(DB::raw('COALESCE(SUM(total_amount), 0)'))
                ->limit(10)
                ->get();

            $tenantNames = Tenant::query()->whereIn('id', $rows->pluck('tenant_id'))->pluck('name', 'id');

            $topTenants = $rows->map(fn ($row) => [
                'tenant_id' => $row->tenant_id,
                'tenant_name' => $tenantNames[$row->tenant_id] ?? "Tenant #{$row->tenant_id}",
                'bookings_count' => (int) $row->count,
                'total_amount' => (string) $row->total_amount,
            ])->values();
        }

        // "need to differentiate... guest or direct bookings" — CHANNEL_DIRECT
        // covers both a logged-in customer and a guest checkout; this splits
        // that one channel the same way created_by_user_id already does for
        // the bookings-list "Created by" filter (NULL means no account was
        // ever attached to the booking, i.e. a guest checkout).
        $directSplit = (clone $bookingsQuery)
            ->where('channel', Booking::CHANNEL_DIRECT)
            ->selectRaw("CASE WHEN created_by_user_id IS NULL THEN 'guest' ELSE 'customer' END as audience")
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('COALESCE(SUM(total_amount), 0) as total_amount')
            ->groupBy('audience')
            ->get();

        // "...or API, partner..." — by_channel above already separates
        // api_partner from agent/white_label/direct, but lumps every API
        // Partner tenant into one bucket. This breaks that one channel back
        // out by tenant_id, the same shape top_tenants uses, so "which
        // specific partner" is answerable without a new column: every
        // booking already carries the tenant_id of the partner that made it.
        $apiPartnerRows = (clone $bookingsQuery)
            ->where('channel', Booking::CHANNEL_API_PARTNER)
            ->select('tenant_id', DB::raw('COUNT(*) as count'), DB::raw('COALESCE(SUM(total_amount), 0) as total_amount'))
            ->groupBy('tenant_id')
            ->orderByDesc(DB::raw('COALESCE(SUM(total_amount), 0)'))
            ->get();

        $apiPartnerNames = Tenant::query()->whereIn('id', $apiPartnerRows->pluck('tenant_id'))->pluck('name', 'id');

        $apiPartners = $apiPartnerRows->map(fn ($row) => [
            'tenant_id' => $row->tenant_id,
            'tenant_name' => $apiPartnerNames[$row->tenant_id] ?? "Partner #{$row->tenant_id}",
            'bookings_count' => (int) $row->count,
            'total_amount' => (string) $row->total_amount,
        ])->values();

        $paymentsQuery = $this->scopedPayments($tenantIds)
            ->whereBetween('created_at', [$from, $to]);

        $paymentTotals = (clone $paymentsQuery)
            ->select('status', DB::raw('COUNT(*) as count'), DB::raw('COALESCE(SUM(amount), 0) as amount'))
            ->groupBy('status')
            ->get();

        $paymentsByGateway = (clone $paymentsQuery)
            ->where('status', PaymentTransaction::STATUS_SUCCESS)
            ->select('gateway', DB::raw('COUNT(*) as count'), DB::raw('COALESCE(SUM(amount), 0) as amount'))
            ->groupBy('gateway')
            ->orderByDesc(DB::raw('COALESCE(SUM(amount), 0)'))
            ->get();

        // Dashboard's "revenue, bookings and users" analytics — same
        // tenant-scoping shape as everything else above, but over `users`
        // instead of `bookings`/`payment_transactions`. User doesn't use
        // the BelongsToTenant global scope (it's staff/agent/customer
        // accounts, not a per-tenant business record), so this filters by
        // tenant_id directly rather than needing withoutTenantScope().
        $usersQuery = User::query()->when($tenantIds !== null, fn ($q) => $q->whereIn('tenant_id', $tenantIds));

        $usersTotalCount = (clone $usersQuery)->count();

        $newUsersQuery = (clone $usersQuery)->whereBetween('created_at', [$from, $to]);

        $newUsersCount = (clone $newUsersQuery)->count();

        $usersTrend = (clone $newUsersQuery)
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();

        $usersByRole = (clone $usersQuery)
            ->select('role', DB::raw('COUNT(*) as count'))
            ->groupBy('role')
            ->get();

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => [
                'bookings_count' => $bookingsCount,
                'gross_amount' => (string) $totals->gross_amount,
                'total_amount' => (string) $totals->total_amount,
                'markup_amount' => (string) $totals->markup_amount,
                'commission_amount' => (string) $totals->commission_amount,
                'discount_amount' => (string) $totals->discount_amount,
                'supplier_net_amount' => (string) $totals->supplier_net_amount,
                'net_profit_amount' => (string) $netProfitAmount,
                'profit_margin_pct' => $profitMarginPct,
                'avg_booking_value' => $bookingsCount > 0
                    ? number_format(((float) $totals->total_amount) / $bookingsCount, 2, '.', '')
                    : '0.00',
            ],
            'by_status' => $byStatus->map(fn ($r) => [
                'status' => $r->status, 'count' => (int) $r->count, 'total_amount' => (string) $r->total_amount,
            ]),
            'by_module' => $byModule->map(fn ($r) => [
                'module' => $r->module, 'count' => (int) $r->count, 'total_amount' => (string) $r->total_amount,
            ]),
            'by_channel' => $byChannel->map(fn ($r) => [
                'channel' => $r->channel,
                'count' => (int) $r->count,
                'total_amount' => (string) $r->total_amount,
                'markup_amount' => (string) $r->markup_amount,
                'commission_amount' => (string) $r->commission_amount,
                'profit_amount' => (string) BigDecimal::of((string) $r->markup_amount)
                    ->minus((string) $r->commission_amount)
                    ->toScale(2, RoundingMode::HALF_UP),
            ]),
            'trend' => $trend->map(fn ($r) => [
                'date' => $r->date, 'count' => (int) $r->count, 'total_amount' => (string) $r->total_amount,
            ]),
            'top_tenants' => $topTenants,
            'direct_split' => $directSplit->map(fn ($r) => [
                'audience' => $r->audience, 'count' => (int) $r->count, 'total_amount' => (string) $r->total_amount,
            ])->values(),
            'api_partners' => $apiPartners,
            'payments' => [
                'by_status' => $paymentTotals->map(fn ($r) => [
                    'status' => $r->status, 'count' => (int) $r->count, 'amount' => (string) $r->amount,
                ]),
                'by_gateway' => $paymentsByGateway->map(fn ($r) => [
                    'gateway' => $r->gateway, 'count' => (int) $r->count, 'amount' => (string) $r->amount,
                ]),
            ],
            'users' => [
                'total_count' => $usersTotalCount,
                'new_in_range' => $newUsersCount,
                'trend' => $usersTrend->map(fn ($r) => ['date' => $r->date, 'count' => (int) $r->count]),
                'by_role' => $usersByRole->map(fn ($r) => ['role' => $r->role, 'count' => (int) $r->count]),
            ],
            'scope' => [
                'is_platform_wide' => $tenantIds === null,
                'tenant_count' => $tenantIds === null ? null : count($tenantIds),
            ],
        ];
    }

    /**
     * The same summary data as a set of named .xlsx sheets (see
     * App\Support\XlsxWriter) — one sheet per table the PDF/UI already
     * show. Shared by ReportsController::streamSummaryXlsx() (an HTTP
     * download) and SendScheduledReports (an email attachment) so the two
     * never drift into two different ideas of what the Excel export looks
     * like.
     *
     * @return array<int, array{name: string, rows: iterable<int, array<int, mixed>>}>
     */
    public function xlsxSheets(array $data): array
    {
        $moduleLabels = [
            'flight' => 'Flights', 'hotel' => 'Hotels', 'bus' => 'Bus', 'cab' => 'Cabs',
            'holiday' => 'Holiday packages', 'insurance' => 'Insurance', 'visa' => 'Visa',
            'cruise' => 'Cruise', 'activity' => 'Activities',
        ];
        $channelLabels = [
            'direct' => 'Direct', 'agent' => 'Agent', 'sub_agent' => 'Sub-agent',
            'white_label' => 'White-label', 'api_partner' => 'API partner',
        ];

        return [
            [
                'name' => 'Totals',
                'rows' => [
                    ['Metric', 'Value'],
                    ['Range from', $data['range']['from']],
                    ['Range to', $data['range']['to']],
                    ['Bookings', (int) $data['totals']['bookings_count']],
                    ['Gross bookings value', (float) $data['totals']['gross_amount']],
                    ['Total collected', (float) $data['totals']['total_amount']],
                    ['Markup', (float) $data['totals']['markup_amount']],
                    ['Commission paid', (float) $data['totals']['commission_amount']],
                    ['Discounts', (float) $data['totals']['discount_amount']],
                    ['Net profit', (float) $data['totals']['net_profit_amount']],
                    ['Profit margin %', (float) $data['totals']['profit_margin_pct']],
                    ['Avg booking value', (float) $data['totals']['avg_booking_value']],
                ],
            ],
            [
                'name' => 'By module',
                'rows' => (function () use ($data, $moduleLabels) {
                    yield ['Module', 'Bookings', 'Total amount'];
                    foreach ($data['by_module'] as $row) {
                        yield [$moduleLabels[$row['module']] ?? $row['module'], (int) $row['count'], (float) $row['total_amount']];
                    }
                })(),
            ],
            [
                'name' => 'By channel',
                'rows' => (function () use ($data, $channelLabels) {
                    yield ['Channel', 'Bookings', 'Revenue', 'Markup', 'Commission paid', 'Profit'];
                    foreach ($data['by_channel'] as $row) {
                        yield [
                            $channelLabels[$row['channel']] ?? ($row['channel'] ?? 'Unknown'),
                            (int) $row['count'],
                            (float) $row['total_amount'],
                            (float) $row['markup_amount'],
                            (float) $row['commission_amount'],
                            (float) $row['profit_amount'],
                        ];
                    }
                })(),
            ],
            [
                'name' => 'Direct — guest vs customer',
                'rows' => (function () use ($data) {
                    yield ['Audience', 'Bookings', 'Total amount'];
                    foreach ($data['direct_split'] as $row) {
                        yield [ucfirst($row['audience']), (int) $row['count'], (float) $row['total_amount']];
                    }
                })(),
            ],
            [
                'name' => 'API partners',
                'rows' => (function () use ($data) {
                    yield ['Partner', 'Bookings', 'Total amount'];
                    foreach ($data['api_partners'] as $row) {
                        yield [$row['tenant_name'], (int) $row['bookings_count'], (float) $row['total_amount']];
                    }
                })(),
            ],
            [
                'name' => 'Top tenants',
                'rows' => (function () use ($data) {
                    yield ['Tenant', 'Bookings', 'Total amount'];
                    foreach ($data['top_tenants'] as $row) {
                        yield [$row['tenant_name'], (int) $row['bookings_count'], (float) $row['total_amount']];
                    }
                })(),
            ],
            [
                'name' => 'Payments by status',
                'rows' => (function () use ($data) {
                    yield ['Status', 'Count', 'Amount'];
                    foreach ($data['payments']['by_status'] as $row) {
                        yield [ucfirst($row['status']), (int) $row['count'], (float) $row['amount']];
                    }
                })(),
            ],
        ];
    }

    protected function scopedBookings(?array $tenantIds)
    {
        $query = Booking::withoutTenantScope();

        if ($tenantIds !== null) {
            $query->whereIn('tenant_id', $tenantIds);
        }

        return $query;
    }

    protected function scopedPayments(?array $tenantIds)
    {
        $query = PaymentTransaction::withoutTenantScope();

        if ($tenantIds !== null) {
            $query->whereIn('tenant_id', $tenantIds);
        }

        return $query;
    }
}
