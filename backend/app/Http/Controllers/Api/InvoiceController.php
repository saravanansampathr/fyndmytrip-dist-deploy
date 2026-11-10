<?php

namespace App\Http\Controllers\Api;

use App\Domains\Booking\Models\Booking;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PdfBranding;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/**
 * Platform Roadmap Phase 7's final bullet — "Invoices, generated per
 * booking, per user. Every ticketed booking generates an invoice addressed
 * to whoever is responsible for it — the agent, the B2B partner, the
 * whitelabel operator, the API caller, or the direct customer — visible
 * under that user's own login, not just to staff."
 *
 * One generic controller for all eight Product Modules, same reasoning as
 * ReportsController::bookingDetail() — Booking's docblock: "A single
 * generic booking schema serves all eight Product Modules... Module-
 * specific detail lives in the module ENUM plus the metadata JSON column,
 * not per-module table forks" — rather than adding an invoice action to
 * each of the seven existing per-module booking controllers.
 *
 * Generated on-demand (never stored), exactly like every other booking PDF
 * (ticket/voucher/policy) — no invoices table, no migration. The invoice
 * number is derived from the booking's own id rather than a counter table,
 * the same zero-migration approach booking_code itself already uses.
 *
 * Authorization mirrors MyBookingsController exactly (not the looser scope
 * the per-module ticket/voucher endpoints rely on): a customer sees only
 * their own bookings (created_by_user_id), never their tenant's other
 * customers'; everyone else sees their own tenant plus whatever sits below
 * it via TenantService::getDescendantIdsRobust(); platform staff see every
 * tenant unless they narrow with ?tenant_id=. This is what makes the
 * invoice "visible under that user's own login" a real guarantee rather
 * than an accident of the tenant global scope.
 */
class InvoiceController extends Controller
{
    public function __construct(protected TenantService $tenantService)
    {
    }

    public function show(Request $request, int $booking)
    {
        $user = $request->user();

        // Deliberately not using implicit route-model binding — same
        // reasoning as ReportsController::bookingDetail(): Booking's
        // default global scope is tenant-scoped to the *acting* tenant,
        // which would 404 a platform-staff or parent-tenant viewer trying
        // to reach a booking that belongs to a tenant further down their
        // own hierarchy. Authorization below replaces the scope instead
        // of relying on it.
        $bookingModel = Booking::withoutTenantScope()
            ->with(['tenant', 'createdBy', 'statusHistory'])
            ->findOrFail($booking);

        $this->authorizeInvoiceAccess($request, $user, $bookingModel);

        // An invoice only exists once a booking has actually been
        // ticketed — checked (and dated) from status history rather than
        // just the current status, since a later cancellation/refund
        // shouldn't erase the invoice for a booking that WAS ticketed,
        // and the invoice date must stay fixed to when that happened
        // rather than drifting to "now" on every later view.
        $ticketedAt = $bookingModel->statusHistory->firstWhere('to_status', Booking::STATUS_TICKETED)?->created_at;

        if ($ticketedAt === null && $bookingModel->status === Booking::STATUS_TICKETED) {
            // No recorded transition row (older data) but the booking is
            // currently ticketed — fall back to its own updated_at rather
            // than refusing the invoice outright.
            $ticketedAt = $bookingModel->updated_at;
        }

        abort_if($ticketedAt === null, 404, 'No invoice is available for this booking yet.');

        [$billTo, $billToLabel] = $this->resolveBillTo($bookingModel);

        // Same markup-folding convention as every other booking PDF — "no
        // where markup should be shows as markup, It needed to added in
        // tax alone" (FlightBookingController::ticketPdf()) — reused
        // as-is here rather than a separate rule for invoices, since the
        // policy is about what's ever shown on a document a traveller (or
        // an agent/partner) might see, not specific to the e-ticket.
        $baseFare = (float) $bookingModel->supplier_net_amount;
        $markup = (float) $bookingModel->markup_amount;
        $serviceFee = $markup > 0 ? round($markup / 1.18, 2) : 0.0;
        $gst = $markup > 0 ? round($markup - $serviceFee, 2) : 0.0;
        $extras = (float) $bookingModel->extras_amount;
        $discount = (float) $bookingModel->discount_amount;

        $lineItems = [];
        $lineItems[] = ['label' => 'Base Fare', 'amount' => $baseFare];
        if ($serviceFee > 0) {
            $lineItems[] = ['label' => 'Taxes & Fees', 'amount' => $serviceFee];
        }
        if ($gst > 0) {
            $lineItems[] = ['label' => 'GST (18%)', 'amount' => $gst];
        }
        if ($extras > 0) {
            $lineItems[] = ['label' => 'Add-on Services (meals / seats / baggage)', 'amount' => $extras];
        }
        if ($discount > 0) {
            $lineItems[] = ['label' => 'Discount', 'amount' => -$discount];
        }

        $issuingTenant = $bookingModel->tenant;

        $pdf = Pdf::loadView('pdf.invoice', [
            'booking' => $bookingModel,
            'invoiceNumber' => 'INV-'.str_pad((string) $bookingModel->id, 6, '0', STR_PAD_LEFT),
            'invoiceDate' => $ticketedAt->format('d M Y'),
            'brandName' => $issuingTenant?->legal_name ?? $issuingTenant?->name ?? 'FyndMyTrip',
            'logo' => PdfBranding::resolveLogo($issuingTenant),
            'tenant' => $issuingTenant,
            'socialLinks' => PdfBranding::socialLinks($issuingTenant),
            'qrDataUri' => PdfBranding::qrDataUri($issuingTenant),
            'playStoreUrl' => PdfBranding::appLinks($issuingTenant)['play_store_url'],
            'appStoreUrl' => PdfBranding::appLinks($issuingTenant)['app_store_url'],
            'generatedAt' => now()->format('d M Y, h:i A'),
            'billTo' => $billTo,
            'billToLabel' => $billToLabel,
            'lineItems' => $lineItems,
            'total' => (float) $bookingModel->total_amount,
        ])->setPaper('a4');

        $filename = ($bookingModel->booking_code ?? $bookingModel->booking_reference).'-invoice.pdf';

        return $request->boolean('download') ? $pdf->download($filename) : $pdf->stream($filename);
    }

    /** Mirrors MyBookingsController::index()'s exact scoping rule. */
    protected function authorizeInvoiceAccess(Request $request, User $user, Booking $booking): void
    {
        if ($user->role === User::ROLE_CUSTOMER) {
            abort_unless($booking->created_by_user_id === $user->id, 403, 'That invoice is not available under your account.');

            return;
        }

        $requestedTenantId = $request->filled('tenant_id') ? $request->integer('tenant_id') : null;

        if ($user->isPlatformStaff()) {
            if ($requestedTenantId === null) {
                return;
            }

            $tenant = Tenant::query()->findOrFail($requestedTenantId);
            $tenantIds = $this->tenantService->getDescendantIdsRobust($tenant);
        } else {
            $ownTenant = Tenant::query()->findOrFail($user->tenant_id);
            $tenantIds = $this->tenantService->getDescendantIdsRobust($ownTenant);
        }

        abort_unless(in_array($booking->tenant_id, $tenantIds, true), 403, 'That invoice is outside your account scope.');
    }

    /**
     * Who this invoice is addressed to — the roadmap bullet's own wording:
     * "the agent, the B2B partner, the whitelabel operator, the API
     * caller, or the direct customer". Booking::channel (snapshotted at
     * creation from the booking tenant's Tenant::type) already carries
     * exactly this distinction: CHANNEL_DIRECT bills the individual
     * customer who bought it; every other channel bills the booking's own
     * tenant — the agent/sub-agent/white-label/API-partner entity that
     * actually stands between the platform and the traveller.
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    protected function resolveBillTo(Booking $booking): array
    {
        if ($booking->channel === Booking::CHANNEL_DIRECT || $booking->channel === null) {
            $customer = $booking->createdBy;

            return [[
                'name' => $customer?->name ?? 'Guest customer',
                'email' => $booking->contact_email ?? $customer?->email,
                'phone' => $booking->contact_phone ?? $customer?->phone,
                'address' => null,
                'registration_no' => null,
            ], 'Customer'];
        }

        $tenant = $booking->tenant;

        $channelLabels = [
            Booking::CHANNEL_AGENT => 'Agent',
            Booking::CHANNEL_SUB_AGENT => 'Sub-agent',
            Booking::CHANNEL_WHITE_LABEL => 'White-label operator',
            Booking::CHANNEL_API_PARTNER => 'API partner',
        ];

        return [[
            'name' => $tenant?->legal_name ?? $tenant?->name ?? 'Partner account',
            'email' => $tenant?->support_email,
            'phone' => $tenant?->support_phone,
            'address' => $tenant?->business_address,
            'registration_no' => $tenant?->business_registration_no,
        ], $channelLabels[$booking->channel] ?? 'Partner'];
    }
}
