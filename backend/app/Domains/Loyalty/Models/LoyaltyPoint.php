<?php

namespace App\Domains\Loyalty\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyPoint extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'tenant_id', 'points', 'reason', 'booking_id', 'note', 'expires_at'];

    protected $casts = ['points' => 'integer', 'expires_at' => 'datetime'];
}
