<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform Roadmap Phase 7 — "Commission tracking per channel... calculated
 * and recorded distinctly from markup — markup is what the platform
 * charges over net fare; commission is what's paid out or credited to a
 * channel for the business it brings." The mirror image of markup_rules
 * (see create_supplier_tables + extend_markup_rules_and_bookings): markup
 * is what a tenant charges DOWN the chain (a parent capping what it lets a
 * child charge); commission is what a tenant pays DOWN the chain instead —
 * a reward credited to whichever tenant (agent/sub_agent/white_label/
 * api_partner) actually sold the booking, for bringing that business in.
 *
 * tenant_id is the tenant GRANTING this commission (mirrors markup_rules'
 * own tenant_id) — CommissionService walks the booking tenant's ancestor
 * chain (excluding itself) for the nearest one with a matching active
 * rule, same "nearest wins" resolution MarkupService uses, but with no
 * ceiling walk: commission has no "child can't out-configure parent"
 * concern the way a cost passed UP the chain does.
 *
 * module and rule_type are VARCHAR, not ENUM — see
 * 2024_02_01_000033_convert_module_enums_to_varchar's docblock for exactly
 * why an ENUM on a column whose value set grows (a new Product Module) is
 * a trap: this table starts out already avoiding it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 191)->nullable();

            // Null = applies to every module.
            $table->string('module', 20)->nullable();

            // Which tenant TYPE this rule rewards when it sells a booking —
            // Tenant::TYPE_AGENT / TYPE_SUB_AGENT / TYPE_WHITE_LABEL /
            // TYPE_API_PARTNER. Never TYPE_MASTER: nobody sits above Master
            // to reward it.
            $table->string('applies_to_type', 20);

            $table->string('rule_type', 20); // percentage | flat_per_pnr | flat_per_pax
            $table->json('config'); // {"percentage": "..."} or {"amount": "..."}
            $table->decimal('max_commission_amount', 12, 2)->nullable();

            $table->string('booking_scope', 20)->default('both'); // domestic | international | both
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Laravel's auto-generated name for this composite index
            // ("commission_rules_tenant_id_applies_to_type_module_is_active_index")
            // is 65 characters — one over MySQL's 64-char identifier limit,
            // which fails the whole migration with "Identifier name ... is
            // too long" (1059). Name it explicitly and short instead.
            $table->index(['tenant_id', 'applies_to_type', 'module', 'is_active'], 'commission_rules_lookup_index');
        });

        Schema::create('commission_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('commission_rule_id')->nullable()->constrained('commission_rules')->nullOnDelete();
            $table->foreignId('wallet_ledger_entry_id')->nullable()->constrained('wallet_ledger_entries')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamp('created_at')->useCurrent();

            // One commission credit per booking — the idempotency guard
            // CommissionService relies on, same pattern as cashback_credits.
            $table->unique('booking_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_credits');
        Schema::dropIfExists('commission_rules');
    }
};
