<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storefront footer's "Subscribe for deals" box (Platform Roadmap: OTA-style
 * public storefront). Deliberately its own tiny table rather than piggy-
 * backing on `users` — a newsletter signup is anonymous (no password, no
 * booking history, may not even become a customer) and tenant_id is
 * NULLABLE for the same reason Coupon's is: the platform-wide storefront
 * (no resolved tenant) can still collect a signup. Unsubscribing keeps the
 * row (unsubscribed_at set) rather than deleting it, so a re-subscribe
 * doesn't fight the unique index and the tenant can see churn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletter_subscribers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('email', 191);
            $table->timestamp('subscribed_at')->useCurrent();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_subscribers');
    }
};
