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
 * Tickets, vouchers and invoices a traveller sees: markup / service charge /
 * convenience fee is folded into the fare and never printed on its own, and
 * the fare-less e-ticket is for B2B agents only.
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

        // The agent's copy with the fare also folds the markup in.
        [$data] = $this->capture('pdf.flight-eticket', fn () => $this->get("/api/flights/bookings/{$booking->id}/ticket.pdf")->assertOk());
        $this->assertSame('Base Fare', $data['priceRows'][0]['label']);
        $this->assertSame(1150.0, $data['priceRows'][0]['amount']);
    }

    public function test_b2c_invoice_shows_gst_but_no_service_charge_line(): void
    {
        $customer = $this->createUserForTenant($this->master, User::ROLE_CUSTOMER);
        $booking = $this->ticketedBooking($this->master, $customer, Booking::MODULE_FLIGHT, Booking::CHANNEL_DIRECT, markup: 118.0);
        Sanctum::actingAs($customer, ['*']);

        [$data, $html] = $this->capture('pdf.invoice', fn () => $this->get("/api/bookings/{$booking->id}/invoice.pdf")->assertOk());
        $labels = array_column($data['lineItems'], 'label');

        // GST on the 100.00 service value folded in the 118.00 markup.
        $this->assertSame(['Base Fare', 'CGST (9%)', 'SGST (9%)', 'Add-on Services (meals / seats / baggage)', 'Discount'], $labels);
        $this->assertSame(1100.0, $data['lineItems'][0]['amount']); // 1000 net + 100 service value
        $this->assertSame(18.0, $data['lineItems'][1]['amount'] + $data['lineItems'][2]['amount']);
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
        $this->assertSame(100.0, $items['Service charges (taxable value)']);
        $this->assertEqualsWithDelta((float) $booking->total_amount, $items->sum(), 0.001);
    }

    private function ticketedBooking(Tenant $tenant, User $user, string $module, string $channel, float $markup = 150.0): Booking
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
            'metadata' => [],
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
