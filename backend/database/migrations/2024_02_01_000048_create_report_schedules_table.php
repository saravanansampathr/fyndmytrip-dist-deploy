<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Anything missed... scheduled/emailed reports" — the last reporting gap:
 * every report so far is pull-only (someone has to log in and download
 * it). This table lets a user ask for the Overview summary report to be
 * generated and emailed on its own, on a cadence, without anyone coming
 * back to click a button.
 *
 * tenant_id is nullable and means "platform-wide" — same convention
 * ReportsController::resolveTenantIds() already uses (null = don't filter
 * by tenant at all), restricted to platform staff only
 * (ReportScheduleController::authorizeCreate()). A non-staff tenant always
 * gets a concrete tenant_id (their own), never null.
 *
 * recipients is a JSON array of plain email addresses rather than a
 * separate pivot table — nobody needs to query "which schedules email
 * person X", and a handful of emails per schedule never grows large
 * enough to need relational modeling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();

            // Only 'summary' (the Overview report) is implemented today —
            // a string column rather than an enum so a future report type
            // (ledger, commission_statement) can be added without a
            // migration, same reasoning every module/channel column in
            // this codebase already follows.
            $table->string('report_type', 30)->default('summary');

            $table->string('frequency', 10); // daily | weekly | monthly
            $table->string('format', 10)->default('pdf'); // pdf | xlsx
            $table->json('recipients');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_sent_at')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'frequency'], 'report_schedules_due_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedules');
    }
};
