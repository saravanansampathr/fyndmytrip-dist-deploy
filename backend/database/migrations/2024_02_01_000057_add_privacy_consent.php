<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Privacy (India's DPDP Act): when a person accepted the terms, whether
 * they agreed to offers by email/SMS/WhatsApp, a log of every consent
 * change as proof, and when an account was erased.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('terms_accepted_at')->nullable();
            $table->boolean('marketing_consent')->default(false);
            $table->timestamp('erased_at')->nullable();
        });
        Schema::create('consent_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('email', 191)->nullable();
            $table->string('purpose', 40); // terms, marketing, data_export, erasure
            $table->boolean('granted');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_logs');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['terms_accepted_at', 'marketing_consent', 'erased_at']);
        });
    }
};
