<?php

namespace App\Domains\Auth\Concerns;

use App\Domains\Auth\Services\PermissionRegistry;

/**
 * Mixed into App\Models\User. super_admin implicitly holds every
 * permission; every other role is resolved against roles_permissions via
 * PermissionRegistry — see Database Design Document §3.3.
 */
trait HasPermissions
{
    public function hasPermission(string $permissionKey): bool
    {
        if ($this->role === self::ROLE_SUPER_ADMIN) {
            return true;
        }

        return app(PermissionRegistry::class)->roleHasPermission($this->role, $permissionKey);
    }

    public function hasAnyPermission(array $permissionKeys): bool
    {
        foreach ($permissionKeys as $key) {
            if ($this->hasPermission($key)) {
                return true;
            }
        }

        return false;
    }
}
