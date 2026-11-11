<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 (launch blockers): settings admins can change without a code
 * release, GST invoice/credit-note series, message templates, legal pages,
 * two-factor login and real payment-gateway references.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Key/value settings edited from the admin panel (tax, security,
        // monitoring...). One row per group, value is JSON.
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_secret');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_confirmed_at');
        });

        // GST tax invoices and credit notes, numbered in one unbroken
        // series per financial year (April to March).
        Schema::create('invoice_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->string('type', 20); // invoice | credit_note
            $table->string('financial_year', 9); // e.g. 2026-27
            $table->unsignedInteger('sequence');
            $table->string('number', 16)->unique();
            $table->foreignId('original_invoice_id')->nullable()->constrained('invoice_documents')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('tcs_amount', 12, 2)->default(0);
            $table->json('details')->nullable();
            $table->timestamp('issued_at');
            $table->timestamps();

            $table->unique(['type', 'financial_year', 'sequence']);
            $table->index(['booking_id', 'type']);
        });

        // Editable texts for every customer/agent message. tenant_id null =
        // the platform default; a white-label partner can override.
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('event', 60);
            $table->string('channel', 20); // email | sms | whatsapp
            $table->string('subject', 255)->nullable();
            $table->text('body');
            // DLT template id (India SMS) or approved WhatsApp template name.
            $table->string('provider_template_id', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'event', 'channel']);
        });

        Schema::table('notification_logs', function (Blueprint $table) {
            $table->string('provider', 50)->nullable()->after('channel');
            $table->text('error')->nullable()->after('provider_message_id');
        });

        // Terms, privacy, refund policy, about us... tenant_id null = the
        // platform's own pages, used by partners that have none.
        Schema::create('cms_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('slug', 100);
            $table->string('title', 191);
            $table->longText('body');
            $table->string('meta_description', 300)->nullable();
            $table->boolean('is_published')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
        });

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->string('gateway_order_id', 191)->nullable()->after('gateway_txn_id');
            $table->string('gateway_payment_id', 191)->nullable()->after('gateway_order_id');
            $table->boolean('is_live')->default(false)->after('gateway_payment_id');
            $table->index('gateway_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropIndex(['gateway_order_id']);
            $table->dropColumn(['gateway_order_id', 'gateway_payment_id', 'is_live']);
        });
        Schema::dropIfExists('cms_pages');
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->dropColumn(['provider', 'error']);
        });
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('invoice_documents');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_confirmed_at', 'two_factor_recovery_codes']);
        });
        Schema::dropIfExists('app_settings');
    }
};
