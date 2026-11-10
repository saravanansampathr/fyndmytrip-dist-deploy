<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Database Design Document §4 — a single generic booking schema serves all
 * eight Product Modules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->uuid('booking_reference')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('agent_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('module', ['flight', 'hotel', 'bus', 'cab', 'holiday', 'insurance', 'visa', 'cruise']);
            $table->string('supplier_code', 30);
            $table->string('supplier_booking_ref', 100)->nullable();
            $table->enum('status', ['cart', 'held', 'confirmed', 'ticketed', 'cancelled', 'failed', 'refunded'])->default('cart');
            $table->decimal('gross_amount', 12, 2);
            $table->decimal('supplier_net_amount', 12, 2);
            $table->decimal('markup_amount', 12, 2)->default(0);
            $table->decimal('commission_amount', 12, 2)->default(0);
            $table->char('currency', 3)->default('INR');
            $table->date('travel_date_start');
            $table->date('travel_date_end')->nullable();
            $table->unsignedSmallInteger('passenger_count')->default(1);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['tenant_id', 'module', 'created_at']);
        });

        Schema::create('booking_passengers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->string('name', 191);
            $table->date('dob')->nullable();
            $table->enum('gender', ['male', 'female', 'other', 'unspecified'])->default('unspecified');
            $table->text('passport_number')->nullable();
            $table->enum('document_type', ['passport', 'aadhaar', 'pan', 'voter_id', 'driving_license', 'other'])->nullable();
            $table->text('document_number')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('booking_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('origin', 100)->nullable();
            $table->string('destination', 100)->nullable();
            $table->dateTime('departure_at')->nullable();
            $table->dateTime('arrival_at')->nullable();
            $table->string('carrier_or_provider', 100)->nullable();
            $table->string('fare_basis', 50)->nullable();
            $table->json('raw_supplier_payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('booking_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->enum('from_status', ['cart', 'held', 'confirmed', 'ticketed', 'cancelled', 'failed', 'refunded'])->nullable();
            $table->enum('to_status', ['cart', 'held', 'confirmed', 'ticketed', 'cancelled', 'failed', 'refunded']);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['booking_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_status_history');
        Schema::dropIfExists('booking_segments');
        Schema::dropIfExists('booking_passengers');
        Schema::dropIfExists('bookings');
    }
};
