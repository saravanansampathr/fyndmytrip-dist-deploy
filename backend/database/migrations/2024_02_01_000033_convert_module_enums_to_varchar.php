<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bug found deploying Activities (batch34): `markup_rules.module`,
 * `bookings.module` and `tenant_feature_flags.module_key` were all created
 * (2024_01_01_000005/000006/000001) as hard-coded MySQL ENUMs listing every
 * Product Module that existed *at the time each of those files was last
 * edited* — up to 'cruise'. Those three tables were seeded/populated long
 * before this migration exists, so simply adding 'activity' to the enum
 * list inside those already-run migration files (as ALL_MODULES etc. were
 * updated for Activities) does nothing to the live column: MySQL's ENUM
 * only accepts its declared literal list, so the very first `activity`
 * value inserted anywhere (ActivitySupplierSeeder's MarkupRule row) failed
 * with "Data truncated for column 'module'" — and every real
 * ActivityBookingController::hold() would have hit the exact same error
 * against bookings.module right after.
 *
 * Fix, once and for all: widen all three module-ish columns from ENUM to a
 * plain VARCHAR(20), exactly the same "ENUM isn't portable/maintainable,
 * enforce the allowed-value list in application validation instead" move
 * 2024_02_01_000015 already made for markup_rules.rule_type /
 * applies_to_role. This means the *next* new Product Module (Phase 4/5/7
 * might add one) never needs a schema migration for these three columns
 * again — only the application-level MODULES/ALL_MODULES arrays.
 *
 * MySQL: a plain ALTER ... MODIFY is enough, values are preserved as-is
 * and any index on the column stays valid. SQLite (tests): enum() is
 * emulated as a CHECK constraint that MODIFY can't touch, so — as in
 * 000015 — recreate the column under a temp name, copy the data across,
 * drop the old column, rename, and reinstate whichever index/unique
 * constraint originally covered it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // Laravel 11 rebuilds SQLite tables natively for ->change(),
            // dropping the enum CHECK constraint and keeping indexes.
            Schema::table('markup_rules', fn (Blueprint $t) => $t->string('module', 20)->change());
            Schema::table('bookings', fn (Blueprint $t) => $t->string('module', 20)->change());
            Schema::table('tenant_feature_flags', fn (Blueprint $t) => $t->string('module_key', 20)->change());
        } else {
            DB::statement('ALTER TABLE markup_rules MODIFY module VARCHAR(20) NOT NULL');
            DB::statement('ALTER TABLE bookings MODIFY module VARCHAR(20) NOT NULL');
            DB::statement('ALTER TABLE tenant_feature_flags MODIFY module_key VARCHAR(20) NOT NULL');
        }
    }

    public function down(): void
    {
        // Deliberately a no-op: reverting to a fixed ENUM list would just
        // reintroduce the bug this migration exists to fix, and nothing
        // downstream depends on these columns being ENUMs rather than
        // VARCHAR(20).
    }
};
