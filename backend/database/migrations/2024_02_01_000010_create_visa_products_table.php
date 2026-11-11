<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visa product catalog — one row per (destination country, visa type)
 * offering, e.g. "Thailand — Tourist eVisa". Mirrors holiday_packages'
 * conventions: tenant_id NULL = platform-wide catalog, net/markup/sell
 * pricing with the same +10% default markup, supplier_code for future
 * Akbar/TripJack/visa-partner sync. processing_time_label carries the
 * free-text turnaround shown to customers (e.g. "within 48 hours", "In a
 * Week"), matching how reference visa sites (e.g. Visa2fly, Journey
 * Meister) present it — exact SLA guarantees vary too much per country to
 * model as a strict enum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visa_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();

            $table->enum('supplier_code', ['akbar', 'tripjack', 'manual'])->default('manual');
            $table->string('supplier_product_ref', 191)->nullable();

            $table->char('destination_country', 2);
            $table->string('destination_country_name', 191);
            $table->string('visa_type', 191);
            $table->string('processing_time_label', 100)->nullable();
            $table->enum('entry_type', ['single', 'multiple'])->nullable();
            $table->unsignedSmallInteger('validity_days')->nullable();
            $table->unsignedSmallInteger('stay_days')->nullable();

            $table->decimal('net_price', 12, 2);
            $table->decimal('markup_percent', 5, 2)->default(10.00);
            $table->decimal('sell_price', 12, 2);
            $table->char('currency', 3)->default('INR');

            $table->json('documents_required')->nullable();
            $table->text('notes')->nullable();

            $table->enum('status', ['draft', 'active', 'paused', 'expired'])->default('draft');
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            $table->index(['destination_country', 'status']);
            $table->index(['supplier_code', 'supplier_product_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visa_products');
    }
};
