<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Raise amendment like requesting any correction like name spell mistake,
 * or date change, or add the baggage... so that it will assigned to
 * concerned agent/staff to look into it and give solution against each
 * ticket" — a lightweight support-ticket system always tied to one specific
 * booking (never a general/unattached ticket), with an assignee and a
 * reply thread so staff can converse with whoever raised it and record the
 * resolution.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->string('category', 40); // name_correction | date_change | add_baggage | pricing_query | other
            $table->string('subject', 191);
            $table->text('description');
            $table->string('status', 20)->default('open'); // open | in_progress | resolved | closed
            $table->string('priority', 10)->default('normal'); // low | normal | high
            $table->foreignId('raised_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['booking_id']);
            $table->index(['assigned_to_user_id', 'status']);
        });

        Schema::create('support_ticket_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('message');
            $table->timestamp('created_at')->nullable();

            $table->index(['ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_replies');
        Schema::dropIfExists('support_tickets');
    }
};
