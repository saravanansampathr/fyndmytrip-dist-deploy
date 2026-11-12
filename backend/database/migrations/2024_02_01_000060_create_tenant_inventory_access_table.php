<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which inventories (supplier connections such as Akbar, TBO, TripJack,
 * and FyndMyTrip's own contracted stock) the master account shares with
 * each account below it: white-label partners, agents, sub-agents and API
 * partners. One row per account and inventory only when an admin has set
 * it; no row means the account follows the platform default
 * (Accounts & inventory → default for accounts with nothing set).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_inventory_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // A supplier code (suppliers.code) or 'own_inventory'.
            $table->string('inventory_key', 50);
            $table->boolean('is_enabled')->default(true);
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'inventory_key']);
            $table->index('inventory_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_inventory_access');
    }
};
