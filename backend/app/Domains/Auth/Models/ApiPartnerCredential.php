<?php

namespace App\Domains\Auth\Models;

use App\Domains\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * OAuth2-style client-credential clients for the "FyndMyTrip Connect API"
 * (Master Brief §2, business model layer 3). Database Design Document §8.4.
 * client_secret_hash is hashed at write time by the issuing service, never
 * stored or logged in plaintext.
 */
class ApiPartnerCredential extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'label',
        'client_id',
        'client_secret_hash',
        'allowed_domain',
        'allowed_scopes',
        'rate_limit_per_min',
        'is_active',
    ];

    protected $hidden = [
        'client_secret_hash',
    ];

    protected $casts = [
        'allowed_scopes' => 'array',
        'rate_limit_per_min' => 'integer',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $credential) {
            if (empty($credential->client_id)) {
                $credential->client_id = (string) \Illuminate\Support\Str::uuid();
            }

            if (empty($credential->rate_limit_per_min)) {
                $credential->rate_limit_per_min = 60;
            }
        });
    }
}
