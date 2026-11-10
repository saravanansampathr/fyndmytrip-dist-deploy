<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Database Design Document §10.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_interaction_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('feature', ['search_nlp', 'itinerary_builder', 'chatbot', 'fraud_flag', 'price_alert']);
            $table->text('input_summary');
            $table->text('output_summary');
            $table->string('model_used', 100);
            $table->unsignedInteger('latency_ms');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('fraud_flags', function (Blueprint $table) {
            $table->id();
            $table->enum('subject_type', ['booking', 'wallet_ledger_entry']);
            $table->unsignedBigInteger('subject_id');
            $table->decimal('risk_score', 5, 2);
            $table->string('reason');
            $table->enum('status', ['open', 'reviewed', 'cleared', 'confirmed_fraud'])->default('open');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fraud_flags');
        Schema::dropIfExists('ai_interaction_logs');
    }
};
