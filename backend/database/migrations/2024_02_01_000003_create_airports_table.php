<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal route-classification reference data. There is no PNR/GDS airport
 * master anywhere in this system (the supplier layer is entirely mocked —
 * see MockFlightSupplierAdapter), so this table exists purely to answer one
 * question for the new payment-gateway routing requirement: is a booking's
 * origin/destination pair domestic or international relative to the
 * platform's home country (config('booking.home_country'))?
 *
 * A code that is NOT in this table is treated as domestic by
 * RouteClassificationService (see its docblock) so that existing demo
 * searches against unseeded city codes keep working exactly as before;
 * only codes seeded here (or added later) can ever mark a booking
 * international. Seed data covers common Indian metros plus a handful of
 * international demo airports so the new gateway flow is actually
 * reachable in this environment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('airports', function (Blueprint $table) {
            $table->char('code', 3)->primary();
            $table->string('city', 100);
            $table->char('country_code', 2);
            $table->string('country_name', 100);
            $table->timestamps();
        });

        $now = now();

        $rows = [
            // --- India (domestic) ---
            ['DEL', 'Delhi', 'IN', 'India'],
            ['BOM', 'Mumbai', 'IN', 'India'],
            ['BLR', 'Bengaluru', 'IN', 'India'],
            ['MAA', 'Chennai', 'IN', 'India'],
            ['HYD', 'Hyderabad', 'IN', 'India'],
            ['CCU', 'Kolkata', 'IN', 'India'],
            ['AMD', 'Ahmedabad', 'IN', 'India'],
            ['PNQ', 'Pune', 'IN', 'India'],
            ['GOI', 'Goa', 'IN', 'India'],
            ['COK', 'Kochi', 'IN', 'India'],
            ['JAI', 'Jaipur', 'IN', 'India'],
            ['LKO', 'Lucknow', 'IN', 'India'],
            // --- International demo routes ---
            ['DXB', 'Dubai', 'AE', 'United Arab Emirates'],
            ['SIN', 'Singapore', 'SG', 'Singapore'],
            ['LHR', 'London', 'GB', 'United Kingdom'],
            ['JFK', 'New York', 'US', 'United States'],
            ['BKK', 'Bangkok', 'TH', 'Thailand'],
            ['KUL', 'Kuala Lumpur', 'MY', 'Malaysia'],
            ['CMB', 'Colombo', 'LK', 'Sri Lanka'],
            ['KTM', 'Kathmandu', 'NP', 'Nepal'],
        ];

        foreach ($rows as [$code, $city, $countryCode, $countryName]) {
            DB::table('airports')->insert([
                'code' => $code,
                'city' => $city,
                'country_code' => $countryCode,
                'country_name' => $countryName,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('airports');
    }
};
