<?php

namespace App\Domains\Commission\Models;

use App\Domains\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform Roadmap Phase 7 — the mirror image of
 * App\Domains\Suppliers\Models\MarkupRule: markup is what a tenant charges
 * DOWN the chain (a parent capping what a child can charge); commission is
 * what a tenant PAYS down the chain instead, rewarding whichever
 * agent/sub-agent/white-label operator/API partner actually sold the
 * booking. tenant_id is the tenant GRANTING the commission (a parent, or
 * ultimately Master for a platform-wide default) — see
 * App\Domains\Commission\Services\CommissionService::bestRuleFor().
 */
class CommissionRule extends Model
{
    use BelongsToTenant;

    public const TYPE_PERCENTAGE = 'percentage';

    /** Flat amount credited once per booking (PNR), regardless of passenger count. */
    public const TYPE_FLAT_PER_PNR = 'flat_per_pnr';

    /** Flat amount multiplied by the booking's passenger count. */
    public const TYPE_FLAT_PER_PAX = 'flat_per_pax';

    public const TYPES = [
        self::TYPE_PERCENTAGE,
        self::TYPE_FLAT_PER_PNR,
        self::TYPE_FLAT_PER_PAX,
    ];

    /** The tenant TYPE this rule rewards — never Tenant::TYPE_MASTER, nobody sits above Master to reward it. */
    public const APPLIES_TO_TYPES = [
        \App\Domains\Tenancy\Models\Tenant::TYPE_AGENT,
        \App\Domains\Tenancy\Models\Tenant::TYPE_SUB_AGENT,
        \App\Domains\Tenancy\Models\Tenant::TYPE_WHITE_LABEL,
        \App\Domains\Tenancy\Models\Tenant::TYPE_API_PARTNER,
    ];

    public const SCOPE_DOMESTIC = 'domestic';

    public const SCOPE_INTERNATIONAL = 'international';

    public const SCOPE_BOTH = 'both';

    public const BOOKING_SCOPES = [self::SCOPE_DOMESTIC, self::SCOPE_INTERNATIONAL, self::SCOPE_BOTH];

    protected $fillable = [
        'tenant_id',
        'name',
        'module',
        'applies_to_type',
        'rule_type',
        'config',
        'max_commission_amount',
        'booking_scope',
        'is_active',
    ];

    protected $casts = [
        'config' => 'array',
        'max_commission_amount' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $rule) {
            if (empty($rule->booking_scope)) {
                $rule->booking_scope = self::SCOPE_BOTH;
            }
        });
    }

    /** The single numeric value this rule carries, whatever its type — percentage or flat amount. */
    public function value(): string
    {
        return (string) ($this->config['percentage'] ?? $this->config['amount'] ?? '0');
    }
}
