<?php

namespace App\Domains\Auth\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The flat RBAC map described in the Database Design Document §3.3: one row
 * per (role_key, permission_key) grant. role_key mirrors users.role;
 * permission_key is a free-form dotted string such as "booking.cancel" or
 * "wallet.topup", checked by PermissionRegistry / the HasPermissions trait.
 */
class RolePermission extends Model
{
    public $timestamps = false;

    protected $table = 'roles_permissions';

    protected $fillable = [
        'role_key',
        'permission_key',
    ];

    protected static function booted(): void
    {
        static::created(fn () => app(\App\Domains\Auth\Services\PermissionRegistry::class)->forget());
        static::deleted(fn () => app(\App\Domains\Auth\Services\PermissionRegistry::class)->forget());
    }
}
