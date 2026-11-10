<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A before/after snapshot per credit change (limit increased/decreased,
 * frozen/revoked/reinstated, terms or due-date edited) — money never moves
 * on these rows (that's what wallet_ledger_entries is for), so they live
 * in their own append-style table rather than overloading the ledger's
 * reference_type list. Shown on the partner's Wallet page and the staff
 * Credit admin screen; also what CreditController::update() emails the
 * affected user about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_credit_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('wallets')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('old_credit_limit', 12, 2);
            $table->decimal('new_credit_limit', 12, 2);
            $table->string('old_credit_terms', 20);
            $table->string('new_credit_terms', 20);
            $table->date('old_credit_next_due_at')->nullable();
            $table->date('new_credit_next_due_at')->nullable();
            $table->string('old_credit_status', 10);
            $table->string('new_credit_status', 10);
            $table->string('remarks', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['wallet_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_credit_audit_logs');
    }
};
