<?php

namespace Tests\Feature;

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\BookingAmendment;
use App\Domains\Booking\Services\BookingAmendmentService;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Wallet\Models\WalletLedgerEntry;
use App\Domains\Wallet\Services\WalletService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Covers Phase 2's last bullet: reschedule/date-change/reissue as
 * first-class actions on a TICKETED booking, reusing
 * CancellationPolicyService::quote()'s band calculation for the fee — see
 * BookingAmendmentService's own docblock.
 */
class BookingAmendmentServiceTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function service(): BookingAmendmentService
    {
        return app(BookingAmendmentService::class);
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
            // 10 days out clears the fallback policy's "7+ days before
            // travel" band (90% refund / 10% fee — see
            // CancellationPolicy::FALLBACK_BANDS), so a fee_amount of
            // '100.00' on a 1000.00 booking is the fallback quote, not a
            // magic number.
            'travel_date_start' => now()->addDays(10)->toDateString(),
            'supplier_booking_ref' => 'PNR-OLD1',
        ], $attributes));
    }

    public function test_reschedule_updates_travel_dates_and_debits_the_wallet_for_the_quoted_fee(): void
    {
        $master = $this->createMasterTenant();
        $agent = $this->createChildTenant($master, Tenant::TYPE_AGENT);
        $staff = $this->createUserForTenant($master, User::ROLE_STAFF);
        $customer = $this->createUserForTenant($agent, User::ROLE_CUSTOMER);
        $booking = $this->makeBooking($agent, $customer);

        $wallet = $this->walletFor($customer);
        app(WalletService::class)->credit($wallet, '500.00', WalletLedgerEntry::REF_TOPUP);

        $newStart = now()->addDays(20)->toDateString();
        $newEnd = now()->addDays(25)->toDateString();

        $amendment = $this->service()->reschedule($booking, $staff, $newStart, $newEnd, BookingAmendment::FEE_WALLET, 'Customer requested later dates.');

        $this->assertSame(BookingAmendment::TYPE_RESCHEDULE, $amendment->type);
        $this->assertSame('100.00', (string) $amendment->fee_amount);
        $this->assertSame($newStart, $booking->fresh()->travel_date_start->toDateString());
        $this->assertSame($newEnd, $booking->fresh()->travel_date_end->toDateString());
        $this->assertNotNull($amendment->wallet_ledger_entry_id);
        $this->assertSame('400.00', (string) $wallet->fresh()->balance);
    }

    public function test_reschedule_with_waived_fee_collection_charges_nothing(): void
    {
        $master = $this->createMasterTenant();
        $agent = $this->createChildTenant($master, Tenant::TYPE_AGENT);
        $staff = $this->createUserForTenant($master, User::ROLE_STAFF);
        $customer = $this->createUserForTenant($agent, User::ROLE_CUSTOMER);
        $booking = $this->makeBooking($agent, $customer);

        $wallet = $this->walletFor($customer);

        $amendment = $this->service()->reschedule($booking, $staff, now()->addDays(15)->toDateString(), null, BookingAmendment::FEE_WAIVED);

        $this->assertSame(BookingAmendment::FEE_WAIVED, $amendment->fee_collection);
        $this->assertNull($amendment->wallet_ledger_entry_id);
        // No wallet row was ever created for this booking's owner, since
        // collectFee() short-circuits before touching the wallet at all.
        $this->assertSame('0.00', (string) $wallet->fresh()->balance);
    }

    public function test_reissue_updates_supplier_ref_and_ticket_numbers(): void
    {
        $master = $this->createMasterTenant();
        $agent = $this->createChildTenant($master, Tenant::TYPE_AGENT);
        $staff = $this->createUserForTenant($master, User::ROLE_STAFF);
        $customer = $this->createUserForTenant($agent, User::ROLE_CUSTOMER);
        $booking = $this->makeBooking($agent, $customer, [
            'metadata' => ['ticket_numbers' => ['OLD-TKT-1']],
        ]);

        $amendment = $this->service()->reissue($booking, $staff, 'PNR-NEW2', ['NEW-TKT-1', 'NEW-TKT-2'], BookingAmendment::FEE_WAIVED);

        $this->assertSame(BookingAmendment::TYPE_REISSUE, $amendment->type);
        $this->assertSame('PNR-OLD1', $amendment->previous_supplier_booking_ref);
        $this->assertSame('PNR-NEW2', $amendment->new_supplier_booking_ref);
        $this->assertSame(['OLD-TKT-1'], $amendment->previous_ticket_numbers);
        $this->assertSame(['NEW-TKT-1', 'NEW-TKT-2'], $amendment->new_ticket_numbers);

        $fresh = $booking->fresh();
        $this->assertSame('PNR-NEW2', $fresh->supplier_booking_ref);
        $this->assertSame(['NEW-TKT-1', 'NEW-TKT-2'], $fresh->metadata['ticket_numbers']);
    }

    public function test_reschedule_throws_when_booking_isnt_ticketed(): void
    {
        $master = $this->createMasterTenant();
        $agent = $this->createChildTenant($master, Tenant::TYPE_AGENT);
        $staff = $this->createUserForTenant($master, User::ROLE_STAFF);
        $customer = $this->createUserForTenant($agent, User::ROLE_CUSTOMER);
        $booking = $this->makeBooking($agent, $customer, ['status' => Booking::STATUS_CONFIRMED]);

        $this->expectException(RuntimeException::class);

        $this->service()->reschedule($booking, $staff, now()->addDays(15)->toDateString(), null, BookingAmendment::FEE_WAIVED);
    }

    public function test_reissue_throws_when_booking_isnt_ticketed(): void
    {
        $master = $this->createMasterTenant();
        $agent = $this->createChildTenant($master, Tenant::TYPE_AGENT);
        $staff = $this->createUserForTenant($master, User::ROLE_STAFF);
        $customer = $this->createUserForTenant($agent, User::ROLE_CUSTOMER);
        $booking = $this->makeBooking($agent, $customer, ['status' => Booking::STATUS_CANCELLED]);

        $this->expectException(RuntimeException::class);

        $this->service()->reissue($booking, $staff, 'PNR-NEW2', null, BookingAmendment::FEE_WAIVED);
    }

    public function test_reschedule_requires_at_least_one_new_date(): void
    {
        $master = $this->createMasterTenant();
        $agent = $this->createChildTenant($master, Tenant::TYPE_AGENT);
        $staff = $this->createUserForTenant($master, User::ROLE_STAFF);
        $customer = $this->createUserForTenant($agent, User::ROLE_CUSTOMER);
        $booking = $this->makeBooking($agent, $customer);

        $this->expectException(\InvalidArgumentException::class);

        $this->service()->reschedule($booking, $staff, null, null, BookingAmendment::FEE_WAIVED);
    }

    public function test_reissue_requires_a_new_ref_or_ticket_numbers(): void
    {
        $master = $this->createMasterTenant();
        $agent = $this->createChildTenant($master, Tenant::TYPE_AGENT);
        $staff = $this->createUserForTenant($master, User::ROLE_STAFF);
        $customer = $this->createUserForTenant($agent, User::ROLE_CUSTOMER);
        $booking = $this->makeBooking($agent, $customer);

        $this->expectException(\InvalidArgumentException::class);

        $this->service()->reissue($booking, $staff, null, null, BookingAmendment::FEE_WAIVED);
    }

    public function test_fee_is_computed_off_the_current_travel_date_before_the_change_is_applied(): void
    {
        $master = $this->createMasterTenant();
        $agent = $this->createChildTenant($master, Tenant::TYPE_AGENT);
        $staff = $this->createUserForTenant($master, User::ROLE_STAFF);
        $customer = $this->createUserForTenant($agent, User::ROLE_CUSTOMER);
        // travel_date_start is a date-only column, so "today" is as close
        // to travel as this quote can get without going negative — well
        // inside the fallback's "less than 24 hours / no-show" band (0%
        // refund / 100% fee), which applies to the CURRENT date even
        // though the new date pushed out to 30 days would have cleared the
        // best (90%) band instead.
        $booking = $this->makeBooking($agent, $customer, [
            'travel_date_start' => now()->toDateString(),
        ]);

        $amendment = $this->service()->reschedule($booking, $staff, now()->addDays(30)->toDateString(), null, BookingAmendment::FEE_WAIVED);

        $this->assertSame('1000.00', (string) $amendment->fee_amount);
    }
}
