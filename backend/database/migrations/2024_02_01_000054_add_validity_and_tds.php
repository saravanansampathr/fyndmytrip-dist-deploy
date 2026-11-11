<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seasonal margin and commission rules (start / end dates), and TDS
 * deducted from agent commission (Income Tax Act s.194H).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['markup_rules', 'commission_rules'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->dateTime('valid_from')->nullable();
                $table->dateTime('valid_until')->nullable();
            });
        }
        Schema::table('commission_credits', function (Blueprint $table) {
            $table->decimal('gross_amount', 12, 2)->nullable();
            $table->decimal('tds_amount', 12, 2)->default(0);
            $table->decimal('tds_rate', 5, 2)->nullable();
            $table->string('pan', 10)->nullable();
            $table->string('financial_year', 7)->nullable()->index();
        });
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('pan', 10)->nullable();
        });
    }

    public function down(): void
    {
        foreach (['markup_rules', 'commission_rules'] as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->dropColumn(['valid_from', 'valid_until']));
        }
        Schema::table('commission_credits', fn (Blueprint $t) => $t->dropColumn(['gross_amount', 'tds_amount', 'tds_rate', 'pan', 'financial_year']));
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn('pan'));
    }
};
