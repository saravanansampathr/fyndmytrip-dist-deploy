<?php

namespace App\Domains\Suppliers\Models;

use App\Domains\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How a White-Label Partner or Agent plugs in its own TBO/TripJack/Akbar/
 * custom supplier contract — Database Design Document §8.2. The
 * credentials_encrypted column uses Laravel's built-in `encrypted:array`
 * cast (application-layer envelope encryption via APP_KEY); production
 * deployments should additionally keep the key material itself in AWS
 * Secrets Manager / Vault per the Security, Compliance & DevOps Plan.
 */
class TenantSupplierConfig extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'supplier_id',
        'credentials_encrypted',
        'markup_rule_id',
        'is_active',
        'priority_order',
    ];

    protected $casts = [
        'credentials_encrypted' => 'encrypted:array',
        'is_active' => 'boolean',
        'priority_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $config) {
            if (empty($config->priority_order)) {
                $config->priority_order = 1;
            }
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function markupRule(): BelongsTo
    {
        return $this->belongsTo(MarkupRule::class);
    }
}
