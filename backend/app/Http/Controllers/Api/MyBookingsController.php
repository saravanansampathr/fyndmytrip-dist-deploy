<?php

namespace App\Http\Controllers\Api;

use App\Domains\Booking\Models\Booking;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantService;
use App\Http\Controllers\Concerns\FiltersByDateRangePreset;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * "One booking history view... reused by every role. What changes per role
 * is scope, not layout" (Platform Roadmap Phase 4) — fixes "when we click
 * my bookings then its taking to flight search page only, why so? where is
 * the booking history then?": DashboardPage's "My Bookings" tile pointed
 * at /flights (the search page) because there was no real booking-history
 * view yet.
 *
 * Scope, per role, mirrors ReportsController/SupportTicketController's
 * resolveTenantIds() pattern: an individual end customer (role 'customer')
 * always sees only bookings THEY personally created, never their tenant's
 * other customers' — tenant-hierarchy scoping is for the roles that
 * actually manage a tenant (agent/sub_agent/partner_admin/staff_user), who
 * see their own tenant plus whatever sits below it (an agent's own
 * bookings plus their direct customers'; a partner_admin's whole
 * hierarchy); platform staff (super_admin/staff) see every tenant unless
 * they narrow with ?tenant_id=.
 *
 * Also carries the standard list-view filter set — status/module/source,
 * global search, date-range preset, serial no/total/pagination — per the
 * "filters in each module and each report" backlog request, and a
 * per-module travel-date/duration line (hotel nights, holiday days,
 * flight/bus departure) since "each product own different" duration shape
 * doesn't fit one column.
 */
class MyBookingsController extends Controller
{
    use FiltersByDateRangePreset;

    public function __construct(protected TenantService $tenantService)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();

        [$from, $to] = $this->resolveDateRangePreset($request, default: 'custom');

        $baseQuery = Booking::withoutTenantScope();

        if ($user->role === User::ROLE_CUSTOMER) {
            $baseQuery->where('created_by_user_id', $user->id);
        } else {
            $tenantIds = $this->resolveTenantIds($request, $user);
            $baseQuery->when($tenantIds !== null, fn ($q) => $q->whereIn('tenant_id', $tenantIds));
        }

        // "custom" with neither from nor to given means "no date filter at
        // all" here (unlike Reports, which always wants a bounded range) —
        // a customer's own booking history should show everything by
        // default, only narrowing by date when they actually ask to.
        $dateFilterActive = $request->filled('range') || $request->filled('from') || $request->filled('to');
        if ($dateFilterActive) {
            $baseQuery->whereBetween('created_at', [$from, $to]);
        }

        $availableSources = (clone $baseQuery)
            ->whereNotNull('supplier_code')
            ->distinct()
            ->orderBy('supplier_code')
            ->pluck('supplier_code')
            ->values();

        $query = $baseQuery
            ->when($request->filled('module'), fn ($q) => $q->where('module', $request->string('module')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('source'), fn ($q) => $q->where('supplier_code', $request->string('source')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('booking_code', 'like', $term)
                        ->orWhere('booking_reference', 'like', $term)
                        ->orWhere('contact_email', 'like', $term)
                        ->orWhere('contact_phone', 'like', $term);
                });
            })
            ->orderByDesc('created_at');

        $bookings = $query->paginate(min($request->integer('per_page', 25), 500));

        $bookings->getCollection()->transform(function (Booking $booking) {
            $total = $booking->total_amount && (float) $booking->total_amount > 0
                ? $booking->total_amount
                : $booking->recalculateTotal();

            return [
                'id' => $booking->id,
                'module' => $booking->module,
                'booking_code' => $booking->booking_code,
                'booking_reference' => $booking->booking_reference,
                'status' => $booking->status,
                'source' => $booking->supplier_code,
                'total_amount' => (string) $total,
                'currency' => $booking->currency,
                'travel_date_start' => $booking->travel_date_start?->toDateString(),
                'travel_date_end' => $booking->travel_date_end?->toDateString(),
                // Booking date+time is always just when the row was
                // created — one shape for every module.
                'booking_date' => $booking->created_at?->toDateString(),
                'booking_time' => $booking->created_at?->format('H:i'),
                'created_at' => $booking->created_at,
                // Travel date/time and "duration" mean something different
                // per module (a hotel's nights, a holiday's days, a
                // flight/bus's one-way departure) — computed once here so
                // the frontend doesn't need per-module branching.
                'travel_summary' => $this->travelSummaryFor($booking),
                'summary' => $this->summaryFor($booking),
                'pdf_url' => $this->pdfUrlFor($booking),
                'invoice_url' => $this->invoiceUrlFor($booking),
            ];
        });

        $response = $bookings->toArray();
        $response['available_sources'] = $availableSources;

        return response()->json($response);
    }

    /** A one-line, module-appropriate description for the list row. */
    protected function summaryFor(Booking $booking): string
    {
        $meta = $booking->metadata ?? [];

        return match ($booking->module) {
            Booking::MODULE_FLIGHT => 'Flight booking',
            Booking::MODULE_HOLIDAY => $meta['package_name'] ?? ($meta['route_summary'] ?? 'Holiday package'),
            Booking::MODULE_HOTEL => trim(($meta['hotel_name'] ?? 'Hotel').(($meta['city'] ?? null) ? ' · '.$meta['city'] : '')),
            Booking::MODULE_BUS => trim(($meta['origin'] ?? '—').' → '.($meta['destination'] ?? '—')),
            Booking::MODULE_CAB => trim(($meta['provider_name'] ?? 'Cab').' · '.($meta['origin'] ?? '—').' → '.($meta['destination'] ?? '—')),
            Booking::MODULE_INSURANCE => trim(($meta['plan_name'] ?? 'Insurance plan').(($meta['destination'] ?? null) ? ' · '.$meta['destination'] : '')),
            Booking::MODULE_CRUISE => trim(($meta['cruise_line'] ?? 'Cruise').' · '.($meta['departure_port'] ?? '—').' → '.($meta['arrival_port'] ?? '—')),
            Booking::MODULE_ACTIVITY => trim(($meta['category'] ?? 'Activity').(($meta['city'] ?? null) ? ' · '.$meta['city'] : '')),
            Booking::MODULE_VISA => trim(($meta['visa_type'] ?? 'Visa').(($meta['destination_country_name'] ?? null) ? ' · '.$meta['destination_country_name'] : '')),
            default => ucfirst($booking->module),
        };
    }

    /**
     * Travel date/duration, shaped per module: a hotel or holiday booking
     * is a *stay* (check-in → check-out, N nights/days); a flight or bus
     * booking is a one-way *departure* (a single date+time), and a
     * round-trip flight also has a return date. Metadata already carries
     * a precomputed 'nights' for hotel bookings (see HotelBookingController)
     * — reused here rather than recomputed.
     */
    protected function travelSummaryFor(Booking $booking): array
    {
        $meta = $booking->metadata ?? [];
        $start = $booking->travel_date_start;
        $end = $booking->travel_date_end;

        return match ($booking->module) {
            Booking::MODULE_HOTEL => [
                'kind' => 'stay',
                'check_in' => $start?->toDateString(),
                'check_out' => $end?->toDateString(),
                'nights' => $meta['nights'] ?? ($start && $end ? $start->diffInDays($end) : null),
            ],
            Booking::MODULE_HOLIDAY => [
                'kind' => 'stay',
                'check_in' => $start?->toDateString(),
                'check_out' => $end?->toDateString(),
                'nights' => $meta['duration_nights'] ?? ($start && $end ? $start->diffInDays($end) : null),
            ],
            Booking::MODULE_FLIGHT => [
                'kind' => 'departure',
                'departure_date' => $start?->toDateString(),
                'return_date' => $end?->toDateString(),
            ],
            Booking::MODULE_BUS => [
                'kind' => 'departure',
                'departure_date' => $start?->toDateString(),
                'departure_time' => $meta['departure_time'] ?? null,
            ],
            Booking::MODULE_CAB => [
                'kind' => 'departure',
                'departure_date' => $start?->toDateString(),
                'departure_time' => $meta['pickup_time'] ?? null,
            ],
            // Insurance is date-agnostic (Platform Roadmap Phase 3 note):
            // there's no fixed departure, just the trip window the policy
            // covers plus how many days its term itself runs for — a
            // distinct 'coverage' shape rather than forcing it into
            // 'stay' (nights) or 'departure' (a single date).
            Booking::MODULE_INSURANCE => [
                'kind' => 'coverage',
                'check_in' => $start?->toDateString(),
                'check_out' => $end?->toDateString(),
                'days' => $meta['policy_term_days'] ?? null,
            ],
            Booking::MODULE_CRUISE => [
                'kind' => 'stay',
                'check_in' => $start?->toDateString(),
                'check_out' => $end?->toDateString(),
                'nights' => $meta['nights'] ?? ($start && $end ? $start->diffInDays($end) : null),
            ],
            Booking::MODULE_ACTIVITY => [
                'kind' => 'departure',
                'departure_date' => $start?->toDateString(),
            ],
            default => [
                'kind' => 'departure',
                'departure_date' => $start?->toDateString(),
            ],
        };
    }

    /** Which downloadable PDF (if any) applies to this booking's module. */
    protected function pdfUrlFor(Booking $booking): ?string
    {
        $meta = $booking->metadata ?? [];

        // No ticket/voucher for a booking that hasn't been paid for (the
        // package itinerary is a public brochure, so it stays available).
        if ($booking->isAwaitingPayment() && $booking->module !== Booking::MODULE_HOLIDAY) {
            return null;
        }

        return match ($booking->module) {
            Booking::MODULE_FLIGHT => "/flights/bookings/{$booking->id}/ticket.pdf",
            Booking::MODULE_HOLIDAY => isset($meta['package_slug'])
                ? "/packages/{$meta['package_slug']}/itinerary.pdf"
                : null,
            Booking::MODULE_HOTEL => "/hotels/bookings/{$booking->id}/voucher.pdf",
            Booking::MODULE_BUS => "/bus/bookings/{$booking->id}/ticket.pdf",
            Booking::MODULE_CAB => "/cabs/bookings/{$booking->id}/voucher.pdf",
            Booking::MODULE_INSURANCE => "/insurance/bookings/{$booking->id}/policy.pdf",
            Booking::MODULE_CRUISE => "/cruises/bookings/{$booking->id}/ticket.pdf",
            Booking::MODULE_ACTIVITY => "/activities/bookings/{$booking->id}/voucher.pdf",
            default => null,
        };
    }

    /**
     * Phase 7 — "every ticketed booking generates an invoice... visible
     * under that user's own login" (see InvoiceController). Checked
     * against current status only, not the full status history, to avoid
     * an extra query per row in this list; InvoiceController itself does
     * the more thorough statusHistory check when the link is actually
     * opened, so a booking ticketed and later cancelled by an unusual
     * path may simply be missing the link here without being wrong.
     */
    protected function invoiceUrlFor(Booking $booking): ?string
    {
        $eligible = [Booking::STATUS_TICKETED, Booking::STATUS_REFUND_PROCESSING, Booking::STATUS_REFUNDED];

        // Holiday packages and visas are fulfilled by the ops team, so a
        // paid one stays 'confirmed' — it still gets its invoice.
        if (in_array($booking->module, InvoiceController::CONFIRM_ONLY_MODULES, true)) {
            $eligible[] = Booking::STATUS_CONFIRMED;
        }

        return in_array($booking->status, $eligible, true) ? "/bookings/{$booking->id}/invoice.pdf" : null;
    }

    /**
     * Mirrors ReportsController::resolveTenantIds() — platform staff see
     * every tenant unless they narrow with ?tenant_id=; everyone else
     * (agent/sub_agent/partner_admin/staff_user — the roles that manage a
     * tenant rather than just buying from one) sees their own tenant plus
     * whatever sits below it in the hierarchy. Never called for role
     * 'customer', which index() always scopes to created_by_user_id
     * instead — an individual buyer, not a tenant manager.
     *
     * @return array<int>|null
     */
    protected function resolveTenantIds(Request $request, User $user): ?array
    {
        $requestedTenantId = $request->filled('tenant_id') ? $request->integer('tenant_id') : null;

        if ($user->isPlatformStaff()) {
            if ($requestedTenantId === null) {
                return null;
            }

            $tenant = Tenant::query()->findOrFail($requestedTenantId);

            return $this->tenantService->getDescendantIdsRobust($tenant);
        }

        $ownTenant = Tenant::query()->findOrFail($user->tenant_id);

        return $this->tenantService->getDescendantIdsRobust($ownTenant);
    }
}
