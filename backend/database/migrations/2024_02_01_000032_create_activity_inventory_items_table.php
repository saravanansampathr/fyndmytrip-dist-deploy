<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Activities is Phase 3's one remaining item — unlike Bus/Cab/Insurance/
 * Cruise, it had no inventory table, no admin screen, nothing (see the
 * roadmap's "Doesn't exist at all" note), so this creates the table from
 * scratch rather than adding a column to one that
 * 2024_02_01_000018_create_product_inventory_tables.php already made.
 * Same common-prefix shape every other product-module inventory table
 * uses (id, tenant_id, title, ..., capacity, net_price, currency,
 * valid_from/to, distribution flags, status, timestamps) plus a handful of
 * activity-specific columns, with is_refundable included from the start
 * (Cab/Insurance/Cruise all needed a follow-up migration to add theirs;
 * this table doesn't need one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('title', 191);

            $table->string('city', 100)->nullable();
            $table->date('activity_date')->nullable();
            $table->string('provider_name', 150)->nullable();
            $table->string('category', 100)->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->string('meeting_point', 191)->nullable();
            $table->boolean('is_refundable')->default(true);

            $table->unsignedInteger('capacity')->nullable();
            $table->decimal('net_price', 12, 2);
            $table->string('currency', 3)->default('INR');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->boolean('share_on_platform_site')->default(false);
            $table->boolean('share_with_network')->default(false);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->index(['city', 'activity_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_inventory_items');
    }
};
