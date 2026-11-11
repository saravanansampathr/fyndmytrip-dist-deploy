<?php

namespace Database\Seeders;

use App\Domains\Cashback\Models\CashbackRule;
use Illuminate\Database\Seeder;

/**
 * Demo cashback rules — platform-wide (tenant_id NULL), same convention as
 * CouponSeeder — so the new international-payment-gateway feature has
 * something real to demonstrate: a flat rule for every booking and a
 * richer percentage rule scoped to international bookings only, to show
 * the "airline or date or location or duration or product" configurability
 * requested for the rule engine. See CashbackRuleController for the admin
 * CRUD that manages these day to day.
 */
class CashbackRuleSeeder extends Seeder
{
    public function run(): void
    {
        CashbackRule::query()->firstOrCreate(
            ['tenant_id' => null, 'name' => 'Standard flight cashback'],
            [
                'applicable_modules' => ['flight'],
                'booking_scope' => CashbackRule::SCOPE_BOTH,
                'calc_type' => CashbackRule::CALC_PERCENTAGE,
                'value' => '2.00',
                'max_cashback_amount' => '500.00',
                'priority' => 1,
                'status' => CashbackRule::STATUS_ACTIVE,
            ]
        );

        CashbackRule::query()->firstOrCreate(
            ['tenant_id' => null, 'name' => 'International booking bonus cashback'],
            [
                'applicable_modules' => ['flight'],
                'booking_scope' => CashbackRule::SCOPE_INTERNATIONAL,
                'calc_type' => CashbackRule::CALC_PERCENTAGE,
                'value' => '4.00',
                'max_cashback_amount' => '2500.00',
                'priority' => 5,
                'status' => CashbackRule::STATUS_ACTIVE,
            ]
        );

        $this->command?->info('Seeded demo cashback rules: 2% on every flight (capped ₹500), 4% bonus on international flights (capped ₹2,500).');
    }
}
