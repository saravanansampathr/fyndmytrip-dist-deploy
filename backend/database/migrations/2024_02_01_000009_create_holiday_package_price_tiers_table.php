<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-occupancy pricing for a holiday package — adult sharing/single,
 * child with/without bed (by free-text age band, since real supplier age
 * bands are inconsistent, e.g. "Age 4y - 11.11y"), and infant. Mirrors the
 * "Category / Occupancy / Age / Price (Per Person)" table found on
 * reference DMC package pages. Each tier stores its own net/markup/sell
 * price so a package can carry different margins per occupancy type if
 * ever needed, defaulting to the package's 10% markup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holiday_package_price_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holiday_package_id')->constrained('holiday_packages')->cascadeOnDelete();

            $table->enum('occupancy_type', [
                'adult_sharing',
                'adult_single',
                'child_with_bed',
                'child_without_bed',
                'infant',
            ]);
            $table->string('age_label', 100)->nullable();

            $table->decimal('net_price', 12, 2);
            $table->decimal('markup_percent', 5, 2)->default(10.00);
            $table->decimal('sell_price', 12, 2);
            $table->char('currency', 3)->default('INR');

            $table->timestamps();

            // Explicit short name — Laravel's auto-generated name for this
            // table/column combination is 67 chars, over MySQL's 64-char
            // identifier limit (see the itinerary-days table's same fix).
            $table->index(['holiday_package_id', 'occupancy_type'], 'hp_price_tiers_pkg_occupancy_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_package_price_tiers');
    }
};
