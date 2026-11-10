<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform roadmap Phase 2 (Cancellation & Refund Policy Engine): "We need
 * options to keep... cancellations, rescheduling or full refund or date
 * change/reissue... on sector wise or pnr wise or pax wise or anything we
 * can define in CRM" and "all need to calculate properly and show them
 * its in process." This first slice covers the core: a policy is a set of
 * time-before-travel bands, each with a refund percentage and/or flat fee,
 * scoped the same way MarkupRule now is (tenant/module/domestic-
 * international/supplier) plus route (origin/destination) — deliberately
 * NOT yet per-PNR or per-passenger overrides (there's no data model for
 * those yet; a policy applies at the booking level for now, matching what
 * CancellationPolicyService::quote() computes).
 *
 * bands is JSON rather than a child table — same reasoning as
 * MarkupRule.config: one small, always-read-together array per policy, no
 * need for its own queryable rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cancellation_policies', function (Blueprint $table) {
            $table->id();
            // NULL = platform-wide default (same tenant_id convention as
            // CashbackRule/Coupon/PaymentGatewayConfig — not tenant-scoped
            // automatically, the owning controller decides visibility).
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('name', 191);
            // NULL = applies to every module.
            $table->string('module', 20)->nullable();
            $table->string('booking_scope', 20)->default('both');
            // NULL = any supplier/airline/hotel/operator.
            $table->string('supplier_code', 40)->nullable();
            $table->string('origin', 10)->nullable();
            $table->string('destination', 10)->nullable();
            // [{hours_before_min: int, refund_percentage: string, flat_fee: string, label: string}, ...]
            // sorted by hours_before_min descending when evaluated — see
            // CancellationPolicy::bandFor().
            $table->json('bands');
            $table->unsignedInteger('priority')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['module', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cancellation_policies');
    }
};
