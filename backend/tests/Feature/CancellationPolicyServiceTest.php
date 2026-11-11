<?php

namespace Tests\Feature;

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\CancellationPolicy;
use App\Domains\Booking\Services\CancellationPolicyService;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Covers Phase 2's cancellation/refund policy engine: band selection by
 * hours-until-travel, refund/fee computation, most-specific-policy-wins
 * matching (mirrors MarkupServiceTest's coverage of the same tie-break
 * idea in MarkupService), and the conservative FALLBACK_BANDS default
 * when no policy has been configured at all.
 */
class CancellationPolicyServiceTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function service(): CancellationPolicyService
    {
        return app(CancellationPolicyService::class);
    }

    protected function makeBooking(Tenant $tenant, array $attributes = []): Booking
    {
        $user = $this->createUserForTenant($tenant, \App\Models\User::ROLE_AGENT);

        return Booking::create(array_merge([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $user->id,
            'module' => Booking::MODULE_FLIGHT,
            'supplier_code' => 'MOCKAIR',
            'gross_amount' => '1000.00',
            'supplier_net_amount' => '900.00',
        ], $attributes));
    }

    public function test_no_policy_configured_falls_back_to_conservative_default(): void
    {
        $master = $this->createMasterTenant();
        $agent = $this->createChildTenant($master, Tenant::TYPE_AGENT);

        $booking = $this->makeBooking($agent, [
            'travel_date_start' => now()->addDays(10)->toDateString(),
        ]);

        $quote = $this->service()->quote($booking);

        $this->assertTrue($quote['used_fallback']);
        $this->assertNull($quote['policy_id']);
        // FALLBACK_BANDS' 7+ days band: 90% refund, no flat fee.
        $this->assertSame('900.00', $quote['refund_amount']);
        $this->assertSame('100.00', $quote['fee_amount']);
    }

    public function test_band_is_selected_by_hours_until_travel(): void
    {
        $master = $this->createMasterTenant();
        $agent = $this->createChildTenant($master, Tenant::TYPE_AGENT);

        CancellationPolicy::query()->create([
            'tenant_id' => $agent->id,
            'name' => 'Agent default',
            'bands' => [
                ['hours_before_min' => 168, 'refund_percentage' => '90.00', 'flat_fee' => '0.00', 'label' => '7+ days'],
                ['hours_before_min' => 72, 'refund_percentage' => '50.00', 'flat_fee' => '0.00', 'label' => '3-7 days'],
                ['hours_before_min' => 0, 'refund_percentage' => '0.00', 'flat_fee' => '0.00', 'label' => 'No-show'],
            ],
        ]);

        // 4 days out lands in the 72-168 hour bracket, not the 168+ one.
        $midRange = $this->makeBooking($agent, ['travel_date_start' => now()->addDays(4)->toDateString()]);
        $midQuote = $this->service()->quote($midRange);
        $this->assertSame('500.00', $midQuote['refund_amount']);
        $this->assertSame('500.00', $midQuote['fee_amount']);

        // Travel date already passed — falls through to the strictest band.
        $passed = $this->makeBooking($agent, ['travel_date_start' => now()->subDay()->toDateString()]);
        $passedQuote = $this->service()->quote($passed);
        $this->assertSame('0.00', $passedQuote['refund_amount']);
        $this->assertSame('1000.00', $passedQuote['fee_amount']);
    }

    public function test_flat_fee_is_subtracted_from_the_percentage_refund(): void
    {
        $master = $this->createMasterTenant();
        $agent = $this->createChildTenant($master, Tenant::TYPE_AGENT);

        CancellationPolicy::query()->create([
            'tenant_id' => $agent->id,
            'name' => 'With processing fee',
            'bands' => [
                ['hours_before_min' => 0, 'refund_percentage' => '50.00', 'flat_fee' => '100.00', 'label' => 'Any time'],
            ],
        ]);

        $booking = $this->makeBooking($agent, ['travel_date_start' => now()->addDays(10)->toDateString()]);
        $quote = $this->service()->quote($booking);

        // 1000 * 50% = 500, minus a flat 100 processing fee = 400 refunded.
        $this->assertSame('400.00', $quote['refund_amount']);
        $this->assertSame('600.00', $quote['fee_amount']);
    }

    /**
     * Same "most specific match wins" idea MarkupRule now uses: a
     * supplier-specific policy overrides the tenant's general policy for
     * that supplier only, even though both are otherwise eligible.
     */
    public function test_supplier_specific_policy_overrides_general_policy_for_that_supplier_only(): void
    {
        $master = $this->createMasterTenant();
        $agent = $this->createChildTenant($master, Tenant::TYPE_AGENT);

        CancellationPolicy::query()->create([
            'tenant_id' => $agent->id,
            'name' => 'General',
            'bands' => [
                ['hours_before_min' => 0, 'refund_percentage' => '50.00', 'flat_fee' => '0.00', 'label' => 'General'],
            ],
        ]);

        CancellationPolicy::query()->create([
            'tenant_id' => $agent->id,
            'name' => 'MockAir non-refundable',
            'supplier_code' => 'MOCKAIR',
            'bands' => [
                ['hours_before_min' => 0, 'refund_percentage' => '0.00', 'flat_fee' => '0.00', 'label' => 'Non-refundable'],
            ],
        ]);

        $mockAirBooking = $this->makeBooking($agent, [
            'supplier_code' => 'MOCKAIR',
            'travel_date_start' => now()->addDays(10)->toDateString(),
        ]);
        $mockAirQuote = $this->service()->quote($mockAirBooking);
        $this->assertSame('0.00', $mockAirQuote['refund_amount']);
        $this->assertSame('MockAir non-refundable', $mockAirQuote['policy_name']);

        $otherBooking = $this->makeBooking($agent, [
            'supplier_code' => 'OTHERAIR',
            'travel_date_start' => now()->addDays(10)->toDateString(),
        ]);
        $otherQuote = $this->service()->quote($otherBooking);
        $this->assertSame('500.00', $otherQuote['refund_amount']);
    }

    public function test_domestic_only_policy_does_not_apply_to_international_booking(): void
    {
        $master = $this->createMasterTenant();
        $agent = $this->createChildTenant($master, Tenant::TYPE_AGENT);

        CancellationPolicy::query()->create([
            'tenant_id' => $agent->id,
            'name' => 'Domestic strict',
            'booking_scope' => CancellationPolicy::SCOPE_DOMESTIC,
            'bands' => [
                ['hours_before_min' => 0, 'refund_percentage' => '10.00', 'flat_fee' => '0.00', 'label' => 'Domestic'],
            ],
        ]);

        $domestic = $this->makeBooking($agent, [
            'is_international' => false,
            'travel_date_start' => now()->addDays(10)->toDateString(),
        ]);
        $domesticQuote = $this->service()->quote($domestic);
        $this->assertFalse($domesticQuote['used_fallback']);
        $this->assertSame('100.00', $domesticQuote['refund_amount']);

        // No domestic-only policy matches an international booking, so this
        // falls all the way back to the platform default bands instead.
        $international = $this->makeBooking($agent, [
            'is_international' => true,
            'travel_date_start' => now()->addDays(10)->toDateString(),
        ]);
        $internationalQuote = $this->service()->quote($international);
        $this->assertTrue($internationalQuote['used_fallback']);
    }
}
