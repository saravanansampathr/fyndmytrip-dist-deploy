<?php

namespace App\Domains\Suppliers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The master catalogue of supplier integrations — the three launch
 * suppliers (TBO, TripJack, Akbar Travels) plus the mock adapter used until
 * real credentials are wired in, plus any custom supplier a white-label has
 * had certified for reuse. Database Design Document §8.1.
 */
class Supplier extends Model
{
    protected $fillable = [
        'code',
        'name',
        'supported_modules',
        'adapter_class',
    ];

    protected $casts = [
        'supported_modules' => 'array',
    ];

    public function tenantConfigs(): HasMany
    {
        return $this->hasMany(TenantSupplierConfig::class);
    }
}
