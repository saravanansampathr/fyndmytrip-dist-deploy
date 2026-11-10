<?php

namespace App\Http\Controllers\Api;

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\BookingPassenger;
use App\Domains\Booking\Services\BookingStatusService;
use App\Domains\Cashback\Services\CashbackService;
use App\Domains\Commission\Services\CommissionService;
use App\Domains\Inventory\Models\ActivityInventoryItem;
use App\Domains\Payments\Models\PaymentMethodRoute;
use App\Domains\Payments\Models\PaymentTransaction;
use App\Domains\Payments\Services\PaymentGatewayService;
use App\Domains\Suppliers\DTOs\BookingConfirmResult;
use App\Domains\Suppliers\DTOs\BookingHoldResult;
use App\Domains\Suppliers\DTOs\FareRuleResult;
use App\Domains\Suppliers\DTOs\SearchRequest;
use App\Domains\Suppliers\Exceptions\SupplierException;
use App\Domains\Suppliers\Services\MarkupService;
use App\Domains\Suppliers\Services\SupplierOrchestrator;
use App\Domains\Tenancy\Services\TenantContext;
use App\Domains\Wallet\Exceptions\InsufficientBalanceException;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Models\WalletLedgerEntry;
use App\Domains\Wallet\Services\WalletService;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3's sixth and final item, after Bus, Hotel, Cab, Insurance and
 * Cruise: search -> hold -> confirm (wallet/gateway) -> voucher, against
 * MockActivitySupplierAdapter until a real activities/experiences API is
 * wired in — the exact same pipeline shape as CabBookingController.
 *
 * Unlike every other Phase 3 vertical, Activities had no inventory table,
 * no admin screen, nothing (see the roadmap's "Doesn't exist at all"
 * note) — so this is the first module built completely from scratch, both
 * the admin CRUD side (ActivityInventoryItemController) and this real
 * booking flow together, rather than adding a storefront on top of
 * existing "Own inventory" management. Being a from-scratch clean-room
 * build, hold() below populates its own voucher metadata correctly from
 * day one, the same lesson Cab/Insurance/Cruise's controllers already
 * applied.
 *
 * An activity on this platform is a single date at a single city — no
 * multi-day itinerary or time-slot picker in this first cut (one offer is
 * one fixed start time for that date), no international classification
 * (domestic-issued only), and the price quoted for an offer is per booking
 * (one activity slot for the group), not multiplied by traveller count —
 * same convention every other Phase 3 vertical's real flow already
 * established (a hold consumes exactly one inventory unit regardless of
 * how many people are named on it). These can layer on later without
 * changing this shape, the same way Flight grew them incrementally.
 */
class ActivityBookingController extends Controller
{
    public function __construct(
        protected MarkupService $markupService,
        protected SupplierOrchestrator $orchestrator,
        protected WalletService $walletService,
        protected BookingStatusService $statusService,
        protected PaymentGatewayService $paymentGatewayService,
        protected CashbackService $cashbackService,
        protected CommissionService $commissionService,
    ) {}

    public function voucherPdf(Request $request, Booking $booking)
    {
        abort_unless($booking->module === Booking::MODULE_ACTIVITY, 404);

        $booking->load([
            'passengers',
            'tenant:id,name,legal_name,logo_url,support_email,support_phone,business_address',
        ]);

        $tenant = $booking->tenant;

        // Same "never show markup as markup" convention as every other
        // module's customer-facing PDF — the platform's margin is folded
        // into Service & Fees, never itemised as markup.
        $baseFare = (float) $booking->supplier_net_amount;
        $serviceFee = round((float) $booking->markup_amount, 2);

        $priceRows = [['label' => 'Activity Fare', 'amount' => $baseFare]];
        if ($serviceFee > 0) {
            $priceRows[] = ['label' => 'Service & Fees', 'amount' => $serviceFee];
        }

        $pdf = Pdf::loadView('pdf.activity-voucher', [
            'booking' => $booking,
            'brandName' => $tenant?->legal_name ?? $tenant?->name ?? 'FyndMyTrip',
            'logo' => \App\Support\PdfBranding::resolveLogo($tenant),
            'tenant' => $tenant,
            'socialLinks' => \App\Support\PdfBranding::socialLinks($tenant),
            'qrDataUri' => \App\Support\PdfBranding::qrDataUri($tenant),
            'playStoreUrl' => \App\Support\PdfBranding::appLinks($tenant)['play_store_url'],
            'appStoreUrl' => \App\Support\PdfBranding::appLinks($tenant)['app_store_url'],
            'generatedAt' => now()->format('d M Y, h:i A'),
            'priceRows' => $priceRows,
            'total' => (float) $booking->total_amount,
        ])->setPaper('a4');

        $filename = ($booking->booking_code ?? $booking->booking_reference).'-activity-voucher.pdf';

        return $request->boolean('download') ? $pdf->download($filename) : $pdf->stream($filename);
    }

    /**
     * An activity on this platform is always a single city on a single
     * date — no destination, no round-trip concept. Merges MOCKACTIVITY-
     * adapter offers with the tenant's own manually-entered
     * ActivityInventoryItem rows (ownInventoryOffers()), shaped identically
     * so the frontend can't tell the difference.
     */
    public function search(Request $request)
    {
        $data = $request->validate([
            'city' => ['required', 'string', 'max:100'],
            'activity_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'travellers' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        $travellerCount = $data['travellers'] ?? 1;
        // Guest-accessible — same convention as Flights/Hotels/Buses (see
        // FlightBookingController::search()'s docblock).
        $user = $request->user();
        $tenant = $user?->tenant ?? app(TenantContext::class)->get();
        $role = $this->markupRoleFor($user);
        $canViewMargin = $user?->hasPermission('pricing.view_margin') ?? false;

        abort_if(! $tenant, 422, 'Unable to resolve which storefront to search against.');

        $searchRequest = new SearchRequest(
            module: Booking::MODULE_ACTIVITY,
            origin: $data['city'],
            destination: null,
            departureDate: $data['activity_date'],
            passengerCount: $travellerCount,
        );

        $offers = $this->orchestrator->search($tenant, $searchRequest);
        array_push($offers, ...$this->ownInventoryOffers($tenant, $data['city'], $data['activity_date']));

        $offers = array_map(function (array $item) use ($tenant, $role, $data, $travellerCount) {
            // Activity tickets are priced per person: results show (and
            // checkout charges) the total for everyone in the search.
            $item['net_amount_per_person'] = $item['net_amount'];
            $item['net_amount'] = (string) BigDecimal::of((string) $item['net_amount'])->multipliedBy($travellerCount)->toScale(2, RoundingMode::HALF_UP);
            $pricing = $this->markupService->applyMarkup($tenant, Booking::MODULE_ACTIVITY, $role, $item['net_amount'], [
                'passenger_count' => $travellerCount,
                'origin' => $data['city'],
                // Activities on this platform are domestic-issued only.
                'is_international' => false,
                'supplier_code' => $item['supplier_code'] ?? null,
            ]);

            return array_merge($item, $pricing);
        }, $offers);

        usort($offers, fn ($a, $b) => $a['gross_amount'] <=> $b['gross_amount']);

        // Same margin-withholding rule as FlightBookingController::search().
        if (! $canViewMargin) {
            $offers = array_map(function (array $offer) {
                unset($offer['net_amount'], $offer['net_amount_per_person'], $offer['markup_amount']);

                return $offer;
            }, $offers);
        }

        return response()->json([
            'data' => [
                'city' => $data['city'],
                'activity_date' => $data['activity_date'],
                'travellers' => $travellerCount,
                'can_view_margin' => $canViewMargin,
                'offers' => $offers,
            ],
        ]);
    }

    /**
     * A tenant's own manually-entered activity inventory for this exact
     * city/date, shaped and merged into the offer list exactly like a real
     * supplier's results — mirrors CabBookingController::ownInventoryOffers().
     * No time-slot picker in this first cut: an own-inventory row's
     * activity_date must match the search exactly, the same convention
     * every other module already uses.
     */
    protected function ownInventoryOffers($tenant, string $city, string $activityDate): array
    {
        $tenantId = $tenant?->id;

        $items = ActivityInventoryItem::query()
            ->where('status', ActivityInventoryItem::STATUS_ACTIVE)
            ->where('city', $city)
            ->whereDate('activity_date', $activityDate)
            ->where(function ($q) use ($tenantId) {
                $q->whereNull('tenant_id')
                    ->orWhere('tenant_id', $tenantId)
                    ->orWhere('share_with_network', true)
                    ->when(is_null($tenantId), fn ($q2) => $q2->orWhere('share_on_platform_site', true));
            })
            ->get();

        return $items->filter->isSellable()->map(fn (ActivityInventoryItem $item) => $item->toActivityOfferArray())->values()->all();
    }

    /**
     * Holds a Booking for the one activity slot the customer picked — one
     * priceCheck()/holdBooking() supplier round-trip (or the own-inventory
     * equivalent), then persists the Booking + its single BookingSegment +
     * travellers, exactly like CabBookingController::hold() but for an
     * activity slot instead of a ride.
     *
     * Populates the voucher metadata (city/provider_name/category/
     * duration_minutes/meeting_point) that activity-voucher.blade.php reads
     * directly from the $segment array here, at creation time — built
     * correctly from the start, the same lesson Cab/Insurance/Cruise's
     * hold() methods already applied.
     */
    public function hold(Request $request)
    {
        $data = $request->validate([
            'supplier_code' => ['required', 'string'],
            'external_id' => ['required', 'string'],
            'segment' => ['nullable', 'array'],
            'activity_date' => ['required', 'date_format:Y-m-d'],
            'contact_email' => ['nullable', 'email', 'max:191'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'passengers' => ['required', 'array', 'min:1', 'max:12'],
            'passengers.*.name' => ['required', 'string', 'max:191'],
        ]);

        $user = $request->user();
        $tenant = $user->tenant;
        $role = $this->markupRoleFor($user);
        $travellerCount = count($data['passengers']);
        $segment = $data['segment'] ?? [];

        if ($data['supplier_code'] === ActivityInventoryItem::SUPPLIER_CODE) {
            $fareRule = $this->ownInventoryPriceCheck($data['external_id']);
        } else {
            try {
                $fareRule = $this->orchestrator->priceCheck($tenant, $data['supplier_code'], $data['external_id']);
            } catch (SupplierException $e) {
                return response()->json(['message' => $e->getMessage()], 502);
            }
        }

        if (! $fareRule->isStillAvailable) {
            return response()->json(['message' => 'This activity is no longer available. Please search again.'], 409);
        }

        $totalNet = (string) BigDecimal::of($fareRule->confirmedNetAmount)->multipliedBy($travellerCount)->toScale(2, RoundingMode::HALF_UP);

        $pricing = $this->markupService->applyMarkup($tenant, Booking::MODULE_ACTIVITY, $role, $totalNet, [
            'passenger_count' => $travellerCount,
            'origin' => $segment['origin'] ?? null,
            'is_international' => false,
            'supplier_code' => $data['supplier_code'],
        ]);

        $booking = Booking::create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $user->id,
            'channel' => Booking::channelForTenant($tenant),
            'module' => Booking::MODULE_ACTIVITY,
            'supplier_code' => $data['supplier_code'],
            'is_international' => false,
            'gross_amount' => $pricing['gross_amount'],
            'supplier_net_amount' => $totalNet,
            'markup_amount' => $pricing['markup_amount'],
            'travel_date_start' => $data['activity_date'],
            'passenger_count' => $travellerCount,
            'contact_email' => $data['contact_email'] ?? $user->email,
            'contact_phone' => $data['contact_phone'] ?? $user->phone,
            'metadata' => [
                'city' => $segment['origin'] ?? null,
                'provider_name' => $segment['carrier_or_provider'] ?? null,
                'category' => $segment['fare_basis'] ?? null,
                'duration_minutes' => $segment['duration_minutes'] ?? null,
                'meeting_point' => $segment['boarding_point'] ?? null,
            ],
        ]);

        foreach ($data['passengers'] as $passenger) {
            $booking->passengers()->create([
                'name' => $passenger['name'],
                'passenger_type' => BookingPassenger::TYPE_ADULT,
            ]);
        }

        if ($segment !== []) {
            $rawPayload = $segment;
            $rawPayload['is_refundable'] = $fareRule->isRefundable;

            $booking->segments()->create([
                'sequence' => 1,
                'origin' => $segment['origin'] ?? null,
                'destination' => $segment['destination'] ?? null,
                'departure_at' => $segment['departure_at'] ?? null,
                'arrival_at' => $segment['arrival_at'] ?? null,
                'carrier_or_provider' => $segment['carrier_or_provider'] ?? null,
                'fare_basis' => $segment['fare_basis'] ?? null,
                'raw_supplier_payload' => $rawPayload,
            ]);
        }

        if ($data['supplier_code'] === ActivityInventoryItem::SUPPLIER_CODE) {
            try {
                $hold = $this->ownInventoryHold($data['external_id']);
            } catch (\RuntimeException $e) {
                $this->statusService->transition($booking, Booking::STATUS_FAILED, $user, $e->getMessage());

                return response()->json(['message' => $e->getMessage()], 409);
            }
        } else {
            try {
                $hold = $this->orchestrator->hold($tenant, $data['supplier_code'], $data['external_id'], $data['passengers']);
            } catch (SupplierException $e) {
                $this->statusService->transition($booking, Booking::STATUS_FAILED, $user, $e->getMessage());

                return response()->json(['message' => $e->getMessage()], 502);
            }
        }

        $booking->update(['supplier_booking_ref' => $hold->supplierBookingRef]);
        $this->statusService->transition($booking, Booking::STATUS_HELD, $user, 'Supplier hold confirmed.');

        return response()->json([
            'data' => new BookingResource($booking->fresh(['passengers', 'segments'])),
            'hold_expires_at' => $hold->holdExpiresAt->format(DATE_ATOM),
        ], 201);
    }

    /**
     * Confirms payment for a HELD activity booking — wallet, gateway, or a
     * wallet+gateway split — identical logic to
     * CabBookingController::confirm() (no international restriction: an
     * activity booking is never international here), minus coupon/
     * ancillary handling this first cut doesn't have.
     */
    public function confirm(Request $request, Booking $booking)
    {
        $user = $request->user();
        abort_unless($booking->module === Booking::MODULE_ACTIVITY, 404);

        if ($booking->status !== Booking::STATUS_HELD) {
            return response()->json(['message' => "Booking must be 'held' to confirm; it is currently '{$booking->status}'."], 409);
        }

        $data = $request->validate([
            'wallet_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $payable = BigDecimal::of($booking->recalculateTotal());
        $requestedWalletAmount = BigDecimal::of((string) ($data['wallet_amount'] ?? '0'));

        if ($requestedWalletAmount->isGreaterThan($payable)) {
            return response()->json(['message' => 'Wallet amount cannot exceed the payable amount.'], 422);
        }

        $wallet = Wallet::query()->firstOrCreate([
            'tenant_id' => $booking->tenant_id,
            'owner_type' => Wallet::OWNER_USER,
            'owner_id' => $user->id,
        ]);

        if ($requestedWalletAmount->isGreaterThan(0) && $requestedWalletAmount->isGreaterThan(BigDecimal::of($wallet->balance))) {
            return response()->json([
                'message' => 'Wallet amount exceeds your available balance.',
                'wallet_balance' => (string) $wallet->balance,
            ], 402);
        }

        $walletAmount = (string) $requestedWalletAmount->toScale(2, RoundingMode::HALF_UP);
        $remainder = $payable->minus($requestedWalletAmount)->toScale(2, RoundingMode::HALF_UP);

        if (! $remainder->isPositive()) {
            try {
                DB::transaction(function () use ($booking, $wallet, $payable, $walletAmount, $user) {
                    $this->walletService->debit(
                        $wallet,
                        $walletAmount,
                        WalletLedgerEntry::REF_BOOKING_PAYMENT,
                        $booking->id,
                        "Payment for booking {$booking->booking_reference}",
                        $user->id,
                    );

                    $booking->total_amount = (string) $payable;
                    $booking->save();
                });
            } catch (InsufficientBalanceException $e) {
                return response()->json(['message' => $e->getMessage()], 402);
            }

            $this->statusService->transition($booking, Booking::STATUS_CONFIRMED, $user, 'Wallet payment captured.');

            return $this->finalizeTicketing($booking, $user);
        }

        $transaction = $this->paymentGatewayService->initiate(
            $booking,
            (string) $remainder,
            $walletAmount,
            $requestedWalletAmount->isGreaterThan(0) ? $wallet : null,
        );

        return response()->json([
            'requires_payment' => true,
            'data' => new BookingResource($booking->fresh(['passengers', 'segments'])),
            'payment' => [
                'transaction_id' => $transaction->id,
                'gateway' => $transaction->gateway,
                'amount' => $transaction->amount,
                'wallet_amount_applied' => $walletAmount,
                'currency' => $transaction->currency,
                'available_methods' => $this->paymentGatewayService->availableMethods($booking->tenant),
            ],
        ]);
    }

    /**
     * The gateway leg of confirm() — mirrors
     * CabBookingController::payGateway() exactly, minus coupons.
     */
    public function payGateway(Request $request, Booking $booking)
    {
        $user = $request->user();
        abort_unless($booking->module === Booking::MODULE_ACTIVITY, 404);

        if ($booking->status !== Booking::STATUS_HELD) {
            return response()->json(['message' => "Booking must be 'held' to pay; it is currently '{$booking->status}'."], 409);
        }

        $transaction = PaymentTransaction::query()
            ->where('booking_id', $booking->id)
            ->where('status', PaymentTransaction::STATUS_INITIATED)
            ->latest()
            ->first();

        if (! $transaction) {
            return response()->json(['message' => 'No pending payment was found for this booking. Call confirm first.'], 409);
        }

        $data = $request->validate([
            'method' => ['required', 'string', 'in:'.implode(',', PaymentMethodRoute::METHODS)],
        ]);
        $method = $data['method'];
        $payable = $booking->recalculateTotal();

        try {
            DB::transaction(function () use ($booking, $transaction, $payable, $user, $method) {
                $walletAmount = BigDecimal::of((string) $transaction->wallet_amount);
                if ($walletAmount->isGreaterThan(0)) {
                    $wallet = $transaction->wallet_id ? Wallet::withoutTenantScope()->find($transaction->wallet_id) : null;

                    abort_if(! $wallet, 500, 'Wallet leg on this payment could not be resolved.');

                    $this->walletService->debit(
                        $wallet,
                        (string) $walletAmount,
                        WalletLedgerEntry::REF_BOOKING_PAYMENT,
                        $booking->id,
                        "Payment for booking {$booking->booking_reference}",
                        $user->id,
                    );
                }

                $this->paymentGatewayService->settle($transaction, $booking->tenant, $method);

                $booking->total_amount = $payable;
                $booking->save();
            });
        } catch (InsufficientBalanceException $e) {
            return response()->json(['message' => $e->getMessage()], 402);
        }

        $this->statusService->transition($booking, Booking::STATUS_CONFIRMED, $user, "Payment gateway ({$transaction->gateway}) + wallet captured.");

        return $this->finalizeTicketing($booking, $user);
    }

    /**
     * Shared tail for both payment paths once the booking is CONFIRMED —
     * ticket with the supplier, then credit any cashback the rule engine
     * awards. Mirrors CabBookingController::finalizeTicketing(), minus the
     * multi-leg loop (an activity booking has exactly one supplier ref).
     */
    protected function finalizeTicketing(Booking $booking, User $user)
    {
        if ($booking->supplier_code === ActivityInventoryItem::SUPPLIER_CODE) {
            $confirmResult = $this->ownInventoryConfirm($booking->supplier_booking_ref);
        } else {
            try {
                $confirmResult = $this->orchestrator->confirm($booking->tenant, $booking->supplier_code, $booking->supplier_booking_ref);
            } catch (SupplierException $e) {
                $this->statusService->transition($booking, Booking::STATUS_FAILED, $user, $e->getMessage());

                return response()->json(['message' => $e->getMessage()], 502);
            }
        }

        $booking->update(['metadata' => array_merge($booking->metadata ?? [], ['ticket_numbers' => $confirmResult->ticketNumbers])]);
        $this->statusService->transition($booking, Booking::STATUS_TICKETED, $user, 'Supplier confirmation received.');

        $this->cashbackService->applyCashback($booking, $user);
        $this->commissionService->applyCommission($booking);

        return new BookingResource($booking->fresh(['passengers', 'segments']));
    }

    protected function ownActivityInventoryItemFromExternalId(string $externalId): ActivityInventoryItem
    {
        $id = (int) str_replace('inv-', '', $externalId);

        return ActivityInventoryItem::query()->findOrFail($id);
    }

    protected function ownInventoryPriceCheck(string $externalId): FareRuleResult
    {
        $item = $this->ownActivityInventoryItemFromExternalId($externalId);

        return new FareRuleResult(
            isStillAvailable: $item->isSellable(),
            confirmedNetAmount: (string) $item->net_price,
            isRefundable: (bool) $item->is_refundable,
        );
    }

    protected function ownInventoryHold(string $externalId): BookingHoldResult
    {
        $item = $this->ownActivityInventoryItemFromExternalId($externalId);

        if (! $item->isSellable()) {
            throw new \RuntimeException('This own-inventory activity is no longer available.');
        }

        if ($item->capacity !== null) {
            $updated = ActivityInventoryItem::query()
                ->where('id', $item->id)
                ->where('capacity', '>', 0)
                ->decrement('capacity');

            if (! $updated) {
                throw new \RuntimeException('This own-inventory activity just sold out.');
            }
        }

        return new BookingHoldResult(
            supplierBookingRef: ActivityInventoryItem::SUPPLIER_CODE.'-'.$item->id.'-'.uniqid(),
            holdExpiresAt: new \DateTimeImmutable('+30 minutes'),
            netAmount: (string) $item->net_price,
        );
    }

    protected function ownInventoryConfirm(string $supplierBookingRef): BookingConfirmResult
    {
        $parts = explode('-', $supplierBookingRef);
        $itemId = $parts[1] ?? null;

        return new BookingConfirmResult(
            supplierBookingRef: $supplierBookingRef,
            isTicketed: true,
            ticketNumbers: ['OWNINV-'.($itemId ?? '0').'-'.strtoupper(substr(uniqid(), -6))],
        );
    }

    /**
     * The booking detail read for the new customer-facing flow — mirrors
     * CabBookingController::show().
     */
    public function show(Request $request, Booking $booking)
    {
        abort_unless($booking->module === Booking::MODULE_ACTIVITY, 404);

        return new BookingResource($booking->load(['passengers', 'segments', 'statusHistory']));
    }

    /** Mirrors FlightBookingController::markupRoleFor() — null is a guest, priced as 'b2c'. */
    protected function markupRoleFor(?User $user): string
    {
        return match ($user?->role) {
            User::ROLE_AGENT, User::ROLE_PARTNER_ADMIN => 'agent',
            User::ROLE_SUB_AGENT => 'sub_agent',
            default => 'b2c',
        };
    }
}
