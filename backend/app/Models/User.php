<?php

namespace App\Models;

use App\Domains\Auth\Concerns\HasPermissions;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string $role
 * @property string $status
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasPermissions, Notifiable, SoftDeletes;

    // Mirrors the users.role ENUM in the Database Design Document §3.2.
    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_STAFF = 'staff';
    public const ROLE_PARTNER_ADMIN = 'partner_admin';
    public const ROLE_AGENT = 'agent';
    public const ROLE_SUB_AGENT = 'sub_agent';
    public const ROLE_STAFF_USER = 'staff_user';
    public const ROLE_API_USER = 'api_user';
    public const ROLE_CUSTOMER = 'customer';

    public const STATUS_INVITED = 'invited';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_DEACTIVATED = 'deactivated';

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'phone',
        'password_hash',
        'role',
        'status',
    ];

    protected $hidden = [
        'password_hash',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    protected $casts = [
        'last_login_at' => 'datetime',
        'two_factor_confirmed_at' => 'datetime',
    ];

    /**
     * A login token that expires after the session length set in admin
     * (Security settings): shorter for staff and agents, longer for
     * customers.
     */
    public function issueApiToken(string $name = 'api'): string
    {
        $security = app(\App\Domains\Settings\Services\SettingsService::class)->get('security');
        $expiresAt = $this->role === self::ROLE_CUSTOMER
            ? now()->addDays(max(1, (int) $security['customer_session_days']))
            : now()->addHours(max(1, (int) $security['staff_session_hours']));

        return $this->createToken($name, ['*'], $expiresAt)->plainTextToken;
    }

    /**
     * Laravel's Authenticatable expects an "auth password" accessor; the
     * Database Design Document names the column password_hash (not
     * password) to make explicit that it is never queried/selected in bulk
     * listing endpoints by accident.
     */
    public function getAuthPassword(): ?string
    {
        return $this->password_hash;
    }

    protected static function booted(): void
    {
        static::creating(function (self $user) {
            if (empty($user->uuid)) {
                $user->uuid = (string) \Illuminate\Support\Str::uuid();
            }

            if (empty($user->status)) {
                $user->status = self::STATUS_INVITED;
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    public function isPlatformStaff(): bool
    {
        return in_array($this->role, [self::ROLE_SUPER_ADMIN, self::ROLE_STAFF], true);
    }

    /**
     * Which login-user roles make sense to create under a given tenant type
     * — used by TenantUserController's tenant/staff assignment screen so
     * the role dropdown only ever offers roles that fit the tenant being
     * staffed (Database Design Document §3.2).
     *
     * @return string[]
     */
    public static function assignableRolesFor(string $tenantType): array
    {
        return match ($tenantType) {
            \App\Domains\Tenancy\Models\Tenant::TYPE_MASTER => [self::ROLE_SUPER_ADMIN, self::ROLE_STAFF],
            \App\Domains\Tenancy\Models\Tenant::TYPE_WHITE_LABEL => [self::ROLE_PARTNER_ADMIN, self::ROLE_STAFF_USER],
            \App\Domains\Tenancy\Models\Tenant::TYPE_AGENT => [self::ROLE_AGENT, self::ROLE_STAFF_USER],
            \App\Domains\Tenancy\Models\Tenant::TYPE_SUB_AGENT => [self::ROLE_SUB_AGENT, self::ROLE_STAFF_USER],
            \App\Domains\Tenancy\Models\Tenant::TYPE_API_PARTNER => [self::ROLE_API_USER],
            default => [self::ROLE_STAFF_USER],
        };
    }
}
