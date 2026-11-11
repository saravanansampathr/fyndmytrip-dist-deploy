<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Margins keeping options needed for all users including admins and they
 * can choose the users too or service."
 *
 * - user_id: a rule that only applies when this exact user books (any role
 *   — a single agent, sub-agent login, API user, staff member, admin or
 *   direct customer).
 * - target_tenant_id: a rule that only applies to bookings made under one
 *   specific downstream account (a chosen Agent / White-Label / API
 *   Partner tenant).
 * - module may now be 'all' (every service) and applies_to_role gains
 *   white_label / api_partner / staff / admin / all — both are VARCHAR(20)
 *   since 000015/000033, so no column change is needed for those.
 *
 * Also grants the new markup.manage / markup.view permissions (see RolePermissionSeeder)
 * so existing installs keep working before the seeder is re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('markup_rules', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('tenant_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('target_tenant_id')->nullable()->after('user_id')->constrained('tenants')->cascadeOnDelete();
            $table->index(['module', 'applies_to_role', 'is_active']);
        });

        $now = now();
        $grants = [
            'staff' => 'markup.manage',
            'partner_admin' => 'markup.manage',
            'agent' => 'markup.manage',
            'api_user' => 'markup.manage',
            'sub_agent' => 'markup.view',
        ];

        foreach ($grants as $role => $permission) {
            $exists = DB::table('roles_permissions')
                ->where('role_key', $role)->where('permission_key', $permission)->exists();
            if (! $exists) {
                $row = ['role_key' => $role, 'permission_key' => $permission];
                foreach (['created_at', 'updated_at'] as $col) {
                    if (Schema::hasColumn('roles_permissions', $col)) {
                        $row[$col] = $now;
                    }
                }
                DB::table('roles_permissions')->insert($row);
            }
        }

        $this->forgetPermissionCache();
    }

    public function down(): void
    {
        Schema::table('markup_rules', function (Blueprint $table) {
            $table->dropIndex(['module', 'applies_to_role', 'is_active']);
            $table->dropConstrainedForeignId('target_tenant_id');
            $table->dropConstrainedForeignId('user_id');
        });

        DB::table('roles_permissions')->whereIn('permission_key', ['markup.manage', 'markup.view'])->delete();
        $this->forgetPermissionCache();
    }

    /** Best effort — an unreachable cache store must not fail the migration. */
    private function forgetPermissionCache(): void
    {
        try {
            app(\App\Domains\Auth\Services\PermissionRegistry::class)->forget();
        } catch (\Throwable) {
            // The registry's cache entry expires within a day on its own.
        }
    }
};
