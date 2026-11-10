<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform Roadmap Phase 4: "recent searches remembered per user underneath
 * the search box." One small table serves every Product Module — a search
 * is just {module, a one-line human summary, the exact params to replay
 * it}, module stored as plain VARCHAR (not an ENUM — see
 * 2024_02_01_000033's docblock for why: no schema migration should ever be
 * needed again just because a new Product Module was added). `params` is
 * whatever shape that module's own search form already builds for its own
 * search API call — RecentSearchController never interprets it, so every
 * module's search page can log/replay a search without a dedicated column
 * per field.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recent_searches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('module', 20);
            $table->string('summary', 191);
            $table->json('params');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'module', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recent_searches');
    }
};
