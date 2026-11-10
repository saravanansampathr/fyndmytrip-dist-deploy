<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "We need an option to add own inventory of all the products" — a
 * tenant's own manually-entered supply (flights, hotels, buses, cabs,
 * insurance, cruise) that sits alongside supplier-sourced results rather
 * than replacing them. Holiday packages and Visa products already have
 * their own dedicated catalog tables from earlier work; this generic table
 * covers the remaining six Product Modules with one schema, the same way
 * Booking uses one generic schema + a `details` JSON column for the
 * module-specific bits (Database Design Document's own pattern).
 *
 * tenant_id NULL = platform-wide catalog entry, same convention as
 * holiday_packages/visa_products/cashback_rules (no BelongsToTenant/
 * TenantScope here — visibility is resolved explicitly by
 * InventoryItemController/InventorySearchService, not the global scope).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->enum('module', ['flight', 'hotel', 'bus', 'cab', 'insurance', 'cruise']);
            $table->string('title', 191);
            $table->string('origin', 100)->nullable();
            $table->string('destination', 100)->nullable();
            $table->date('travel_date')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->string('operator_or_provider', 150)->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->decimal('net_price', 12, 2);
            $table->string('currency', 3)->default('INR');
            // Module-specific extras: flight {flight_number, cabin_class, aircraft_type},
            // hotel {star_rating, room_type, meal_plan}, bus {bus_type, boarding_point},
            // cab {car_type}, insurance {coverage_amount, policy_term_days}, cruise
            // {cabin_type, itinerary_nights} — validated loosely at the controller.
            $table->json('details')->nullable();
            $table->boolean('share_on_platform_site')->default(false);
            $table->boolean('share_with_network')->default(false);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->index(['module', 'origin', 'destination', 'travel_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
