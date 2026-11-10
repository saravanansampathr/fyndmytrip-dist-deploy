<?php

namespace App\Domains\Wallet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only ledger row — the single source of truth for a wallet's
 * balance (Database Design Document §5.2). No application code may UPDATE
 * or DELETE a row here; WalletService only ever INSERTs.
 *
 * @property int $id
 * @property int $wallet_id
 * @property string $entry_type
 * @property string $amount
 * @property string $running_balance
 * @property string $reference_type
 * @property int|null $reference_id
 */
class WalletLedgerEntry extends Model
{
    public $timestamps = false;

    public const CREDIT = 'credit';
    public const DEBIT = 'debit';

    public const REF_TOPUP = 'topup';
    public const REF_BOOKING_PAYMENT = 'booking_payment';
    public const REF_REFUND = 'refund';
    public const REF_CASHBACK = 'cashback';
    public const REF_COMMISSION_PAYOUT = 'commission_payout';
    public const REF_ADJUSTMENT = 'adjustment';
    /** Verified offline bank transfer/cash deposit — see OfflinePaymentService::approve(). */
    public const REF_OFFLINE_PAYMENT = 'offline_payment';
    /** Group booking deposit/balance debits — see GroupBookingService::acceptQuote()/finalize(). Kept under the 20-char reference_type column width (2024_02_01_000040). */
    public const REF_GROUP_DEPOSIT = 'group_deposit';
    public const REF_GROUP_BALANCE = 'group_balance';
    /** Hotel group booking deposit/balance debits — see HotelGroupBookingService::acceptQuote()/finalize(). Also kept under the 20-char column width. */
    public const REF_HOTEL_GROUP_DEPOSIT = 'hotel_group_deposit';
    public const REF_HOTEL_GROUP_BALANCE = 'hotel_group_balance';

    protected $fillable = [
        'wallet_id',
        'entry_type',
        'amount',
        'running_balance',
        'reference_type',
        'reference_id',
        'remarks',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'running_balance' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
