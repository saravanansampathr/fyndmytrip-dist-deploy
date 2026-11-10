<?php

namespace App\Domains\Suppliers\Models;

use App\Domains\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * "Keep the markup for each Agent and Whitelabel API, either fixed per
 * PNR or PAX or Destination or percentage wise" — one type per rule (the
 * admin picks a single type when creating it, no stacking). Every rule
 * belongs to the tenant that DEFINES it (an Agent, White-Label Partner, or
 * Master) and names the role it constrains: applies_to_role='b2c' is "what
 * I charge my own direct customers", 'agent'/'sub_agent' is "the ceiling I
 * cap the agents/sub-agents under me at". MarkupService::applyMarkup()
 * walks the acting tenant's ancestor chain and — for whichever type the
 * nearest rule in that chain uses — applies the most restrictive (lowest)
 * value found among same-type rules, so a child tenant can never configure
 * its way past a parent's cap. See Database Design Document §8.3.
 */
class MarkupRule extends Model
{
    use BelongsToTenant;

    public const TYPE_PERCENTAGE = 'percentage';

    /** Flat amount added once per booking (PNR), regardless of passenger count. */
    public const TYPE_FLAT_PER_PNR = 'flat_per_pnr';

    /** Flat amount multiplied by the booking's passenger count. */
    public const TYPE_FLAT_PER_PAX = 'flat_per_pax';

    /** Flat amount that only applies when the leg/offer matches this rule's origin/destination. */
    public const TYPE_FLAT_PER_DESTINATION = 'flat_per_destination';

    public const TYPES = [
        self::TYPE_PERCENTAGE,
        self::TYPE_FLAT_PER_PNR,
        self::TYPE_FLAT_PER_PAX,
        self::TYPE_FLAT_PER_DESTINATION,
    ];

    public const ROLE_AGENT = 'agent';

    public const ROLE_SUB_AGENT = 'sub_agent';

    /** Direct customers and guests. */
    public const ROLE_B2C = 'b2c';

    /** White-label partner admins (partner_admin). Falls back to 'agent' rules when none exist. */
    public const ROLE_WHITE_LABEL = 'white_label';

    /** API partner logins (api_user). Falls back to 'b2c' rules when none exist. */
    public const ROLE_API_PARTNER = 'api_partner';

    /** A tenant's own staff logins (staff_user). Falls back to 'b2c' rules when none exist. */
    public const ROLE_STAFF = 'staff';

    /** Platform admins booking themselves (super_admin / platform staff). Falls back to 'b2c'. */
    public const ROLE_ADMIN = 'admin';

    /** Every audience. */
    public const ROLE_ALL = 'all';

    public const ROLES = [
        self::ROLE_AGENT, self::ROLE_SUB_AGENT, self::ROLE_B2C, self::ROLE_WHITE_LABEL,
        self::ROLE_API_PARTNER, self::ROLE_STAFF, self::ROLE_ADMIN, self::ROLE_ALL,
    ];

    public const ROLE_LABELS = [
        self::ROLE_ALL => 'Everyone',
        self::ROLE_B2C => 'Direct customers (B2C)',
        self::ROLE_AGENT => 'Agents',
        self::ROLE_SUB_AGENT => 'Sub-agents',
        self::ROLE_WHITE_LABEL => 'White-label partners',
        self::ROLE_API_PARTNER => 'API partners',
        self::ROLE_STAFF => 'Staff users',
        self::ROLE_ADMIN => 'Admins',
    ];

    /** module value meaning "every service". */
    public const MODULE_ALL = 'all';

    /**
     * Domestic/international scoping — same three values as CashbackRule
     * and HolidayPackage's booking_scope, so a rule can be written "this
     * markup only applies to international flights" without touching
     * rule_type at all. BOTH (the default) matches everything, same as
     * leaving origin/destination empty already does for routes.
     */
    public const SCOPE_DOMESTIC = 'domestic';

    public const SCOPE_INTERNATIONAL = 'international';

    public const SCOPE_BOTH = 'both';

    public const BOOKING_SCOPES = [self::SCOPE_DOMESTIC, self::SCOPE_INTERNATIONAL, self::SCOPE_BOTH];

    protected $fillable = [
        'tenant_id',
        'user_id',
        'target_tenant_id',
        'name',
        'module',
        'rule_type',
        'config',
        'origin',
        'destination',
        'applies_to_role',
        'booking_scope',
        'supplier_code',
        'is_active',
    ];

    protected $casts = [
        'config' => 'array',
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

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    public function targetTenant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Domains\Tenancy\Models\Tenant::class, 'target_tenant_id');
    }

    public function owner(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Domains\Tenancy\Models\Tenant::class, 'tenant_id');
    }

    /** The single numeric value this rule carries, whatever its type — percentage or flat amount. */
    public function value(): string
    {
        return (string) ($this->config['percentage'] ?? $this->config['amount'] ?? '0');
    }
}
