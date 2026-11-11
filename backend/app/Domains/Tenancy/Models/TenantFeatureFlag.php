<?php

namespace App\Domains\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Database Design Document §3.4 — per-tenant, per-module enable/disable
 * plus a free-form AI feature toggle map (Master Brief §9's ten AI
 * differentiators are switched on/off per tenant per pricing tier here).
 */
class TenantFeatureFlag extends Model
{
    protected $fillable = [
        'tenant_id',
        'module_key',
        'is_enabled',
        'ai_features_enabled',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'ai_features_enabled' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $flag) {
            if ($flag->is_enabled === null) {
                $flag->is_enabled = true;
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
