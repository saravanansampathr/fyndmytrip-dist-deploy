<?php

namespace App\Domains\Cashback\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per booking that ever received cashback — the unique(booking_id)
 * constraint (see create_cashback_rules_table migration) is what makes
 * CashbackService::applyCashback() safe to call every time a booking is
 * finalized without risking a double credit.
 */
class CashbackCredit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'booking_id',
        'cashback_rule_id',
        'wallet_ledger_entry_id',
        'amount',
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
