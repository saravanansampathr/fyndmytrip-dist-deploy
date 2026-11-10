<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credit system for partners/agents/sub-agents/API partners/white-labels
 * (Platform Roadmap: "enable credit system ... fix the limit by giving
 * every week payment terms"). Deliberately extends the EXISTING wallet
 * rather than a parallel ledger: WalletService already debits an
 * OWNER_USER wallet for every booking payment (see
 * FlightBookingController::confirm()/payGateway()), so credit is modeled
 * as how far that same balance may go negative — credit_limit is the
 * floor's magnitude, not a separate pool. balance still means exactly what
 * it always has (see Wallet's docblock); it can simply go negative now,
 * down to -credit_limit, for a wallet with credit enabled. A "credit
 * limit" of 0 (the default) preserves today's behavior exactly — no
 * wallet may go negative unless staff explicitly grants credit.
 *
 * credit_terms/credit_next_due_at are informational only (per the user's
 * "manual tracking" decision) — staff read them off the Credit admin
 * screen and chase repayment themselves; nothing here auto-blocks or
 * auto-suspends on a missed due date. credit_status is the actual on/off
 * switch: "frozen" temporarily disables the credit floor (forces it back
 * to 0) without discarding the configured credit_limit, so revoking and
 * later re-enabling credit doesn't lose the negotiated limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->decimal('credit_limit', 12, 2)->default(0)->after('balance');
            $table->string('credit_terms', 20)->default('none')->after('credit_limit');
            $table->date('credit_next_due_at')->nullable()->after('credit_terms');
            $table->string('credit_status', 10)->default('active')->after('credit_next_due_at');
        });
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn(['credit_limit', 'credit_terms', 'credit_next_due_at', 'credit_status']);
        });
    }
};
