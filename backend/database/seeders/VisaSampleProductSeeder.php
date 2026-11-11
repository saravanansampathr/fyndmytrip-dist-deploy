<?php

namespace Database\Seeders;

use App\Domains\Visa\Models\VisaProduct;
use Illuminate\Database\Seeder;

/**
 * A few platform-wide visa products so the Visa page has something to
 * apply for on a fresh install (same idea as HolidaySamplePackageSeeder).
 * Prices are illustrative; edit or replace them from the admin CRM.
 */
class VisaSampleProductSeeder extends Seeder
{
    public function run(): void
    {
        $docs = ['Passport valid 6+ months with 2 blank pages', 'Recent passport-size photo (white background)', 'Confirmed return flight ticket', 'Hotel booking for the stay'];
        $included = ['Application review by a visa expert', 'Embassy / e-visa portal submission', 'Status updates by email and WhatsApp'];
        $excluded = ['Biometrics or appointment fees charged by the embassy, if any', 'Courier charges'];

        foreach ([
            ['AE', 'United Arab Emirates', 'Tourist e-Visa — 30 days', 'single', 'in 3-4 working days', 58, 30, '6200.00'],
            ['TH', 'Thailand', 'Tourist e-Visa', 'single', 'in 5-7 working days', 90, 60, '3200.00'],
            ['SG', 'Singapore', 'Tourist e-Visa', 'multiple', 'in 4-5 working days', 30, 30, '2600.00'],
            ['VN', 'Vietnam', 'Tourist e-Visa — 90 days', 'multiple', 'in 5-6 working days', 90, 90, '2900.00'],
            ['ID', 'Indonesia', 'e-Visa on Arrival', 'single', 'in 2-3 working days', 30, 30, '3100.00'],
            ['GB', 'United Kingdom', 'Standard Visitor Visa', 'multiple', 'in 15-20 working days', 180, 180, '14500.00'],
        ] as [$code, $name, $type, $entry, $time, $validity, $stay, $net]) {
            VisaProduct::query()->firstOrCreate(
                ['destination_country' => $code, 'visa_type' => $type, 'tenant_id' => null],
                [
                    'supplier_code' => VisaProduct::SUPPLIER_MANUAL,
                    'destination_country_name' => $name,
                    'entry_type' => $entry,
                    'processing_time_label' => $time,
                    'validity_days' => $validity,
                    'stay_days' => $stay,
                    'net_price' => $net,
                    'currency' => 'INR',
                    'documents_required' => $docs,
                    'included_services' => $included,
                    'excluded_services' => $excluded,
                    'status' => VisaProduct::STATUS_ACTIVE,
                ],
            );
        }
    }
}
