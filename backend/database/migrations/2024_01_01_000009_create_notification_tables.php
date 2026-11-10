<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Database Design Document §9. notification_gateway_configs.tenant_id is
 * nullable (NULL = platform-default channel account).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_gateway_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->enum('channel', ['sms', 'whatsapp', 'email']);
            $table->string('provider', 50);
            $table->text('credentials_encrypted');
            $table->string('sender_id', 50)->nullable();
            $table->json('dlt_template_ids')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->enum('channel', ['sms', 'whatsapp', 'email']);
            $table->text('recipient');
            $table->string('template_key', 100);
            $table->enum('status', ['queued', 'sent', 'delivered', 'failed'])->default('queued');
            $table->string('provider_message_id', 100)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('notification_gateway_configs');
    }
};
