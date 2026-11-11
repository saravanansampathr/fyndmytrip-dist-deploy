<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: corporate travel. A company is an account with corporate travel
 * switched on (tenants.config.corporate); its employees raise trip
 * requests, checked against the company's travel policy and approved by
 * the company's approvers before anyone books.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('requested_by');
            $table->string('travellers', 500);
            $table->string('module', 20);
            $table->string('origin', 100)->nullable();
            $table->string('destination', 100);
            $table->date('depart_date');
            $table->date('return_date')->nullable();
            $table->string('cabin_class', 20)->nullable();
            $table->decimal('estimated_amount', 12, 2);
            $table->unsignedSmallInteger('nights')->nullable();
            $table->string('purpose', 500);
            $table->string('cost_center', 60)->nullable();
            $table->json('policy_issues')->nullable();
            $table->string('status', 20)->default('pending'); // pending, approved, rejected, booked, cancelled
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->unsignedBigInteger('booking_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_requests');
    }
};
