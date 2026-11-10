<?php

namespace App\Domains\Auth\Services;

use App\Domains\Auth\Models\RolePermission;
use Illuminate\Support\Facades\Cache;

/**
 * Loads and caches the role_key -> [permission_key, ...] map from
 * roles_permissions (Database Design Document §3.3). Cached for 24h and
 * busted automatically whenever a RolePermission row is created/deleted.
 */
class PermissionRegistry
{
    protected const CACHE_KEY = 'fyndmytrip:role_permissions_map';

    /**
     * @return array<string, array<int, string>>
     */
    public function map(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addDay(), function () {
            $map = [];

            foreach (RolePermission::query()->get(['role_key', 'permission_key']) as $row) {
                $map[$row->role_key][] = $row->permission_key;
            }

            return $map;
        });
    }

    public function roleHasPermission(string $roleKey, string $permissionKey): bool
    {
        $permissions = $this->map()[$roleKey] ?? [];

        return in_array($permissionKey, $permissions, true) || in_array('*', $permissions, true);
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
