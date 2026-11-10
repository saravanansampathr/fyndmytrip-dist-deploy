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

    public const ROLE_B2C = 'b2c';

    public const ROLES = [self::ROLE_AGENT, self::ROLE_SUB_AGENT, self::ROLE_B2C];

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

    /** The single numeric value this rule carries, whatever its type — percentage or flat amount. */
    public function value(): string
    {
        return (string) ($this->config['percentage'] ?? $this->config['amount'] ?? '0');
    }
}
