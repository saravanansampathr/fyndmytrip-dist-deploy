<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Own inventory... fields are same for all services, it must be changed
 * for respective product, Build the super product" — replaces the single
 * generic `inventory_items` table (one schema + a freeform `details` JSON
 * blob for every module) with six dedicated tables, one per Product
 * Module, each with real typed columns for what that product actually
 * needs — the same pattern holiday_packages/visa_products already use.
 *
 * The old `inventory_items` table is left in place (not dropped) and its
 * rows are copied forward into these new tables by the next migration —
 * this one only creates the schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createCommonPrefix('flight_inventory_items', function (Blueprint $table) {
            // Nullable at the DB level (the old generic form left every one
            // of these optional, and the data migration must be able to
            // carry forward whatever was already keyed in) — the new admin
            // form/controller validation is what actually requires them for
            // anything created going forward.
            $table->string('origin', 10)->nullable();
            $table->string('destination', 10)->nullable();
            // Nullable: the previous generic form allowed an optional travel
            // date too — a flight row without one simply never matches any
            // search date filter (see FlightInventoryItem::scopeForSearch()),
            // it isn't rejected outright, matching the old behaviour.
            $table->date('travel_date')->nullable();
            $table->string('departure_time', 5)->nullable();
            $table->string('airline', 150)->nullable();
            $table->string('flight_number', 20)->nullable();
            $table->enum('cabin_class', ['economy', 'premium_economy', 'business', 'first'])->default('economy');
            $table->string('aircraft_type', 100)->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->unsignedInteger('baggage_allowance_kg')->nullable();
            $table->boolean('is_refundable')->default(false);
            $table->boolean('meal_included')->default(false);
        }, function (Blueprint $table) {
            $table->index(['origin', 'destination', 'travel_date']);
        });

        $this->createCommonPrefix('hotel_inventory_items', function (Blueprint $table) {
            $table->string('city', 100)->nullable();
            $table->string('hotel_name', 150)->nullable();
            $table->date('check_in_date')->nullable();
            $table->date('check_out_date')->nullable();
            $table->string('room_type', 100)->nullable();
            $table->unsignedTinyInteger('star_rating')->nullable();
            $table->string('meal_plan', 100)->nullable();
        }, function (Blueprint $table) {
            $table->index(['city', 'check_in_date']);
        });

        $this->createCommonPrefix('bus_inventory_items', function (Blueprint $table) {
            $table->string('origin', 100)->nullable();
            $table->string('destination', 100)->nullable();
            $table->date('travel_date')->nullable();
            $table->string('departure_time', 5)->nullable();
            $table->string('operator_name', 150)->nullable();
            $table->string('bus_type', 100)->nullable();
            $table->string('boarding_point', 150)->nullable();
            $table->string('dropping_point', 150)->nullable();
        }, function (Blueprint $table) {
            $table->index(['origin', 'destination', 'travel_date']);
        });

        $this->createCommonPrefix('cab_inventory_items', function (Blueprint $table) {
            $table->string('origin', 100)->nullable();
            $table->string('destination', 100)->nullable();
            $table->date('travel_date')->nullable();
            $table->string('provider_name', 150)->nullable();
            $table->string('car_type', 100)->nullable();
            $table->unsignedInteger('included_km')->nullable();
            $table->decimal('extra_km_fare', 10, 2)->nullable();
        }, function (Blueprint $table) {
            $table->index(['origin', 'destination', 'travel_date']);
        });

        $this->createCommonPrefix('insurance_inventory_items', function (Blueprint $table) {
            $table->string('plan_name', 150);
            $table->string('provider_name', 150)->nullable();
            $table->decimal('coverage_amount', 14, 2)->nullable();
            $table->unsignedInteger('policy_term_days')->nullable();
        }, function (Blueprint $table) {
            $table->index(['plan_name']);
        });

        $this->createCommonPrefix('cruise_inventory_items', function (Blueprint $table) {
            $table->string('cruise_line', 150)->nullable();
            $table->string('ship_name', 150)->nullable();
            $table->string('departure_port', 100)->nullable();
            $table->string('arrival_port', 100)->nullable();
            $table->date('departure_date')->nullable();
            $table->unsignedInteger('nights')->nullable();
            $table->string('cabin_type', 100)->nullable();
        }, function (Blueprint $table) {
            $table->index(['departure_port', 'departure_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cruise_inventory_items');
        Schema::dropIfExists('insurance_inventory_items');
        Schema::dropIfExists('cab_inventory_items');
        Schema::dropIfExists('bus_inventory_items');
        Schema::dropIfExists('hotel_inventory_items');
        Schema::dropIfExists('flight_inventory_items');
    }

    /**
     * `title`, `net_price`/`currency`, `capacity`, `valid_from`/`valid_to`,
     * the two distribution flags, `status` and the tenant scoping/timestamps
     * are identical across every product module — only the middle,
     * product-specific block (passed in as $columns) actually differs.
     */
    protected function createCommonPrefix(string $tableName, \Closure $columns, ?\Closure $indexes = null): void
    {
        Schema::create($tableName, function (Blueprint $table) use ($columns, $indexes) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('title', 191);

            $columns($table);

            $table->unsignedInteger('capacity')->nullable();
            $table->decimal('net_price', 12, 2);
            $table->string('currency', 3)->default('INR');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->boolean('share_on_platform_site')->default(false);
            $table->boolean('share_with_network')->default(false);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            if ($indexes) {
                $indexes($table);
            }
        });
    }
};
