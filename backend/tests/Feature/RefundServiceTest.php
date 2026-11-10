<?php

namespace Tests\Feature;

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\RefundTransaction;
use App\Domains\Booking\Services\RefundService;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Wallet\Models\Wallet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Covers Phase 2's second slice: refund issuance three ways (gateway, bank
 * transfer, wallet) and the REFUND_PROCESSING "in process" status between
 * initiating a refund and the money actually landing — see RefundService's
 * own docblock for the state machine.
 */
class RefundServiceTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function service(): RefundService
    {
        return app(RefundService::class);
    }

    protected function makeBooking(Tenant $tenant, User $customer, array $attributes = []): Booking
    {
        return Booking::create(array_merge([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $customer->id,
            'module' => Booking::MODULE_FLIGHT,
            'supplier_code' => 'MOCKAIR',
            'gross_amount' => '1000.00',
            'supplier_net_amount' => '900.00',
            'status' => Booking::STATUS_TICKETED,
            'travel_date_start' => now()->addDays(10)->toDateString(),
        ], $attributes));
    }

    public function test_wallet_refund_completes_instantly_and_credits_the_customers_wallet(): void
    {
        $master = $this->createMasterTenant();
        $agent = $this->createChildTenant($master, Tenant::TYPE_AGENT);
        $staff = $this->createUserForTenant($master, User::ROLE_STAFF);
        $customer = $this->createUserForTenant($agent, User::ROLE_CUSTOMER);
        $booking = $this->makeBooking($agent, $customer);

        $refund = $this->service()->initiate($booking, $staff, RefundTransaction::METHOD_WALLET, '400.00');

        $this->assertSame(RefundTransaction::STATUS_COMPLETED, $refund->status);
        $this->assertSame(Booking::STATUS_REFUNDED, $booking->fresh()->status);
        $this->assertNotNull($refund->wallet_ledger_entry_id);

        $wallet = Wallet::query()->where([
            'tenant_id' => $agent->id,
            'owner_type' => Wallet::OWNER_USER,
            'owner_id' => $customer->id,
        ])->firstOrFail();
        $this->assertSame('400.00', (string) $wallet->balance);
    }

    public function test_gateway_refund_stays_processing_until_staff_complete_it(): void
    {
        $master = $this->createMasterTenant();
        $agent = $this->createChildTenant($master, Tenant::TYPE_AGENT);
        $staff = $this->createUserForTenant($master, User::ROLE_STAFF);
        $customer = $this->createUserForTenant($agent, User::ROLE_CUSTOMER);
        $booking = $this->makeBooking($agent, $customer);

        $refund = $this->service()->initiate($booking, $staff, RefundTransaction::METHOD_GATEWAY, '600.00');

        $this->assertSame(RefundTransaction::STATUS_PROCESSING, $refund->status);
        $this->assertSame(Booking::STATUS_REFUND_PROCESSING, $booking->fresh()->status);
        $this->assertSame(Booking::STATUS_TICKETED, $refund->previous_booking_status);

        $completed = $this->service()->complete($refund, $staff, 'GTW-REF-123');

        $this->assertSame(RefundTransaction::STATUS_COMPLETED, $completed->status);
        $this->assertSame('GTW-REF-123', $completed->gateway_reference);
        $this->assertSame(Booking::STATUS_REFUNDED, $booking->fresh()->status);
    }

    public function test_failing_a_refund_reverts_the_booking_to_its_previous_status(): void
    {
        $master = $this->createMasterTenant();
        $agent = $this->createChildTenant($master, Tenant::TYPE_AGENT);
        $staff = $this->createUserForTenant($master, User::ROLE_STAFF);
        $customer = $this->createUserForTenant($agent, User::ROLE_CUSTOMER);
        $booking = $this->makeBooking($agent, $customer, ['status' => Booking::STATUS_CANCELLED]);

        $refund = $this->service()->initiate($booking, $staff, RefundTransaction::METHOD_BANK_TRANSFER, '1000.00', [
            'bank_account_name' => 'Test Customer',
            'bank_account_number' => '1234567890',
            'bank_ifsc' => 'TEST0001234',
        ]);
        $this->assertSame(Booking::STATUS_REFUND_PROCESSING, $booking->fresh()->status);

        $failed = $this->service()->fail($refund, $staff, 'Bank rejected the account details.');

        $this->assertSame(RefundTransaction::STATUS_FAILED, $failed->status);
        $this->assertSame('Bank rejected the account details.', $failed->failure_reason);
        // Reverted to CANCELLED, not TICKETED — the state it was actually in
        // before this refund was initiated.
        $this->assertSame(Booking::STATUS_CANCELLED, $booking->fresh()->status);
    }

    public function test_cannot_initiate_a_refund_from_a_status_that_isnt_ticketed_or_cancelled(): void
    {
        $master = $this->createMasterTenant();
        $agent = $this->createChildTenant($master, Tenant::TYPE_AGENT);
        $staff = $this->createUserForTenant($master, User::ROLE_STAFF);
        $customer = $this->createUserForTenant($agent, User::ROLE_CUSTOMER);
        $booking = $this->makeBooking($agent, $customer, ['status' => Booking::STATUS_HELD]);

        $this->expectException(RuntimeException::class);

        $this->service()->initiate($booking, $staff, RefundTransaction::METHOD_WALLET, '100.00');
    }

    public function test_refund_amount_cannot_exceed_the_booking_total(): void
    {
        $master = $this->createMasterTenant();
        $agent = $this->createChildTenant($master, Tenant::TYPE_AGENT);
        $staff = $this->createUserForTenant($master, User::ROLE_STAFF);
        $customer = $this->createUserForTenant($agent, User::ROLE_CUSTOMER);
        $booking = $this->makeBooking($agent, $customer);

        $this->expectException(\InvalidArgumentException::class);

        $this->service()->initiate($booking, $staff, RefundTransaction::METHOD_WALLET, '5000.00');
    }

    public function test_completing_or_failing_an_already_resolved_refund_throws(): void
    {
        $master = $this->createMasterTenant();
        $agent = $this->createChildTenant($master, Tenant::TYPE_AGENT);
        $staff = $this->createUserForTenant($master, User::ROLE_STAFF);
        $customer = $this->createUserForTenant($agent, User::ROLE_CUSTOMER);
        $booking = $this->makeBooking($agent, $customer);

        $refund = $this->service()->initiate($booking, $staff, RefundTransaction::METHOD_WALLET, '100.00');
        $this->assertSame(RefundTransaction::STATUS_COMPLETED, $refund->status);

        $this->expectException(RuntimeException::class);

        $this->service()->complete($refund, $staff);
    }
}
