<?php

namespace Tests\Feature;

use App\Domains\Payments\Models\PaymentTransaction;
use App\Domains\Payments\Services\CurrencyService;
use App\Models\User;
use Database\Seeders\HolidaySamplePackageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Customers pay in their own currency; bookings stay priced in INR. */
class MultiCurrencyPaymentTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['booking.fx_fixed_rates' => '']);
        Http::fake(['open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => [
            'INR' => 1, 'USD' => 0.012, 'AED' => 0.044, 'GBP' => 0.0094, 'EUR' => 0.011, 'SGD' => 0.016, 'AUD' => 0.018, 'KWD' => 0.0037,
        ]])]);
    }

    public function test_the_suggested_currency_follows_the_visitors_country(): void
    {
        $this->getJson('/api/currency-rates', ['CF-IPCountry' => 'AE'])->assertOk()->assertJsonPath('data.suggested', 'AED')->assertJsonPath('data.base', 'INR');
        $this->getJson('/api/currency-rates', ['CF-IPCountry' => 'IN'])->assertJsonPath('data.suggested', 'INR');
        $this->getJson('/api/currency-rates', ['CF-IPCountry' => 'DE'])->assertJsonPath('data.suggested', 'EUR');
        $this->getJson('/api/currency-rates?tz=Europe/London')->assertJsonPath('data.suggested', 'GBP');
        $this->getJson('/api/currency-rates?tz=Asia/Dubai')->assertJsonPath('data.suggested', 'AED');
        $this->getJson('/api/currency-rates?tz=Asia/Kolkata')->assertJsonPath('data.suggested', 'INR');
        $this->getJson('/api/currency-rates?tz=Australia/Sydney', ['Accept-Language' => 'en-US'])->assertJsonPath('data.suggested', 'AUD');
        $this->getJson('/api/currency-rates?tz=Not/AZone')->assertJsonPath('data.suggested', 'INR');
        $this->getJson('/api/currency-rates?country=SG')->assertJsonPath('data.suggested', 'SGD');
        // A country whose own currency isn't offered pays in USD; no signal at all means INR.
        $this->getJson('/api/currency-rates', ['CF-IPCountry' => 'BR'])->assertJsonPath('data.suggested', 'USD');
        $this->getJson('/api/currency-rates')->assertJsonPath('data.suggested', 'INR');
    }

    public function test_a_package_paid_in_usd_records_the_charge_and_shows_it_on_the_invoice(): void
    {
        $master = $this->createMasterTenant();
        $this->seed(HolidaySamplePackageSeeder::class);
        $customer = $this->createUserForTenant($master, User::ROLE_CUSTOMER);
        Sanctum::actingAs($customer, ['*']);

        $hold = $this->postJson('/api/packages/goa-beach-escape-north-south-goa/bookings', [
            'travel_date' => now()->addDays(40)->toDateString(),
            'travelers' => [['name' => 'Asha Rao', 'occupancy_type' => 'adult_sharing']],
        ])->assertCreated();
        $id = $hold->json('data.id');
        $total = (string) $hold->json('data.total_amount');

        $this->postJson("/api/holiday-bookings/{$id}/confirm", ['wallet_amount' => 0])->assertOk();
        $this->postJson("/api/holiday-bookings/{$id}/pay", ['method' => 'international_card', 'currency' => 'USD'])->assertOk();

        $txn = PaymentTransaction::withoutGlobalScopes()->where('booking_id', $id)->firstOrFail();
        $this->assertSame('INR', $txn->currency);
        $this->assertSame('USD', $txn->charge_currency);
        $this->assertEqualsWithDelta((float) $total * 0.012, (float) $txn->charge_amount, 0.01);

        $payments = $this->getJson('/api/my-payments')->assertOk()->json();
        $row = collect($payments['data'] ?? $payments)->firstWhere('booking_id', $id) ?? collect($payments['data'] ?? $payments)->first();
        $this->assertSame('USD', $row['charge_currency']);

        $html = null;
        \Illuminate\Support\Facades\View::composer('pdf.invoice', function ($view) use (&$html) {
            if ($html === null) {
                $html = '';
                $html = view('pdf.invoice', $view->getData())->render();
            }
        });
        $this->get("/api/bookings/{$id}/invoice.pdf")->assertOk();
        $this->assertStringContainsString('Charged to card in USD', (string) $html);
        $this->assertStringContainsString('USD '.number_format(round((float) $total * 0.012, 2), 2), (string) $html);
    }

    public function test_an_unsupported_or_missing_currency_is_charged_in_inr(): void
    {
        $svc = app(CurrencyService::class);
        $this->assertSame('INR', $svc->chargeFor('1000.00', 'XYZ')['currency']);
        $this->assertSame('INR', $svc->chargeFor('1000.00', null)['currency']);
        $this->assertSame('1000.00', $svc->chargeFor('1000.00', null)['amount']);
        $this->assertSame('12.00', $svc->chargeFor('1000.00', 'usd')['amount']);
        $this->assertSame('3.700', $svc->chargeFor('1000.00', 'KWD')['amount']);
        // A supported currency without a rate today (feed didn't return it) also falls back.
        $this->assertSame('INR', $svc->chargeFor('1000.00', 'JPY')['currency']);
    }

    public function test_fixed_rates_override_the_feed(): void
    {
        config(['booking.fx_fixed_rates' => 'USD=0.02, AED=0.05, BAD=1, EUR=x']);
        $rates = app(CurrencyService::class)->rates();
        $this->assertSame(['USD' => 0.02, 'AED' => 0.05, 'INR' => 1.0], $rates);
        $this->assertSame('20.00', app(CurrencyService::class)->chargeFor('1000.00', 'USD')['amount']);
    }
}
