<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website content admins edit (banners, offers, FAQ, SEO per page) and
 * agent self sign-up with KYC documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('type', 20)->index(); // banner | offer | faq | seo | popular_route
            $table->string('module', 20)->nullable();
            $table->string('title', 191)->nullable();
            $table->text('body')->nullable();
            $table->string('image_url', 500)->nullable();
            $table->string('link_url', 500)->nullable();
            $table->json('data')->nullable();
            $table->integer('sort_order')->default(0);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'type', 'is_active']);
        });

        Schema::create('media_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable();
            $table->string('path', 255);
            $table->string('original_name', 191)->nullable();
            $table->string('mime', 100)->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->boolean('is_public')->default(true);
            $table->foreignId('uploaded_by')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('company_name', 191);
            $table->string('contact_name', 191);
            $table->string('email', 191)->index();
            $table->string('phone', 30);
            $table->string('pan', 10)->nullable();
            $table->string('gstin', 15)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('password_hash');
            $table->string('status', 12)->default('pending')->index(); // pending | approved | rejected
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('tenant_id')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('kyc_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_application_id')->nullable()->constrained('agent_applications')->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->index();
            $table->string('type', 30); // pan | gst | id_proof | address_proof | iata | other
            $table->foreignId('media_file_id')->constrained('media_files')->cascadeOnDelete();
            $table->date('expires_on')->nullable();
            $table->string('status', 12)->default('submitted'); // submitted | verified | rejected
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_documents');
        Schema::dropIfExists('agent_applications');
        Schema::dropIfExists('media_files');
        Schema::dropIfExists('content_items');
    }
};
