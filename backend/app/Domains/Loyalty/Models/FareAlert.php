<?php

namespace App\Domains\Loyalty\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class FareAlert extends Model
{
    protected $fillable = ['tenant_id', 'user_id', 'email', 'origin', 'destination', 'depart_date', 'adults', 'cabin_class', 'target_price', 'last_price', 'currency', 'last_checked_at', 'notified_at', 'is_active', 'token'];

    protected $casts = ['depart_date' => 'date', 'target_price' => 'decimal:2', 'last_price' => 'decimal:2', 'last_checked_at' => 'datetime', 'notified_at' => 'datetime', 'is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(fn (self $a) => $a->token ??= Str::random(40));
    }
}
