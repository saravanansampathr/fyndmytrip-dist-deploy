<?php

namespace App\Domains\Tenancy\Models;

use App\Domains\Wallet\Models\Wallet;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A node in the recursive tenant hierarchy: Super Admin (Master, the
 * platform root — not represented as a row) -> White-Label Partner -> Agent
 * -> Sub-Agent -> ... to a configurable max depth, plus Direct Agent and
 * API Partner tenants attached straight to Master. See Master Brief §3 and
 * Database Design Document §3.1.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $parent_tenant_id
 * @property string $type
 * @property string $name
 * @property string|null $legal_name
 * @property string|null $custom_domain
 * @property string|null $subdomain
 * @property string $status
 * @property int $max_sub_tenant_depth
 * @property array|null $config
 * @property int|null $template_id
 * @property string|null $logo_url
 * @property string|null $support_email
 * @property string|null $support_phone
 * @property string|null $business_address
 * @property string|null $business_registration_no
 * @property string $dns_status
 * @property \Illuminate\Support\Carbon|null $dns_verified_at
 * @property array|null $enabled_modules
 */
class Tenant extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPE_MASTER = 'master';
    public const TYPE_WHITE_LABEL = 'white_label';
    public const TYPE_AGENT = 'agent';
    public const TYPE_SUB_AGENT = 'sub_agent';
    public const TYPE_API_PARTNER = 'api_partner';

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_CLOSED = 'closed';

    public const DNS_NOT_CONFIGURED = 'not_configured';
    public const DNS_PENDING = 'pending';
    public const DNS_VERIFIED = 'verified';

    // There used to be a DNS_CNAME_TARGET constant here ('whitelabel.
    // fyndmytrip.com') that every custom_domain was supposed to CNAME to —
    // that model fits a CDN/load-balancer fronting many hostnames, which
    // this deployment isn't: it's one cPanel server at demo.fyndmytrip.in /
    // api-demo.fyndmytrip.in, and that target domain was never even real
    // DNS this server answers to, so verification could never actually
    // succeed. See WhiteLabelController::verifyDns()'s docblock for the
    // A-record-to-this-server's-own-IP check that replaced it.

    /** Every Product Module a white-label site can choose to expose — null/empty enabled_modules means all of these. */
    public const ALL_MODULES = ['flight', 'hotel', 'bus', 'cab', 'holiday', 'insurance', 'visa', 'cruise', 'activity'];

    protected $fillable = [
        'parent_tenant_id',
        'type',
        'name',
        'legal_name',
        'custom_domain',
        'subdomain',
        'status',
        'max_sub_tenant_depth',
        'config',
        'template_id',
        'logo_url',
        'support_email',
        'support_phone',
        'business_address',
        'business_registration_no',
        'dns_status',
        'dns_verified_at',
        'enabled_modules',
    ];

    protected $casts = [
        'config' => 'array',
        'max_sub_tenant_depth' => 'integer',
        'template_id' => 'integer',
        'dns_verified_at' => 'datetime',
        'enabled_modules' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $tenant) {
            if (empty($tenant->uuid)) {
                $tenant->uuid = (string) \Illuminate\Support\Str::uuid();
            }

            if (empty($tenant->status)) {
                $tenant->status = self::STATUS_PENDING;
            }

            if (empty($tenant->max_sub_tenant_depth)) {
                $tenant->max_sub_tenant_depth = config('tenancy.default_max_sub_tenant_depth');
            }
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_tenant_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_tenant_id');
    }

    public function ancestorClosures(): HasMany
    {
        return $this->hasMany(TenantHierarchyClosure::class, 'descendant_tenant_id');
    }

    public function descendantClosures(): HasMany
    {
        return $this->hasMany(TenantHierarchyClosure::class, 'ancestor_tenant_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(\App\Models\User::class, 'tenant_id');
    }

    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class, 'tenant_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** @return string[] */
    public function effectiveModules(): array
    {
        return ! empty($this->enabled_modules) ? $this->enabled_modules : self::ALL_MODULES;
    }

    /**
     * `config` is a free-form JSON column that nothing else in the
     * codebase reads or writes yet — it's the natural home for the
     * sub-keys added by WhiteLabelController (social_links, any tenant)
     * and PlatformSettingsController (platform_settings, Master tenant
     * only) without a schema change per feature. Merges recursively so
     * saving one sub-key (e.g. social_links) never wipes another
     * (e.g. platform_settings) already stored alongside it.
     *
     * @return array<string, mixed>
     */
    public function withMergedConfig(array $patch): array
    {
        return array_replace_recursive($this->config ?? [], $patch);
    }
}
