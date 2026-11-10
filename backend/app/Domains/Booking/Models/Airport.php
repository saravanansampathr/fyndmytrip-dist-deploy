<?php

namespace App\Domains\Booking\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Minimal route-classification reference data — see the
 * create_airports_table migration's docblock for why this exists and how
 * an unseeded code is treated. Global (not tenant-scoped): every tenant
 * shares the same airport-to-country mapping.
 */
class Airport extends Model
{
    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'city',
        'country_code',
        'country_name',
    ];
}
