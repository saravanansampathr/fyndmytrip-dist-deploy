<?php

namespace Tests\Feature;

use App\Domains\Booking\Models\Booking;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Tickets, vouchers and invoices: for direct customers our markup and its
 * GST are folded into the base fare and the airline's tax breakup is shown;
 * B2B partners also see our convenience fee with its GST; the fare-less
 * e-ticket is for B2B agents only.
 */
class CustomerFareDocumentsTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private const DOCUMENTS = [
        Booking::MODULE_FLIGHT => ['flights', 'ticket.pdf', 'pdf.flight-eticket', 'Base Fare'],
        Booking::MODULE_HOTEL => ['hotels', 'voucher.pdf', 'pdf.hotel-voucher', 'Room Charges'],
        Booking::MODULE_BUS => ['bus', 'ticket.pdf', 'pdf.bus-ticket', 'Base Fare'],
        Booking::MODULE_CAB => ['cabs', 'voucher.pdf', 'pdf.cab-voucher', 'Trip Fare'],
        Booking::MODULE_CRUISE => ['cruises', 'ticket.pdf', 'pdf.cruise-ticket', 'Cruise Fare'],
        Booking::MODULE_ACTIVITY => ['activities', 'voucher.pdf', 'pdf.activity-voucher', 'Activity Fare'],
        Booking::MODULE_INSURANCE => ['insurance', 'policy.pdf', 'pdf.insurance-policy', 'Premium'],
    ];

    private const HIDDEN_WORDS = ['Markup', 'markup', 'Service &amp; Fees', 'Service & Fees', 'Taxes &amp; Fees', 'Taxes and fees', 'Convenience', 'convenience', 'Service charge', 'Margin', 'service fee'];

    protected Tenant $master;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->master = $this->createMasterTenant();
    }

    public function test_every_service_folds_the_markup_into_the_fare_for_a_direct_customer(): void
    {
        $customer = $this->createUserForTenant($this->master, User::ROLE_CUSTOMER);

        foreach (self::DOCUMENTS as $module => [$prefix, $doc, $view, $fareLabel]) {
            $booking = $this->ticketedBooking($this->master, $customer, $module, Booking::CHANNEL_DIRECT);
            Sanctum::actingAs($customer, ['*']);

            [$data, $html] = $this->capture($view, fn () => $this->get("/api/{$prefix}/bookings/{$booking->id}/{$doc}")->assertOk());

            $this->assertSame([['label' => $fareLabel, 'amount' => 1150.0], ['label' => 'Add-on Services'.($module === Booking::MODULE_FLIGHT ? ' (meals / seats / baggage)' : ''), 'amount' => 200.0], ['label' => 'Discount', 'amount' => -50.0]], $data['priceRows'], $module);
            $this->assertEqualsWithDelta((float) $booking->total_amount, array_sum(array_column($data['priceRows'], 'amount')), 0.001, $module);
            $this->assertStringContainsString('1,150.00', $html, $module);
            $this->assertStringContainsString('1,300.00', $html, $module);
            foreach (self::HIDDEN_WORDS as $word) {
                $this->assertStringNotContainsString($word, $html, "{$module} document shows '{$word}'");
            }
            $this->assertStringNotContainsString('150.00', str_replace(['1,150.00', '2,150.00'], '', $html), "{$module} shows the markup amount");
        }
    }

    public function test_direct_customers_always_get_the_ticket_with_the_fare(): void
    {
        $customer = $this->createUserForTenant($this->master, User::ROLE_CUSTOMER);
        $booking = $this->ticketedBooking($this->master, $customer, Booking::MODULE_FLIGHT, Booking::CHANNEL_DIRECT);
        Sanctum::actingAs($customer, ['*']);

        $this->getJson("/api/flights/bookings/{$booking->id}")->assertOk()->assertJsonPath('data.can_hide_fare', false);

        [$data, $html] = $this->capture('pdf.flight-eticket', fn () => $this->get("/api/flights/bookings/{$booking->id}/ticket.pdf?fare=0")->assertOk());
        $this->assertTrue($data['includeFare']);
        $this->assertStringContainsString('Fare Details', $html);
    }

    public function test_b2b_agents_can_download_the_ticket_without_the_fare(): void
    {
        $agency = $this->createChildTenant($this->master, Tenant::TYPE_AGENT);
        $agent = $this->createUserForTenant($agency, User::ROLE_AGENT);
        $booking = $this->ticketedBooking($agency, $agent, Booking::MODULE_FLIGHT, Booking::CHANNEL_AGENT);
        Sanctum::actingAs($agent, ['*']);

        $this->getJson("/api/flights/bookings/{$booking->id}")->assertOk()->assertJsonPath('data.can_hide_fare', true);

        [$data, $html] = $this->capture('pdf.flight-eticket', fn () => $this->get("/api/flights/bookings/{$booking->id}/ticket.pdf?fare=0")->assertOk());
        $this->assertFalse($data['includeFare']);
        $this->assertStringNotContainsString('Fare Details', $html);
        $this->assertStringNotContainsString('1,150.00', $html);

        // The agent's copy with the fare shows the convenience fee and the
        // 18% GST on it, so the partner can claim the GST.
        [$data] = $this->capture('pdf.flight-eticket', fn () => $this->get("/api/flights/bookings/{$booking->id}/ticket.pdf")->assertOk());
        $this->assertSame(['Base Fare', 'Convenience fee', 'CGST (9%) on convenience fee', 'SGST (9%) on convenience fee', 'Add-on Services (meals / seats / baggage)', 'Discount'], array_column($data['priceRows'], 'label'));
        $this->assertSame(1000.0, $data['priceRows'][0]['amount']);
        $this->assertSame(127.12, $data['priceRows'][1]['amount']); // 150 / 1.18
        $this->assertEqualsWithDelta(22.88, $data['priceRows'][2]['amount'] + $data['priceRows'][3]['amount'], 0.001);
        $this->assertEqualsWithDelta((float) $booking->total_amount, array_sum(array_column($data['priceRows'], 'amount')), 0.001);
    }

    public function test_airline_tax_breakup_is_shown_and_our_markup_stays_hidden_for_b2c(): void
    {
        $customer = $this->createUserForTenant($this->master, User::ROLE_CUSTOMER);
        $booking = $this->ticketedBooking($this->master, $customer, Booking::MODULE_FLIGHT, Booking::CHANNEL_DIRECT, metadata: ['airline_taxes' => ['K3' => '41.00', 'YQ' => '60.00', 'YR' => '10.00', 'PSF' => '89.00']]);
        Sanctum::actingAs($customer, ['*']);

        [$data, $html] = $this->capture('pdf.flight-eticket', fn () => $this->get("/api/flights/bookings/{$booking->id}/ticket.pdf")->assertOk());
        $this->assertSame([
            ['label' => 'Base Fare', 'amount' => 950.0], // airline base 800 + our 150 markup (GST included)
            ['label' => 'Airline GST (K3)', 'amount' => 41.0],
            ['label' => 'Fuel surcharge (YQ/YR)', 'amount' => 70.0],
            ['label' => 'Airport & other taxes', 'amount' => 89.0],
            ['label' => 'Add-on Services (meals / seats / baggage)', 'amount' => 200.0],
            ['label' => 'Discount', 'amount' => -50.0],
        ], $data['priceRows']);
        $this->assertEqualsWithDelta((float) $booking->total_amount, array_sum(array_column($data['priceRows'], 'amount')), 0.001);
        $this->assertStringNotContainsString('Convenience', $html);
        $this->assertStringNotContainsString('CGST', $html);

        [$inv] = $this->capture('pdf.invoice', fn () => $this->get("/api/bookings/{$booking->id}/invoice.pdf")->assertOk());
        $this->assertSame(['Base Fare', 'Airline GST (K3)', 'Fuel surcharge (YQ/YR)', 'Airport & other taxes', 'Add-on Services (meals / seats / baggage)', 'Discount'], array_column($inv['lineItems'], 'label'));
    }

    public function test_b2b_ticket_shows_airline_taxes_and_our_fee_with_gst(): void
    {
        $agency = $this->createChildTenant($this->master, Tenant::TYPE_AGENT, ['business_registration_no' => '27AAACX9999L1Z2']);
        $agent = $this->createUserForTenant($agency, User::ROLE_AGENT);
        $booking = $this->ticketedBooking($agency, $agent, Booking::MODULE_FLIGHT, Booking::CHANNEL_AGENT, markup: 118.0, metadata: ['airline_taxes' => ['K3' => '40.00', 'OT' => '160.00']]);
        Sanctum::actingAs($agent, ['*']);

        [$data] = $this->capture('pdf.flight-eticket', fn () => $this->get("/api/flights/bookings/{$booking->id}/ticket.pdf")->assertOk());
        $this->assertSame([
            ['label' => 'Base Fare', 'amount' => 800.0],
            ['label' => 'Airline GST (K3)', 'amount' => 40.0],
            ['label' => 'Airport & other taxes', 'amount' => 160.0],
            ['label' => 'Convenience fee', 'amount' => 100.0],
            ['label' => 'IGST (18%) on convenience fee', 'amount' => 18.0], // Maharashtra partner, Tamil Nadu seller
            ['label' => 'Add-on Services (meals / seats / baggage)', 'amount' => 200.0],
            ['label' => 'Discount', 'amount' => -50.0],
        ], $data['priceRows']);
    }

    public function test_flight_booking_keeps_the_suppliers_tax_breakup(): void
    {
        $supplier = \App\Domains\Suppliers\Models\Supplier::create([
            'code' => 'MOCKAIR', 'name' => 'Mock Flight Supplier', 'supported_modules' => ['flight'],
            'adapter_class' => \App\Domains\Suppliers\Adapters\MockFlightSupplierAdapter::class,
        ]);
        \App\Domains\Suppliers\Models\TenantSupplierConfig::create([
            'tenant_id' => $this->master->id, 'supplier_id' => $supplier->id,
            'credentials_encrypted' => ['api_key' => 'test'], 'is_active' => true, 'priority_order' => 1,
        ]);
        $customer = $this->createUserForTenant($this->master, User::ROLE_CUSTOMER);
        Sanctum::actingAs($customer, ['*']);
        $date = now()->addDays(12)->toDateString();
        $offer = $this->postJson('/api/flights/search', ['origin' => 'DEL', 'destination' => 'BOM', 'departure_date' => $date])->assertOk()->json('data.legs.0.offers.0');

        $id = $this->postJson('/api/flights/bookings', [
            'legs' => [['supplier_code' => $offer['supplier_code'], 'external_id' => $offer['external_id'], 'segments' => $offer['segments']]],
            'travel_date' => $date,
            'passengers' => [
                ['first_name' => 'A', 'last_name' => 'One', 'gender' => 'female'],
                ['first_name' => 'B', 'last_name' => 'Two', 'gender' => 'male'],
            ],
        ])->assertCreated()->json('data.id');

        $booking = Booking::withoutTenantScope()->findOrFail($id);
        $taxes = $booking->metadata['airline_taxes'] ?? [];
        $this->assertSame(['K3', 'YQ', 'OT'], array_keys($taxes));
        $perUnit = \App\Domains\Suppliers\Adapters\MockFlightSupplierAdapter::mockTaxes((float) $booking->supplier_net_amount / 2);
        $this->assertEqualsWithDelta((float) $perUnit['K3'] * 2, (float) $taxes['K3'], 0.02);
        $rows = \App\Support\CustomerFare::rows($booking);
        $this->assertSame('Airline GST (K3)', $rows[1]['label']);
        $this->assertEqualsWithDelta((float) $booking->total_amount, array_sum(array_column($rows, 'amount')), 0.001);
    }

    public function test_b2c_invoice_hides_our_fee_and_its_gst_in_the_base_fare(): void
    {
        $customer = $this->createUserForTenant($this->master, User::ROLE_CUSTOMER);
        $booking = $this->ticketedBooking($this->master, $customer, Booking::MODULE_FLIGHT, Booking::CHANNEL_DIRECT, markup: 118.0);
        Sanctum::actingAs($customer, ['*']);

        [$data, $html] = $this->capture('pdf.invoice', fn () => $this->get("/api/bookings/{$booking->id}/invoice.pdf")->assertOk());
        $labels = array_column($data['lineItems'], 'label');

        // Our 118.00 markup (100 fee + 18 GST) is folded into Base Fare.
        $this->assertSame(['Base Fare', 'Add-on Services (meals / seats / baggage)', 'Discount'], $labels);
        $this->assertSame(1118.0, $data['lineItems'][0]['amount']);
        $this->assertStringNotContainsString('CGST', $html);
        $this->assertEqualsWithDelta((float) $booking->total_amount, array_sum(array_column($data['lineItems'], 'amount')), 0.001);
        foreach (self::HIDDEN_WORDS as $word) {
            $this->assertStringNotContainsString($word, $html, "invoice shows '{$word}'");
        }
    }

    public function test_b2b_invoice_keeps_the_taxable_value_for_input_tax_credit(): void
    {
        $agency = $this->createChildTenant($this->master, Tenant::TYPE_AGENT);
        $agent = $this->createUserForTenant($agency, User::ROLE_AGENT);
        $booking = $this->ticketedBooking($agency, $agent, Booking::MODULE_FLIGHT, Booking::CHANNEL_AGENT, markup: 118.0);
        Sanctum::actingAs($agent, ['*']);

        [$data] = $this->capture('pdf.invoice', fn () => $this->get("/api/bookings/{$booking->id}/invoice.pdf")->assertOk());
        $items = collect($data['lineItems'])->pluck('amount', 'label');

        $this->assertSame(1000.0, $items['Base Fare']);
        $this->assertSame(100.0, $items['Convenience fee']);
        $this->assertSame(18.0, $items['CGST (9%) on convenience fee'] + $items['SGST (9%) on convenience fee']);
        $this->assertEqualsWithDelta((float) $booking->total_amount, $items->sum(), 0.001);
    }

    private function ticketedBooking(Tenant $tenant, User $user, string $module, string $channel, float $markup = 150.0, array $metadata = []): Booking
    {
        $net = 1000.0;
        $booking = Booking::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'channel' => $channel,
            'created_by_user_id' => $user->id,
            'module' => $module,
            'supplier_code' => 'MOCK',
            'supplier_booking_ref' => 'MOCK-'.uniqid(),
            'status' => Booking::STATUS_TICKETED,
            'supplier_net_amount' => number_format($net, 2, '.', ''),
            'markup_amount' => number_format($markup, 2, '.', ''),
            'gross_amount' => number_format($net + $markup, 2, '.', ''),
            'extras_amount' => '200.00',
            'discount_amount' => '50.00',
            'total_amount' => number_format($net + $markup + 150, 2, '.', ''),
            'travel_date_start' => now()->addDays(10)->toDateString(),
            'travel_date_end' => now()->addDays(12)->toDateString(),
            'contact_email' => 'traveller@example.test',
            'contact_phone' => '9000000000',
            'metadata' => $metadata,
        ]);
        $booking->statusHistory()->create(['from_status' => Booking::STATUS_CONFIRMED, 'to_status' => Booking::STATUS_TICKETED]);

        return $booking->fresh();
    }

    /** @return array{0: array<string, mixed>, 1: string} */
    private function capture(string $view, callable $request): array
    {
        $data = null;
        $html = null;
        View::composer($view, function ($v) use (&$data, &$html, $view) {
            if ($data === null) {
                $data = $v->getData();
                $html = view($view, $data)->render();
            }
        });
        $request();
        View::flushFinderCache();
        app('events')->forget("composing: {$view}");
        $this->assertNotNull($data, "{$view} was not rendered");

        return [$data, (string) $html];
    }
}
