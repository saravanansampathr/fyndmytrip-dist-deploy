<?php

namespace App\Domains\Commission\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per booking that ever paid out commission — the unique(booking_id)
 * constraint (see create_commission_tables migration) is what makes
 * CommissionService::applyCommission() safe to call every time a booking is
 * finalized without risking a double credit. Mirrors
 * App\Domains\Cashback\Models\CashbackCredit exactly.
 */
class CommissionCredit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'booking_id',
        'commission_rule_id',
        'wallet_ledger_entry_id',
        'amount',
        'gross_amount',
        'tds_amount',
        'tds_rate',
        'pan',
        'financial_year',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'gross_amount' => 'decimal:2',
        'tds_amount' => 'decimal:2',
        'tds_rate' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $credit) {
            if (empty($credit->created_at)) {
                $credit->created_at = now();
            }
        });
    }
}
