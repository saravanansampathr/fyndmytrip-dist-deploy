<?php

namespace App\Console\Commands;

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Services\BookingStatusService;
use App\Domains\Commission\Models\CommissionRule;
use App\Domains\Commission\Services\CommissionService;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Models\WalletLedgerEntry;
use App\Domains\Wallet\Services\WalletService;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * "All services all min 20 dummy or test services and bookings in all
 * stages to see how it look like... to chk how the platform works and
 * wallet system and margin, profit calculations." Creates 27 real Booking
 * rows (9 product modules × 3 each) spread across every channel in the
 * demo tenant hierarchy and every stage in BookingStatusService's state
 * machine, using the real WalletService/CommissionService/
 * BookingStatusService code paths rather than raw inserts — so the wallet
 * ledger, running balances, markup, and commission payouts this produces
 * are genuinely exercised, not faked separately from what a real booking
 * would do.
 *
 * Requires TenantHierarchySeeder to have already run (php artisan db:seed
 * --class=TenantHierarchySeeder) — this reuses its demo tenants/users by
 * their known emails rather than creating a second, divergent set.
 *
 * Safe to re-run: every booking gets a fresh booking_reference, so re-
 * running adds another batch rather than erroring, but running it twice
 * will double the numbers in reports — run it once per demo refresh, or
 * add --fresh to delete this command's own previous batch first (tagged
 * via metadata.demo_seed = true, never touching any real booking).
 */
class SeedDemoBookings extends Command
{
    protected $signature = 'demo:seed-bookings {--fresh : Delete bookings previously created by this command before seeding again}';

    protected $description = 'Seed ~27 demo bookings across every product module, channel, and booking stage, with real wallet/commission movement, for UI and margin-calculation review.';

    protected const MODULES = [
        Booking::MODULE_FLIGHT => ['net' => '4500.00', 'markup' => '300.00'],
        Booking::MODULE_HOTEL => ['net' => '3200.00', 'markup' => '400.00'],
        Booking::MODULE_BUS => ['net' => '800.00', 'markup' => '100.00'],
        Booking::MODULE_CAB => ['net' => '1200.00', 'markup' => '150.00'],
        Booking::MODULE_HOLIDAY => ['net' => '28000.00', 'markup' => '4000.00'],
        Booking::MODULE_INSURANCE => ['net' => '450.00', 'markup' => '50.00'],
        Booking::MODULE_VISA => ['net' => '2500.00', 'markup' => '300.00'],
        Booking::MODULE_CRUISE => ['net' => '35000.00', 'markup' => '5000.00'],
        Booking::MODULE_ACTIVITY => ['net' => '1500.00', 'markup' => '200.00'],
    ];

    public function handle(
        WalletService $walletService,
        CommissionService $commissionService,
        BookingStatusService $statusService,
    ): int {
        if ($this->option('fresh')) {
            $previous = Booking::withoutTenantScope()->where('metadata->demo_seed', true)->get();
            $previous->each(fn (Booking $b) => $b->forceDelete());
            $this->info("Deleted {$previous->count()} previously seeded demo booking(s).");
        }

        $customer = $this->requireUser('customer@fyndmytrip.com', 'B2C customer');
        $agent = $this->requireUser('agent@acmetravel.fyndmytrip.com', 'Agent');
        $partnerAdmin = $this->requireUser('admin@acmetravel.fyndmytrip.com', 'White-Label Partner Admin');
        $subAgent = $this->requireUser('subagent@acmetravel.fyndmytrip.com', 'Sub-Agent');
        $directAgent = $this->requireUser('agent@fyndmytrip.com', 'Direct Agent');
        $apiPartnerUser = $this->requireUser('api@fyndmytrip.com', 'API Partner staff');

        if (! $customer || ! $agent || ! $partnerAdmin || ! $subAgent || ! $directAgent || ! $apiPartnerUser) {
            $this->error('Run `php artisan db:seed --class=TenantHierarchySeeder` first — this command builds on those demo accounts.');

            return self::FAILURE;
        }

        $this->ensureCommissionRules();

        // Slots A and B pay for every one of the 9 modules out of these two
        // wallets in a single run (total ~₹87k across both at the prices
        // above) — comfortably more than either demo wallet's existing
        // balance (₹10k / ₹50k from TenantHierarchySeeder), which would
        // otherwise throw InsufficientBalanceException partway through.
        // This is a flat demo top-up, not a "fill the gap" computation —
        // safe to run more than once, and clearly remarked in the ledger.
        $this->topUp($walletService, $customer, '200000.00');
        $this->topUp($walletService, $agent, '200000.00');

        // Slot C rotates through unpaid/in-progress stages and the four
        // remaining demo tenants, so every stage BookingStatusService
        // allows gets at least one booking resting in it, not just the
        // happy/refund paths slots A and B cover.
        $slotCPlan = [
            ['user' => $partnerAdmin, 'stage' => Booking::STATUS_CART],
            ['user' => $subAgent, 'stage' => Booking::STATUS_HELD],
            ['user' => $directAgent, 'stage' => Booking::STATUS_CONFIRMED],
            ['user' => $apiPartnerUser, 'stage' => Booking::STATUS_FAILED],
        ];

        $moduleIndex = 0;
        $created = 0;

        foreach (self::MODULES as $module => $pricing) {
            $this->line("Seeding {$module}…");

            $this->seatTicketed($customer, $module, $pricing, $statusService, $walletService, $commissionService);
            $created++;

            // Every even module index rides all the way to REFUNDED; the
            // last module (activity) deliberately stops at plain CANCELLED
            // instead of continuing into a refund, so that resting stage
            // is represented too — otherwise nothing in this command would
            // ever end up sitting at CANCELLED rather than passing through
            // it on the way to a refund.
            $refundOutcome = $module === Booking::MODULE_ACTIVITY
                ? 'cancelled_only'
                : ($moduleIndex % 2 === 0 ? 'refunded' : 'refund_processing');

            $this->sellThenUnwind($agent, $module, $pricing, $refundOutcome, $statusService, $walletService, $commissionService);
            $created++;

            $slot = $slotCPlan[$moduleIndex % count($slotCPlan)];
            $this->inProgress($slot['user'], $module, $pricing, $slot['stage'], $statusService);
            $created++;

            $moduleIndex++;
        }

        $this->info("Done — created {$created} demo bookings across ".count(self::MODULES).' modules.');
        $this->line('Reminder: this triggers real wallet-notification emails through whatever mailer is configured (MAIL_MAILER) — expect a burst of them if that\'s a live SMTP/SES/SendGrid provider rather than the "log" driver.');

        return self::SUCCESS;
    }

    /**
     * Commission only ever pays out if a matching CommissionRule exists —
     * CommissionService::applyCommission() is a silent no-op without one.
     * module => null here means "every module", so one rule per reseller
     * type at Master level covers all nine without 36 separate rows.
     */
    protected function ensureCommissionRules(): void
    {
        $master = Tenant::query()->where('type', Tenant::TYPE_MASTER)->firstOrFail();

        foreach (CommissionRule::APPLIES_TO_TYPES as $type) {
            CommissionRule::withoutTenantScope()->firstOrCreate(
                ['tenant_id' => $master->id, 'applies_to_type' => $type, 'module' => null],
                [
                    'name' => "Demo {$type} commission",
                    'rule_type' => CommissionRule::TYPE_PERCENTAGE,
                    'config' => ['percentage' => '10.0'],
                    'booking_scope' => CommissionRule::SCOPE_BOTH,
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * Slot A: the straightforward successful sale, every module, always
     * through the B2C customer — cart → held → confirmed → ticketed, paid
     * in full from the customer's own wallet. No commission (Master
     * bookings never earn one — nobody sits above Master to pay it).
     */
    protected function seatTicketed(
        User $customer,
        string $module,
        array $pricing,
        BookingStatusService $statusService,
        WalletService $walletService,
        CommissionService $commissionService,
    ): void {
        $booking = $this->makeBooking($customer, $module, $pricing, Booking::CHANNEL_DIRECT);

        $this->advance($booking, [Booking::STATUS_HELD, Booking::STATUS_CONFIRMED, Booking::STATUS_TICKETED], $statusService, $customer);

        $wallet = $this->walletFor($customer);
        $walletService->debit($wallet, $booking->total_amount, WalletLedgerEntry::REF_BOOKING_PAYMENT, $booking->id, "Payment for {$module} booking {$booking->booking_reference}");

        $commissionService->applyCommission($booking->fresh()); // no-op for Master — kept for parity with the other slots.
    }

    /**
     * Slot B: a sold-and-paid booking (ticketed, commission paid to the
     * agent's own wallet) that then gets cancelled and either stops there,
     * sits mid-refund, or completes the refund — covering margin/commission
     * behaviour on the unhappy path, not just the happy one.
     */
    protected function sellThenUnwind(
        User $agent,
        string $module,
        array $pricing,
        string $outcome,
        BookingStatusService $statusService,
        WalletService $walletService,
        CommissionService $commissionService,
    ): void {
        $booking = $this->makeBooking($agent, $module, $pricing, Booking::CHANNEL_AGENT);

        $this->advance($booking, [Booking::STATUS_HELD, Booking::STATUS_CONFIRMED, Booking::STATUS_TICKETED], $statusService, $agent);

        $wallet = $this->walletFor($agent);
        $walletService->debit($wallet, $booking->total_amount, WalletLedgerEntry::REF_BOOKING_PAYMENT, $booking->id, "Payment for {$module} booking {$booking->booking_reference}");
        $commissionService->applyCommission($booking->fresh());

        $this->advance($booking, [Booking::STATUS_CANCELLED], $statusService, $agent, 'Demo cancellation');

        if ($outcome === 'cancelled_only') {
            return;
        }

        $this->advance($booking, [Booking::STATUS_REFUND_PROCESSING], $statusService, $agent, 'Refund requested');

        if ($outcome === 'refunded') {
            $this->advance($booking, [Booking::STATUS_REFUNDED], $statusService, $agent, 'Refund completed');
            $walletService->credit($wallet, $booking->total_amount, WalletLedgerEntry::REF_REFUND, $booking->id, "Refund for cancelled {$module} booking {$booking->booking_reference}");
        }
    }

    /**
     * Slot C: an in-progress or dead-end booking that never took payment —
     * sitting at cart, held, confirmed, or failed. No wallet/commission
     * movement, since nothing has actually been paid for yet at any of
     * these stages in a real flow.
     */
    protected function inProgress(
        User $user,
        string $module,
        array $pricing,
        string $stage,
        BookingStatusService $statusService,
    ): void {
        $booking = $this->makeBooking($user, $module, $pricing, $this->channelFor($user));

        $path = match ($stage) {
            Booking::STATUS_CART => [],
            Booking::STATUS_HELD => [Booking::STATUS_HELD],
            Booking::STATUS_CONFIRMED => [Booking::STATUS_HELD, Booking::STATUS_CONFIRMED],
            Booking::STATUS_FAILED => [Booking::STATUS_FAILED],
            default => [],
        };

        $this->advance($booking, $path, $statusService, $user, $stage === Booking::STATUS_FAILED ? 'Demo: payment never completed' : null);
    }

    protected function makeBooking(User $user, string $module, array $pricing, string $channel): Booking
    {
        $net = $pricing['net'];
        $markup = $pricing['markup'];
        // brick/math, not bcmath — this environment's own convention (see
        // WalletService's docblock): no bcmath extension to rely on, and
        // PHP floats can't represent money exactly either.
        $gross = (string) BigDecimal::of($net)->plus($markup)->toScale(2, RoundingMode::HALF_UP);

        return Booking::create([
            'tenant_id' => $user->tenant_id,
            'created_by_user_id' => $user->id,
            'module' => $module,
            'channel' => $channel,
            'supplier_code' => 'MOCKAIR',
            'supplier_booking_ref' => 'DEMO-'.Str::upper(Str::random(8)),
            'gross_amount' => $gross,
            'supplier_net_amount' => $net,
            'markup_amount' => $markup,
            'total_amount' => $gross,
            'currency' => 'INR',
            'travel_date_start' => now()->addDays(14)->toDateString(),
            'travel_date_end' => now()->addDays(17)->toDateString(),
            'contact_email' => $user->email,
            'metadata' => [
                'demo_seed' => true,
                'note' => "Seeded by demo:seed-bookings for {$module} UI/margin review.",
            ],
        ]);
    }

    /**
     * @param array<int, string> $toStatuses
     */
    protected function advance(Booking $booking, array $toStatuses, BookingStatusService $statusService, User $changedBy, ?string $reason = null): void
    {
        foreach ($toStatuses as $status) {
            $statusService->transition($booking, $status, $changedBy, $reason);
        }
    }

    protected function topUp(WalletService $walletService, User $user, string $amount): void
    {
        $wallet = $this->walletFor($user);
        $walletService->credit($wallet, $amount, WalletLedgerEntry::REF_ADJUSTMENT, null, 'Demo top-up so demo:seed-bookings can pay for every seeded booking');
    }

    protected function walletFor(User $user): Wallet
    {
        return Wallet::withoutTenantScope()->firstOrCreate([
            'tenant_id' => $user->tenant_id,
            'owner_type' => Wallet::OWNER_USER,
            'owner_id' => $user->id,
        ]);
    }

    protected function channelFor(User $user): string
    {
        $tenant = Tenant::find($user->tenant_id);

        return match ($tenant?->type) {
            Tenant::TYPE_WHITE_LABEL => Booking::CHANNEL_WHITE_LABEL,
            Tenant::TYPE_SUB_AGENT => Booking::CHANNEL_SUB_AGENT,
            Tenant::TYPE_AGENT => Booking::CHANNEL_AGENT,
            Tenant::TYPE_API_PARTNER => Booking::CHANNEL_API_PARTNER,
            default => Booking::CHANNEL_DIRECT,
        };
    }

    protected function requireUser(string $email, string $label): ?User
    {
        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->warn("Missing demo user '{$label}' ({$email}).");
        }

        return $user;
    }
}
