<?php

namespace App\Http\Controllers\Api;

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\BookingAmendment;
use App\Domains\Booking\Models\RefundTransaction;
use App\Domains\Booking\Services\BookingAmendmentService;
use App\Domains\Booking\Services\RefundService;
use App\Domains\Commission\Models\CommissionCredit;
use App\Domains\Payments\Models\PaymentTransaction;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantService;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Models\WalletLedgerEntry;
use App\Http\Controllers\Concerns\FiltersByDateRangePreset;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\User;
use App\Support\PdfBranding;
use App\Support\XlsxWriter;
use Barryvdh\DomPDF\Facade\Pdf;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The backlog's "Phase 2: booking history, payment tracking, and
 * analytics/reports dashboard" — a read-only cross-cutting view over the
 * single generic Booking table (see Booking's own docblock: one schema
 * serves all eight Product Modules) and PaymentTransaction, scoped the same
 * way TenantUserController scopes tenant-user management: platform staff
 * see every tenant, everyone else sees their own tenant plus whatever sits
 * below it in the hierarchy (their agents'/sub-agents' bookings included),
 * using the same closure-table-or-parent-chain robustness because seeded
 * demo tenants have no closure rows.
 */
class ReportsController extends Controller
{
    use FiltersByDateRangePreset;

    public function __construct(
        protected TenantService $tenantService,
        protected RefundService $refundService,
        protected BookingAmendmentService $amendmentService,
        protected \App\Domains\Reporting\Services\ReportSummaryService $reportSummaryService,
    ) {}

    /**
     * "Anything missed... reporting in export in excel or pdf... product
     * wise or API, partner, agent, Whitelable or guest or direct bookings"
     * — format=pdf streams this same data as a downloadable PDF (reusing
     * the already-installed barryvdh/laravel-dompdf the booking-voucher
     * PDFs use — no new dependency, nothing new for the live server's
     * vendor/ to need) instead of returning JSON. Kept on this one
     * endpoint with a format switch, matching the exact idiom
     * bookings()/payments() already use for format=csv, rather than a
     * separate route.
     */
    public function summary(Request $request)
    {
        $user = $request->user();
        $this->authorizeView($user);

        $data = $this->resolveSummaryData($request, $user);

        $format = $request->string('format')->toString();

        if ($format === 'pdf') {
            return $this->streamSummaryPdf($data);
        }

        if ($format === 'xlsx') {
            return $this->streamSummaryXlsx($data);
        }

        return response()->json(['data' => $data]);
    }

    /**
     * The actual summary computation, extracted so summary() (JSON) and
     * streamSummaryPdf() (the same data rendered as a PDF) never risk
     * drifting out of sync with two copies of this query logic.
     */
    protected function resolveSummaryData(Request $request, User $user): array
    {
        [$from, $to] = $this->resolveDateRangePreset($request);
        $tenantIds = $this->resolveTenantIds($request, $user);

        return $this->reportSummaryService->summarize($tenantIds, $from, $to);
    }

    /**
     * "reporting in export in excel or pdf" — the summary/overview report as
     * a downloadable PDF, reusing the already-installed barryvdh/laravel-
     * dompdf the booking-voucher/invoice PDFs already use (InvoiceController,
     * resources/views/pdf/invoice.blade.php) rather than a new dependency.
     * Row-level CSV export already exists for Bookings/Payments
     * (streamBookingsCsv/streamPaymentsCsv below) — this is the counterpart
     * for the aggregated Overview tab, which had no export of its own at all.
     */
    protected function streamSummaryPdf(array $data)
    {
        $pdf = Pdf::loadView('pdf.reports-summary', [
            'data' => $data,
            'logo' => PdfBranding::logoDataUri(),
            'generatedAt' => now(),
        ])->setPaper('a4', 'portrait');

        $filename = 'report-summary-'.$data['range']['from'].'-to-'.$data['range']['to'].'.pdf';

        return $pdf->download($filename);
    }

    /**
     * Same aggregated Overview data as streamSummaryPdf(), as a real
     * multi-sheet .xlsx workbook instead — "reporting in export in excel
     * or pdf". One sheet per table the PDF/UI already shows, so a
     * finance user gets exactly the numbers they see on screen, but as
     * real Excel cells they can re-sum, re-sort, or drop into their own
     * model, not just a PDF they can only read.
     */
    protected function streamSummaryXlsx(array $data)
    {
        $filename = 'report-summary-'.$data['range']['from'].'-to-'.$data['range']['to'].'.xlsx';

        return XlsxWriter::download($filename, $this->reportSummaryService->xlsxSheets($data));
    }

    public function bookings(Request $request)
    {
        $user = $request->user();
        $this->authorizeView($user);

        [$from, $to] = $this->resolveDateRangePreset($request);
        $tenantIds = $this->resolveTenantIds($request, $user);

        $baseQuery = $this->scopedBookings($tenantIds)->whereBetween('created_at', [$from, $to]);

        // Distinct supplier codes actually present in this scope/date range
        // (before the source filter itself narrows further) — populates the
        // frontend's "Source" dropdown with real values instead of a
        // hardcoded list, since supplier_code varies by module and grows as
        // real supplier APIs get integrated (today: OWN_INVENTORY, MOCKAIR,
        // manual, akbar, tripjack...).
        $availableSources = (clone $baseQuery)
            ->whereNotNull('supplier_code')
            ->distinct()
            ->orderBy('supplier_code')
            ->pluck('supplier_code');

        $query = (clone $baseQuery)
            ->when($request->filled('module'), fn ($q) => $q->where('module', $request->string('module')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('channel'), fn ($q) => $q->where('channel', $request->string('channel')))
            ->when($request->filled('source'), fn ($q) => $q->where('supplier_code', $request->string('source')))
            ->when($request->filled('created_by_user_id'), fn ($q) => $q->where('created_by_user_id', $request->integer('created_by_user_id')))
            ->when($request->filled('user_search'), function ($q) use ($request) {
                $term = '%'.$request->string('user_search').'%';
                $q->whereHas('createdBy', function ($sub) use ($term) {
                    $sub->where('name', 'like', $term)->orWhere('email', 'like', $term);
                });
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('booking_code', 'like', $term)
                        ->orWhere('booking_reference', 'like', $term)
                        ->orWhere('contact_email', 'like', $term)
                        ->orWhere('contact_phone', 'like', $term);
                });
            })
            // Booking-ops roadmap's "manual review queue" — "sometimes
            // customers may book but it didn't go well on confirmation, or
            // no response from supplier": payment already went through
            // (status CONFIRMED) but the booking never reached TICKETED
            // (or FAILED) within a reasonable window, meaning the supplier
            // call that should have ticketed it hung, timed out, or threw
            // something FlightBookingController::finalizeTicketing() didn't
            // catch — money is captured but nothing has been decided since.
            // 15 minutes is generous next to how fast a real confirm() call
            // normally returns.
            ->when($request->boolean('stuck'), fn ($q) => $q->where('status', Booking::STATUS_CONFIRMED)->where('updated_at', '<', now()->subMinutes(15)))
            ->orderByDesc('created_at');

        $format = $request->string('format')->toString();

        if ($format === 'csv') {
            return $this->streamBookingsCsv((clone $query)->with(['tenant:id,name', 'createdBy:id,name,email']));
        }

        if ($format === 'xlsx') {
            return $this->streamBookingsXlsx((clone $query)->with(['tenant:id,name', 'createdBy:id,name,email']));
        }

        // per_page supports the frontend's 25/50/100/"View all" picker — "All"
        // sends a large number rather than a special value, capped here so a
        // stray request can't force an unbounded query.
        $perPage = min($request->integer('per_page', 25), 500);

        $bookings = $query->with(['tenant:id,name', 'createdBy:id,name,email'])->paginate($perPage);

        return BookingResource::collection($bookings)->additional([
            'meta' => ['available_sources' => $availableSources->values()],
        ]);
    }

    /**
     * Full detail for one booking — the "view details" drill-down from the
     * Reports bookings table. Same generic Booking table/resource serves
     * every module (see Booking's docblock), so this one endpoint covers
     * flights, holiday packages, visa, and anything else booked through it;
     * scoped to the same tenant hierarchy as the list endpoint so a report
     * viewer can only open a booking they could already see in the list.
     */
    public function bookingDetail(Request $request, int $booking)
    {
        $user = $request->user();
        $this->authorizeView($user);

        // Deliberately not using implicit route-model binding: Booking's
        // default global scope is tenant-scoped to the *acting* tenant, which
        // would 404 on a booking that belongs to another tenant within the
        // viewer's own hierarchy (exactly the cross-tenant case this report
        // exists for) — same reason scopedBookings() above bypasses it.
        $bookingModel = Booking::withoutTenantScope()->findOrFail($booking);

        $tenantIds = $this->resolveTenantIds($request, $user);
        abort_unless($tenantIds === null || in_array($bookingModel->tenant_id, $tenantIds, true), 403, 'That booking is outside your reporting scope.');

        $bookingModel->load(['tenant:id,name', 'createdBy:id,name,email', 'segments', 'passengers', 'ancillaries', 'statusHistory', 'events.actor:id,name', 'refundTransactions.initiatedBy:id,name', 'refundTransactions.completedBy:id,name', 'amendments.initiatedBy:id,name']);

        return (new BookingResource($bookingModel))->response();
    }

    /**
     * Adds a manual note to a booking's audit timeline — Phase 1 of the
     * booking-ops roadmap ("Booking Ops Foundation"). Gated behind
     * booking.manage rather than the looser booking.view, since this
     * writes to the booking (an operational note an agent/customer never
     * needs to see is still visible to every staff member with reporting
     * access to this booking). Same tenant-hierarchy scope check as every
     * other booking action here.
     */
    public function addBookingNote(Request $request, int $booking)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('booking.manage'), 403, 'Missing required permission: booking.manage');

        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $bookingModel = Booking::withoutTenantScope()->findOrFail($booking);

        $tenantIds = $this->resolveTenantIds($request, $user);
        abort_unless($tenantIds === null || in_array($bookingModel->tenant_id, $tenantIds, true), 403, 'That booking is outside your reporting scope.');

        \App\Domains\Booking\Models\BookingEvent::log(
            $bookingModel->id,
            \App\Domains\Booking\Models\BookingEvent::TYPE_MANUAL_NOTE,
            $data['message'],
            $user,
        );

        $bookingModel->load(['tenant:id,name', 'createdBy:id,name,email', 'segments', 'passengers', 'ancillaries', 'statusHistory', 'events.actor:id,name', 'refundTransactions.initiatedBy:id,name', 'refundTransactions.completedBy:id,name', 'amendments.initiatedBy:id,name']);

        return (new BookingResource($bookingModel))->response();
    }

    /**
     * Changes a booking's status from the Reports bookings table — "instead
     * give clickable elements in booking ID, inside need options to change
     * the status like initiate refund or cancel and abort". Three concrete
     * actions map onto BookingStatusService's existing state machine (the
     * "only permitted path to change bookings.status", never touched
     * directly here): Cancel → cancelled, Abort → failed (for a booking
     * that never got past held/confirmed), Initiate refund → refunded.
     * "Amend" (editing booking details rather than its status) isn't
     * covered — there's no data model for that yet, so it's deliberately
     * left out until that's scoped.
     *
     * Refunds move money back out, so they need the stricter
     * booking.refund permission; cancel/abort reuse the existing
     * booking.cancel permission already granted to staff/partner_admin/
     * agent. Same tenant-hierarchy scope check as bookingDetail() so a
     * report viewer can only change a booking they could already see.
     */
    public function updateBookingStatus(Request $request, int $booking)
    {
        $user = $request->user();

        // 'ticketed' is the manual-review queue's "Mark ticketed" action —
        // a confirmed booking whose supplier never came back (see the
        // 'stuck' filter on bookings() above) that staff has since
        // confirmed by phone/email with the supplier directly. Gated
        // behind booking.manage rather than booking.cancel/refund, since
        // it's an operational override of the normal flow, not a
        // cancellation.
        //
        // 'refunded' is deliberately NOT a legal value here any more —
        // BookingStatusService no longer allows a direct TICKETED/CANCELLED
        // -> REFUNDED jump (Phase 2's "in process" status sits between the
        // two: REFUND_PROCESSING). Initiating, completing or failing a
        // refund now goes through initiateRefund()/completeRefund()/
        // failRefund() below instead, which collect the method/amount/
        // gateway reference this generic endpoint has no fields for.
        $data = $request->validate([
            'to_status' => ['required', 'string', 'in:cancelled,failed,ticketed'],
            'reason' => ['nullable', 'string', 'max:500'],
            'ticket_numbers' => ['nullable', 'array'],
            'ticket_numbers.*' => ['string', 'max:100'],
        ]);

        $permission = match ($data['to_status']) {
            Booking::STATUS_TICKETED => 'booking.manage',
            default => 'booking.cancel',
        };
        abort_unless($user->hasPermission($permission), 403, 'Missing required permission: '.$permission);

        $bookingModel = Booking::withoutTenantScope()->findOrFail($booking);

        $tenantIds = $this->resolveTenantIds($request, $user);
        abort_unless($tenantIds === null || in_array($bookingModel->tenant_id, $tenantIds, true), 403, 'That booking is outside your reporting scope.');

        if ($data['to_status'] === Booking::STATUS_TICKETED && ! empty($data['ticket_numbers'])) {
            $bookingModel->update([
                'metadata' => array_merge($bookingModel->metadata ?? [], ['ticket_numbers' => $data['ticket_numbers']]),
            ]);
        }

        try {
            $bookingModel = app(\App\Domains\Booking\Services\BookingStatusService::class)
                ->transition($bookingModel, $data['to_status'], $user, $data['reason'] ?? null);

            if ($data['to_status'] === Booking::STATUS_TICKETED) {
                \App\Domains\Booking\Models\BookingEvent::log(
                    $bookingModel->id,
                    \App\Domains\Booking\Models\BookingEvent::TYPE_SUPPLIER_CONFIRMED,
                    'Manually marked ticketed by staff'.(! empty($data['ticket_numbers']) ? ' — ticket(s) '.implode(', ', $data['ticket_numbers']) : '').(! empty($data['reason']) ? ": {$data['reason']}" : '.'),
                    $user,
                );
            }
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $bookingModel->load(['tenant:id,name', 'createdBy:id,name,email', 'segments', 'passengers', 'ancillaries', 'statusHistory', 'events.actor:id,name', 'refundTransactions.initiatedBy:id,name', 'refundTransactions.completedBy:id,name', 'amendments.initiatedBy:id,name']);

        return (new BookingResource($bookingModel))->response();
    }

    /**
     * Live cancellation/refund quote for one booking — Phase 2's "Live
     * calculation, same response everywhere" principle. Staff call this
     * from the Reports "change status" panel to see the refund/fee amount
     * before confirming a cancel/refund, using the exact same
     * CancellationPolicyService::quote() the eventual customer-facing
     * self-service cancellation flow will call too. Read-only — this never
     * changes the booking; booking.view is enough (same permission
     * bookingDetail() requires), no need for booking.cancel/refund just to
     * preview a number.
     */
    public function cancellationQuote(Request $request, int $booking)
    {
        $user = $request->user();
        $this->authorizeView($user);

        $bookingModel = Booking::withoutTenantScope()->findOrFail($booking);

        $tenantIds = $this->resolveTenantIds($request, $user);
        abort_unless($tenantIds === null || in_array($bookingModel->tenant_id, $tenantIds, true), 403, 'That booking is outside your reporting scope.');

        $bookingModel->loadMissing('segments');

        $quote = app(\App\Domains\Booking\Services\CancellationPolicyService::class)->quote($bookingModel);

        return response()->json(['data' => $quote]);
    }

    /**
     * "Refund issuance, three ways: back through the original payment
     * gateway, a direct bank transfer, or credit to the customer's wallet."
     * Moves the booking TICKETED|CANCELLED -> REFUND_PROCESSING (or
     * straight to REFUNDED for a wallet refund, which is instant — see
     * RefundService). Gated behind booking.refund, same permission the old
     * direct "Initiate refund" status action required.
     */
    public function initiateRefund(Request $request, int $booking)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('booking.refund'), 403, 'Missing required permission: booking.refund');

        $data = $request->validate([
            'method' => ['required', 'string', 'in:'.implode(',', RefundTransaction::METHODS)],
            'amount' => ['required', 'numeric', 'gt:0'],
            'bank_account_name' => ['required_if:method,bank_transfer', 'nullable', 'string', 'max:191'],
            'bank_account_number' => ['required_if:method,bank_transfer', 'nullable', 'string', 'max:40'],
            'bank_ifsc' => ['required_if:method,bank_transfer', 'nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $bookingModel = Booking::withoutTenantScope()->findOrFail($booking);

        $tenantIds = $this->resolveTenantIds($request, $user);
        abort_unless($tenantIds === null || in_array($bookingModel->tenant_id, $tenantIds, true), 403, 'That booking is outside your reporting scope.');

        try {
            $this->refundService->initiate($bookingModel, $user, $data['method'], (string) $data['amount'], $data);
        } catch (RuntimeException|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $bookingModel = $bookingModel->fresh([
            'tenant:id,name', 'createdBy:id,name,email', 'segments', 'passengers', 'ancillaries',
            'statusHistory', 'events.actor:id,name', 'refundTransactions.initiatedBy:id,name', 'refundTransactions.completedBy:id,name', 'amendments.initiatedBy:id,name',
        ]);

        return (new BookingResource($bookingModel))->response();
    }

    /**
     * Staff confirm a gateway/bank-transfer refund actually landed —
     * REFUND_PROCESSING -> REFUNDED. A wallet refund never reaches this
     * (it's completed the instant it's initiated), so calling this on one
     * just 422s as "already resolved", same as calling it twice.
     */
    public function completeRefund(Request $request, int $booking, int $refund)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('booking.refund'), 403, 'Missing required permission: booking.refund');

        $data = $request->validate([
            'gateway_reference' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $bookingModel = Booking::withoutTenantScope()->findOrFail($booking);

        $tenantIds = $this->resolveTenantIds($request, $user);
        abort_unless($tenantIds === null || in_array($bookingModel->tenant_id, $tenantIds, true), 403, 'That booking is outside your reporting scope.');

        $refundModel = RefundTransaction::query()->where('booking_id', $bookingModel->id)->findOrFail($refund);

        try {
            $this->refundService->complete($refundModel, $user, $data['gateway_reference'] ?? null, $data['notes'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $bookingModel = $bookingModel->fresh([
            'tenant:id,name', 'createdBy:id,name,email', 'segments', 'passengers', 'ancillaries',
            'statusHistory', 'events.actor:id,name', 'refundTransactions.initiatedBy:id,name', 'refundTransactions.completedBy:id,name', 'amendments.initiatedBy:id,name',
        ]);

        return (new BookingResource($bookingModel))->response();
    }

    /**
     * Staff record that a refund attempt didn't go through — reverts the
     * booking to whatever it was before "Initiate refund" (ticketed or
     * cancelled), so it can be retried with a different method or
     * investigated rather than sitting stuck in "processing" forever.
     */
    public function failRefund(Request $request, int $booking, int $refund)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('booking.refund'), 403, 'Missing required permission: booking.refund');

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $bookingModel = Booking::withoutTenantScope()->findOrFail($booking);

        $tenantIds = $this->resolveTenantIds($request, $user);
        abort_unless($tenantIds === null || in_array($bookingModel->tenant_id, $tenantIds, true), 403, 'That booking is outside your reporting scope.');

        $refundModel = RefundTransaction::query()->where('booking_id', $bookingModel->id)->findOrFail($refund);

        try {
            $this->refundService->fail($refundModel, $user, $data['reason']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $bookingModel = $bookingModel->fresh([
            'tenant:id,name', 'createdBy:id,name,email', 'segments', 'passengers', 'ancillaries',
            'statusHistory', 'events.actor:id,name', 'refundTransactions.initiatedBy:id,name', 'refundTransactions.completedBy:id,name', 'amendments.initiatedBy:id,name',
        ]);

        return (new BookingResource($bookingModel))->response();
    }

    /**
     * Phase 2's last bullet: "Reschedule / date change / reissue as
     * first-class actions, not just cancel: same policy-driven fee
     * calculation, applied when changing dates or reissuing a ticket."
     * Gated behind booking.manage — the same operational-override
     * permission the manual "Mark ticketed" action already uses — rather
     * than booking.cancel/refund, since this changes booking details
     * without cancelling or moving a refund; only TICKETED bookings can be
     * amended (see BookingAmendmentService::assertTicketed()).
     */
    public function reschedule(Request $request, int $booking)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('booking.manage'), 403, 'Missing required permission: booking.manage');

        $data = $request->validate([
            'new_travel_date_start' => ['nullable', 'date'],
            'new_travel_date_end' => ['nullable', 'date'],
            'fee_collection' => ['required', 'string', 'in:'.implode(',', BookingAmendment::FEE_COLLECTIONS)],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $bookingModel = Booking::withoutTenantScope()->findOrFail($booking);

        $tenantIds = $this->resolveTenantIds($request, $user);
        abort_unless($tenantIds === null || in_array($bookingModel->tenant_id, $tenantIds, true), 403, 'That booking is outside your reporting scope.');

        try {
            $this->amendmentService->reschedule(
                $bookingModel,
                $user,
                $data['new_travel_date_start'] ?? null,
                $data['new_travel_date_end'] ?? null,
                $data['fee_collection'],
                $data['notes'] ?? null,
            );
        } catch (RuntimeException|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $bookingModel = $bookingModel->fresh([
            'tenant:id,name', 'createdBy:id,name,email', 'segments', 'passengers', 'ancillaries',
            'statusHistory', 'events.actor:id,name', 'refundTransactions.initiatedBy:id,name', 'refundTransactions.completedBy:id,name', 'amendments.initiatedBy:id,name',
        ]);

        return (new BookingResource($bookingModel))->response();
    }

    /**
     * Ticket reissue — a new supplier PNR/booking ref and/or new ticket
     * number(s), for whatever operational reason the supplier issued a
     * fresh one (schedule change, involuntary reroute, a corrected name).
     * Same fee/permission/eligibility rules as reschedule() above.
     */
    public function reissue(Request $request, int $booking)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('booking.manage'), 403, 'Missing required permission: booking.manage');

        $data = $request->validate([
            'new_supplier_booking_ref' => ['nullable', 'string', 'max:100'],
            'new_ticket_numbers' => ['nullable', 'array'],
            'new_ticket_numbers.*' => ['string', 'max:100'],
            'fee_collection' => ['required', 'string', 'in:'.implode(',', BookingAmendment::FEE_COLLECTIONS)],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $bookingModel = Booking::withoutTenantScope()->findOrFail($booking);

        $tenantIds = $this->resolveTenantIds($request, $user);
        abort_unless($tenantIds === null || in_array($bookingModel->tenant_id, $tenantIds, true), 403, 'That booking is outside your reporting scope.');

        try {
            $this->amendmentService->reissue(
                $bookingModel,
                $user,
                $data['new_supplier_booking_ref'] ?? null,
                $data['new_ticket_numbers'] ?? null,
                $data['fee_collection'],
                $data['notes'] ?? null,
            );
        } catch (RuntimeException|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $bookingModel = $bookingModel->fresh([
            'tenant:id,name', 'createdBy:id,name,email', 'segments', 'passengers', 'ancillaries',
            'statusHistory', 'events.actor:id,name', 'refundTransactions.initiatedBy:id,name', 'refundTransactions.completedBy:id,name', 'amendments.initiatedBy:id,name',
        ]);

        return (new BookingResource($bookingModel))->response();
    }

    public function payments(Request $request)
    {
        $user = $request->user();
        $this->authorizeViewPayments($user);

        [$from, $to] = $this->resolveDateRangePreset($request);
        $tenantIds = $this->resolveTenantIds($request, $user);

        $query = $this->scopedPayments($tenantIds)
            ->whereBetween('created_at', [$from, $to])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('gateway'), fn ($q) => $q->where('gateway', $request->string('gateway')))
            ->orderByDesc('created_at');

        $format = $request->string('format')->toString();

        if ($format === 'csv') {
            return $this->streamPaymentsCsv((clone $query)->with(['booking:id,booking_code,module', 'tenant:id,name']));
        }

        if ($format === 'xlsx') {
            return $this->streamPaymentsXlsx((clone $query)->with(['booking:id,booking_code,module', 'tenant:id,name']));
        }

        $transactions = $query->with(['booking:id,booking_code,module', 'tenant:id,name'])
            ->paginate($request->integer('per_page', 25));

        return response()->json([
            'data' => collect($transactions->items())->map(fn ($t) => [
                'id' => $t->id,
                'booking_id' => $t->booking_id,
                'booking_code' => $t->booking?->booking_code,
                'module' => $t->booking?->module,
                'tenant_id' => $t->tenant_id,
                'tenant_name' => $t->tenant?->name,
                'gateway' => $t->gateway,
                'gateway_txn_id' => $t->gateway_txn_id,
                'method' => $t->method,
                'amount' => (string) $t->amount,
                'wallet_amount' => (string) $t->wallet_amount,
                'currency' => $t->currency,
                'status' => $t->status,
                'created_at' => $t->created_at,
            ]),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
            ],
        ]);
    }

    /**
     * Accounting: the append-only wallet ledger (Database Design Document
     * §5.2 — WalletService is the only writer, and every credit/debit is
     * recorded here with a running balance), platform-wide or scoped to the
     * viewer's tenant hierarchy exactly like every other Reports endpoint.
     * wallet_ledger_entries has no tenant_id of its own, so scoping joins
     * through wallets (which does carry one).
     *
     * reference_id means a bookings.id for every reference_type except
     * REF_TOPUP (a payment_transactions.id), REF_OFFLINE_PAYMENT (an
     * offline_payment_requests.id, OfflinePaymentService::approve()), and
     * REF_GROUP_DEPOSIT/REF_GROUP_BALANCE (a group_booking_requests.id,
     * GroupBookingService::acceptQuote()/finalize()), and
     * REF_HOTEL_GROUP_DEPOSIT/REF_HOTEL_GROUP_BALANCE (a
     * hotel_group_booking_requests.id, HotelGroupBookingService's identical
     * pair of methods) — confirmed against every WalletService::credit()/
     * debit() call site: booking payments, refunds, cashback and commission
     * payouts, and booking amendment fees all pass a booking_id, while
     * WalletController::topup(), OfflinePaymentService::approve(), and both
     * group-booking services' deposit/balance debits are the callers that
     * pass a different table's id instead. Booking codes are resolved in
     * one bulk lookup rather than per-row queries.
     */
    protected const NON_BOOKING_REFERENCE_TYPES = [
        WalletLedgerEntry::REF_TOPUP,
        WalletLedgerEntry::REF_OFFLINE_PAYMENT,
        WalletLedgerEntry::REF_GROUP_DEPOSIT,
        WalletLedgerEntry::REF_GROUP_BALANCE,
        WalletLedgerEntry::REF_HOTEL_GROUP_DEPOSIT,
        WalletLedgerEntry::REF_HOTEL_GROUP_BALANCE,
    ];
    public function ledger(Request $request)
    {
        $user = $request->user();
        $this->authorizeViewPayments($user);

        [$from, $to] = $this->resolveDateRangePreset($request);
        $tenantIds = $this->resolveTenantIds($request, $user);

        $query = $this->ledgerQuery($request, $tenantIds, $from, $to);

        // "reporting in export in excel or pdf" — the wallet ledger had NO
        // export of any kind (JSON-only, unlike every other Reports
        // endpoint). format=csv/pdf/xlsx exports every entry matching the
        // current filters — not just the current page — same idiom as
        // bookings()/payments()/summary() above.
        $format = $request->string('format')->toString();

        if (in_array($format, ['csv', 'pdf', 'xlsx'], true)) {
            return $this->exportLedger((clone $query), $format, $from, $to);
        }

        $perPage = min($request->integer('per_page', 50), 500);
        $entries = $query->paginate($perPage);

        return response()->json([
            'data' => $this->decorateLedgerRows(collect($entries->items()))->values(),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
            ],
        ]);
    }

    /** The ledger's filtered-but-unpaginated base query — shared by the JSON page view and every export format. */
    protected function ledgerQuery(Request $request, ?array $tenantIds, $from, $to)
    {
        return WalletLedgerEntry::query()
            ->join('wallets', 'wallets.id', '=', 'wallet_ledger_entries.wallet_id')
            ->when($tenantIds !== null, fn ($q) => $q->whereIn('wallets.tenant_id', $tenantIds))
            ->whereBetween('wallet_ledger_entries.created_at', [$from, $to])
            ->when($request->filled('entry_type'), fn ($q) => $q->where('wallet_ledger_entries.entry_type', $request->string('entry_type')))
            ->when($request->filled('reference_type'), fn ($q) => $q->where('wallet_ledger_entries.reference_type', $request->string('reference_type')))
            ->when($request->filled('wallet_id'), fn ($q) => $q->where('wallet_ledger_entries.wallet_id', $request->integer('wallet_id')))
            ->select(
                'wallet_ledger_entries.id',
                'wallet_ledger_entries.wallet_id',
                'wallet_ledger_entries.entry_type',
                'wallet_ledger_entries.amount',
                'wallet_ledger_entries.running_balance',
                'wallet_ledger_entries.reference_type',
                'wallet_ledger_entries.reference_id',
                'wallet_ledger_entries.remarks',
                'wallet_ledger_entries.created_by',
                'wallet_ledger_entries.created_at',
                'wallets.tenant_id as wallet_tenant_id',
                'wallets.owner_type as wallet_owner_type',
                'wallets.owner_id as wallet_owner_id',
                'wallets.currency as wallet_currency',
            )
            ->orderByDesc('wallet_ledger_entries.id');
    }

    /**
     * Resolves owner names / booking codes / created-by names for a batch
     * of raw ledger rows and returns them in the exact shape the frontend
     * (and every export format) already expects — extracted out of
     * ledger() so the JSON page view and the CSV/PDF/XLSX exports below
     * never risk drifting out of sync with two copies of this mapping.
     */
    protected function decorateLedgerRows($rows)
    {
        $bookingIds = $rows->whereNotIn('reference_type', self::NON_BOOKING_REFERENCE_TYPES)
            ->pluck('reference_id')->filter()->unique()->values();

        $bookingCodes = Booking::withoutTenantScope()->whereIn('id', $bookingIds)->pluck('booking_code', 'id');

        $tenantOwnerIds = $rows->where('wallet_owner_type', Wallet::OWNER_TENANT)
            ->pluck('wallet_owner_id')->filter()->unique()->values();
        $userOwnerIds = $rows->where('wallet_owner_type', Wallet::OWNER_USER)
            ->pluck('wallet_owner_id')->filter()->unique()->values();

        $tenantNames = Tenant::query()->whereIn('id', $tenantOwnerIds)->pluck('name', 'id');
        $userNames = User::query()->whereIn('id', $userOwnerIds)->pluck('name', 'id');

        $createdByIds = $rows->pluck('created_by')->filter()->unique()->values();
        $createdByNames = User::query()->whereIn('id', $createdByIds)->pluck('name', 'id');

        return $rows->map(function ($r) use ($bookingCodes, $tenantNames, $userNames, $createdByNames) {
            $ownerName = $r->wallet_owner_type === Wallet::OWNER_TENANT
                ? ($tenantNames[$r->wallet_owner_id] ?? "Tenant #{$r->wallet_owner_id}")
                : ($userNames[$r->wallet_owner_id] ?? "User #{$r->wallet_owner_id}");

            $bookingId = ! in_array($r->reference_type, self::NON_BOOKING_REFERENCE_TYPES, true)
                ? $r->reference_id
                : null;

            return [
                'id' => $r->id,
                'wallet_id' => $r->wallet_id,
                'wallet_owner_type' => $r->wallet_owner_type,
                'wallet_owner_name' => $ownerName,
                'tenant_id' => $r->wallet_tenant_id,
                'entry_type' => $r->entry_type,
                'amount' => (string) $r->amount,
                'running_balance' => (string) $r->running_balance,
                'currency' => $r->wallet_currency,
                'reference_type' => $r->reference_type,
                'reference_booking_id' => $bookingId,
                'reference_booking_code' => $bookingId ? ($bookingCodes[$bookingId] ?? null) : null,
                'remarks' => $r->remarks,
                'created_by_name' => $r->created_by ? ($createdByNames[$r->created_by] ?? null) : null,
                'created_at' => $r->created_at,
            ];
        });
    }

    /**
     * Dispatches to the right streamer for every filtered (unpaginated)
     * ledger entry — demo/typical-tenant scale, so one ->get() + the same
     * decorateLedgerRows() the JSON view uses is simpler than a chunked
     * pass and the owner-name/booking-code lookups it would need to repeat
     * per chunk.
     */
    protected function exportLedger($query, string $format, $from, $to)
    {
        $decorated = $this->decorateLedgerRows(collect($query->get()))->values();

        return match ($format) {
            'csv' => $this->streamLedgerCsv($decorated),
            'xlsx' => $this->streamLedgerXlsx($decorated),
            default => $this->streamLedgerPdf($decorated, $from, $to),
        };
    }

    protected function streamLedgerCsv($rows): StreamedResponse
    {
        $columns = [
            'Date', 'Wallet owner type', 'Wallet owner', 'Entry type', 'Reference type',
            'Reference booking code', 'Amount', 'Running balance', 'Currency', 'Remarks', 'Created by',
        ];

        return response()->streamDownload(function () use ($rows, $columns) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);

            foreach ($rows as $r) {
                fputcsv($handle, [
                    $r['created_at'],
                    $r['wallet_owner_type'],
                    $r['wallet_owner_name'],
                    $r['entry_type'],
                    $r['reference_type'],
                    $r['reference_booking_code'],
                    $r['amount'],
                    $r['running_balance'],
                    $r['currency'],
                    $r['remarks'],
                    $r['created_by_name'],
                ]);
            }

            fclose($handle);
        }, 'wallet-ledger.csv', ['Content-Type' => 'text/csv']);
    }

    protected function streamLedgerXlsx($rows)
    {
        $data = (function () use ($rows) {
            yield [
                'Date', 'Wallet owner type', 'Wallet owner', 'Entry type', 'Reference type',
                'Reference booking code', 'Amount', 'Running balance', 'Currency', 'Remarks', 'Created by',
            ];

            foreach ($rows as $r) {
                yield [
                    (string) $r['created_at'],
                    $r['wallet_owner_type'],
                    $r['wallet_owner_name'],
                    $r['entry_type'],
                    $r['reference_type'],
                    $r['reference_booking_code'],
                    (float) $r['amount'],
                    (float) $r['running_balance'],
                    $r['currency'],
                    $r['remarks'],
                    $r['created_by_name'],
                ];
            }
        })();

        return XlsxWriter::download('wallet-ledger.xlsx', [['name' => 'Wallet ledger', 'rows' => $data]]);
    }

    protected function streamLedgerPdf($rows, $from, $to)
    {
        $pdf = Pdf::loadView('pdf.reports-ledger', [
            'rows' => $rows,
            'logo' => PdfBranding::logoDataUri(),
            'generatedAt' => now(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('wallet-ledger-'.$from->toDateString().'-to-'.$to->toDateString().'.pdf');
    }

    /**
     * "Agent, sub-agent... commission statements" — a dedicated per-channel
     * earnings view, separate from the general Overview/Ledger reports: an
     * agent/sub-agent/white-label operator/API partner looking at exactly
     * what THEY were paid, booking by booking, rather than having to read
     * it back out of the wallet ledger's mixed stream of top-ups, refunds
     * and cashback alongside commission. Source of truth is
     * CommissionCredit (one row per booking that ever paid out commission —
     * see its own docblock), not WalletLedgerEntry — CommissionService
     * already writes exactly one credit per booking there, idempotently,
     * so there's no risk of this double-counting a ledger entry that was
     * later reversed/adjusted.
     *
     * Same tenant-hierarchy scoping and wallet.view gate as ledger() — an
     * agent/sub-agent already holds wallet.view (see RolePermissionSeeder)
     * and resolveTenantIds() already narrows a non-staff viewer to their
     * own hierarchy, so this is "my own statement" for them and "any
     * descendant's statement" for staff/parent tenants via ?tenant_id=.
     */
    public function commissionStatement(Request $request)
    {
        $user = $request->user();
        $this->authorizeViewPayments($user);

        [$from, $to] = $this->resolveDateRangePreset($request);
        $tenantIds = $this->resolveTenantIds($request, $user);

        $baseQuery = $this->commissionBaseQuery($request, $tenantIds, $from, $to);

        $format = $request->string('format')->toString();

        if (in_array($format, ['csv', 'pdf', 'xlsx'], true)) {
            return $this->exportCommissionStatement((clone $baseQuery), $format, $from, $to);
        }

        $totalsRow = (clone $baseQuery)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(commission_credits.amount), 0) as total_amount')
            ->first();

        $byModule = (clone $baseQuery)
            ->select('bookings.module')
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(commission_credits.amount), 0) as total_amount')
            ->groupBy('bookings.module')
            ->orderByDesc(DB::raw('COALESCE(SUM(commission_credits.amount), 0)'))
            ->get();

        // Only meaningful once more than one tenant is in scope — a lone
        // agent looking at their own statement doesn't need a "by agent"
        // breakdown of themselves (same reasoning summary()'s top_tenants
        // already uses).
        $byTenant = collect();

        if ($tenantIds === null || count($tenantIds) > 1) {
            $byTenantRows = (clone $baseQuery)
                ->select('bookings.tenant_id')
                ->selectRaw('COUNT(*) as count, COALESCE(SUM(commission_credits.amount), 0) as total_amount')
                ->groupBy('bookings.tenant_id')
                ->orderByDesc(DB::raw('COALESCE(SUM(commission_credits.amount), 0)'))
                ->get();

            $tenantNames = Tenant::query()->whereIn('id', $byTenantRows->pluck('tenant_id'))->pluck('name', 'id');

            $byTenant = $byTenantRows->map(fn ($r) => [
                'tenant_id' => $r->tenant_id,
                'tenant_name' => $tenantNames[$r->tenant_id] ?? "Tenant #{$r->tenant_id}",
                'count' => (int) $r->count,
                'total_amount' => (string) $r->total_amount,
            ])->values();
        }

        $perPage = min($request->integer('per_page', 25), 500);
        $entries = (clone $baseQuery)
            ->select(...$this->commissionDetailColumns())
            ->orderByDesc('commission_credits.created_at')
            ->paginate($perPage);

        return response()->json([
            'data' => $this->decorateCommissionRows(collect($entries->items()))->values(),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
            ],
            'totals' => [
                'count' => (int) $totalsRow->count,
                'total_amount' => (string) $totalsRow->total_amount,
            ],
            'by_module' => $byModule->map(fn ($r) => [
                'module' => $r->module, 'count' => (int) $r->count, 'total_amount' => (string) $r->total_amount,
            ])->values(),
            'by_tenant' => $byTenant,
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'scope' => [
                'is_platform_wide' => $tenantIds === null,
                'tenant_count' => $tenantIds === null ? null : count($tenantIds),
            ],
        ]);
    }

    /** The filtered-but-unselected join — every aggregate and the detail list below all clone from this. */
    protected function commissionBaseQuery(Request $request, ?array $tenantIds, $from, $to)
    {
        return CommissionCredit::query()
            ->join('bookings', 'bookings.id', '=', 'commission_credits.booking_id')
            ->join('commission_rules', 'commission_rules.id', '=', 'commission_credits.commission_rule_id')
            ->when($tenantIds !== null, fn ($q) => $q->whereIn('bookings.tenant_id', $tenantIds))
            ->whereBetween('commission_credits.created_at', [$from, $to])
            ->when($request->filled('module'), fn ($q) => $q->where('bookings.module', $request->string('module')));
    }

    /** @return array<int, string> */
    protected function commissionDetailColumns(): array
    {
        return [
            'commission_credits.id',
            'commission_credits.booking_id',
            'commission_credits.amount',
            'commission_credits.created_at',
            'bookings.tenant_id',
            'bookings.booking_code',
            'bookings.module',
            'bookings.channel',
            'bookings.gross_amount',
            'commission_rules.name as rule_name',
            'commission_rules.rule_type',
        ];
    }

    protected function decorateCommissionRows($rows)
    {
        $tenantNames = Tenant::query()->whereIn('id', $rows->pluck('tenant_id')->filter()->unique())->pluck('name', 'id');

        return $rows->map(fn ($r) => [
            'id' => $r->id,
            'booking_id' => $r->booking_id,
            'booking_code' => $r->booking_code,
            'module' => $r->module,
            'channel' => $r->channel,
            'gross_amount' => (string) $r->gross_amount,
            'tenant_id' => $r->tenant_id,
            'tenant_name' => $tenantNames[$r->tenant_id] ?? "Tenant #{$r->tenant_id}",
            'rule_name' => $r->rule_name,
            'rule_type' => $r->rule_type,
            'amount' => (string) $r->amount,
            'created_at' => $r->created_at,
        ]);
    }

    protected function exportCommissionStatement($query, string $format, $from, $to)
    {
        $rows = $this->decorateCommissionRows(
            collect($query->select(...$this->commissionDetailColumns())->orderByDesc('commission_credits.created_at')->get())
        )->values();

        return match ($format) {
            'csv' => $this->streamCommissionCsv($rows),
            'xlsx' => $this->streamCommissionXlsx($rows),
            default => $this->streamCommissionPdf($rows, $from, $to),
        };
    }

    protected function streamCommissionCsv($rows): StreamedResponse
    {
        $columns = ['Date', 'Booking code', 'Module', 'Channel', 'Tenant', 'Gross amount', 'Commission rule', 'Rule type', 'Commission amount'];

        return response()->streamDownload(function () use ($rows, $columns) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);

            foreach ($rows as $r) {
                fputcsv($handle, [
                    $r['created_at'], $r['booking_code'], $r['module'], $r['channel'], $r['tenant_name'],
                    $r['gross_amount'], $r['rule_name'], $r['rule_type'], $r['amount'],
                ]);
            }

            fclose($handle);
        }, 'commission-statement.csv', ['Content-Type' => 'text/csv']);
    }

    protected function streamCommissionXlsx($rows)
    {
        $data = (function () use ($rows) {
            yield ['Date', 'Booking code', 'Module', 'Channel', 'Tenant', 'Gross amount', 'Commission rule', 'Rule type', 'Commission amount'];

            foreach ($rows as $r) {
                yield [
                    (string) $r['created_at'], $r['booking_code'], $r['module'], $r['channel'], $r['tenant_name'],
                    (float) $r['gross_amount'], $r['rule_name'], $r['rule_type'], (float) $r['amount'],
                ];
            }
        })();

        return XlsxWriter::download('commission-statement.xlsx', [['name' => 'Commission statement', 'rows' => $data]]);
    }

    protected function streamCommissionPdf($rows, $from, $to)
    {
        $total = collect($rows)->sum(fn ($r) => (float) $r['amount']);

        $pdf = Pdf::loadView('pdf.reports-commission-statement', [
            'rows' => $rows,
            'total' => $total,
            'logo' => PdfBranding::logoDataUri(),
            'generatedAt' => now(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ])->setPaper('a4', 'portrait');

        return $pdf->download('commission-statement-'.$from->toDateString().'-to-'.$to->toDateString().'.pdf');
    }

    protected function streamBookingsCsv($query): StreamedResponse
    {
        $columns = [
            'Booking code', 'Reference', 'Tenant', 'Module', 'Channel', 'Source', 'Status',
            'Gross amount', 'Markup', 'Commission', 'Discount', 'Total amount', 'Currency',
            'Travel start', 'Contact email', 'Contact phone', 'Created at',
        ];

        return response()->streamDownload(function () use ($query, $columns) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);

            $query->orderByDesc('created_at')->chunk(500, function ($chunk) use ($handle) {
                foreach ($chunk as $booking) {
                    fputcsv($handle, [
                        $booking->booking_code,
                        $booking->booking_reference,
                        $booking->tenant?->name,
                        $booking->module,
                        $booking->channel,
                        $booking->supplier_code,
                        $booking->status,
                        $booking->gross_amount,
                        $booking->markup_amount,
                        $booking->commission_amount,
                        $booking->discount_amount,
                        $booking->total_amount,
                        $booking->currency,
                        $booking->travel_date_start?->toDateString(),
                        $booking->contact_email,
                        $booking->contact_phone,
                        $booking->created_at,
                    ]);
                }
            });

            fclose($handle);
        }, 'bookings-report.csv', ['Content-Type' => 'text/csv']);
    }

    protected function streamPaymentsCsv($query): StreamedResponse
    {
        $columns = [
            'Booking code', 'Tenant', 'Gateway', 'Gateway txn id', 'Method',
            'Amount', 'Wallet amount', 'Currency', 'Status', 'Created at',
        ];

        return response()->streamDownload(function () use ($query, $columns) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);

            $query->orderByDesc('created_at')->chunk(500, function ($chunk) use ($handle) {
                foreach ($chunk as $txn) {
                    fputcsv($handle, [
                        $txn->booking?->booking_code,
                        $txn->tenant?->name,
                        $txn->gateway,
                        $txn->gateway_txn_id,
                        $txn->method,
                        $txn->amount,
                        $txn->wallet_amount,
                        $txn->currency,
                        $txn->status,
                        $txn->created_at,
                    ]);
                }
            });

            fclose($handle);
        }, 'payments-report.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Same Bookings report as streamBookingsCsv(), as real .xlsx instead —
     * cursor() rather than chunk() since XlsxWriter needs one PHP iterable
     * of rows (a generator), not a chunk callback; still a single
     * buffered-cursor pass over the result set rather than ->get(), so a
     * large export doesn't have to hydrate every row into memory at once.
     */
    protected function streamBookingsXlsx($query)
    {
        $rows = (function () use ($query) {
            yield [
                'Booking code', 'Reference', 'Tenant', 'Module', 'Channel', 'Source', 'Status',
                'Gross amount', 'Markup', 'Commission', 'Discount', 'Total amount', 'Currency',
                'Travel start', 'Contact email', 'Contact phone', 'Created at',
            ];

            foreach ($query->orderByDesc('created_at')->cursor() as $booking) {
                yield [
                    $booking->booking_code,
                    $booking->booking_reference,
                    $booking->tenant?->name,
                    $booking->module,
                    $booking->channel,
                    $booking->supplier_code,
                    $booking->status,
                    (float) $booking->gross_amount,
                    (float) $booking->markup_amount,
                    (float) $booking->commission_amount,
                    (float) $booking->discount_amount,
                    (float) $booking->total_amount,
                    $booking->currency,
                    $booking->travel_date_start?->toDateString(),
                    $booking->contact_email,
                    $booking->contact_phone,
                    (string) $booking->created_at,
                ];
            }
        })();

        return XlsxWriter::download('bookings-report.xlsx', [['name' => 'Bookings', 'rows' => $rows]]);
    }

    /** Same Payments report as streamPaymentsCsv(), as real .xlsx instead. */
    protected function streamPaymentsXlsx($query)
    {
        $rows = (function () use ($query) {
            yield [
                'Booking code', 'Tenant', 'Gateway', 'Gateway txn id', 'Method',
                'Amount', 'Wallet amount', 'Currency', 'Status', 'Created at',
            ];

            foreach ($query->orderByDesc('created_at')->cursor() as $txn) {
                yield [
                    $txn->booking?->booking_code,
                    $txn->tenant?->name,
                    $txn->gateway,
                    $txn->gateway_txn_id,
                    $txn->method,
                    (float) $txn->amount,
                    (float) $txn->wallet_amount,
                    $txn->currency,
                    $txn->status,
                    (string) $txn->created_at,
                ];
            }
        })();

        return XlsxWriter::download('payments-report.xlsx', [['name' => 'Payments', 'rows' => $rows]]);
    }

    /**
     * Null return means "platform-wide, do not filter by tenant_id at all"
     * (Super Admin/Staff with no ?tenant_id= override). Otherwise the
     * explicit list of tenant IDs the acting user may see.
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
        $ownScope = $this->tenantService->getDescendantIdsRobust($ownTenant);

        if ($requestedTenantId === null) {
            return $ownScope;
        }

        abort_unless(in_array($requestedTenantId, $ownScope, true), 403, 'That tenant is outside your own hierarchy.');

        $tenant = Tenant::query()->find($requestedTenantId);

        return $tenant ? $this->tenantService->getDescendantIdsRobust($tenant) : $ownScope;
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

    protected function authorizeView(User $user): void
    {
        abort_unless($user->hasPermission('booking.view'), 403, 'Missing required permission: booking.view');
    }

    protected function authorizeViewPayments(User $user): void
    {
        abort_unless($user->hasPermission('wallet.view'), 403, 'Missing required permission: wallet.view');
    }
}
