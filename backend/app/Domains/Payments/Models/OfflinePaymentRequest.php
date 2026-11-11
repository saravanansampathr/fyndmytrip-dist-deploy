<?php

namespace App\Domains\Payments\Models;

use App\Domains\Tenancy\Concerns\BelongsToTenant;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Models\WalletLedgerEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A partner/agent/sub-agent/API-partner/white-label user's claim that they
 * paid the platform outside of it (bank transfer, cash deposit, UPI, ...);
 * sits 'pending' until staff verify it against their own bank statement
 * and approve (crediting the wallet — see OfflinePaymentService::approve())
 * or reject it. See the 2024_02_01_000041 migration's docblock.
 */
class OfflinePaymentRequest extends Model
{
    use BelongsToTenant;

    public const MODE_NETBANKING = 'netbanking';
    public const MODE_CASH_DEPOSIT = 'cash_deposit';
    public const MODE_NEFT = 'neft';
    public const MODE_RTGS = 'rtgs';
    public const MODE_IMPS = 'imps';
    public const MODE_UPI = 'upi';
    public const MODE_OTHER = 'other';

    public const MODES = [
        self::MODE_NETBANKING,
        self::MODE_CASH_DEPOSIT,
        self::MODE_NEFT,
        self::MODE_RTGS,
        self::MODE_IMPS,
        self::MODE_UPI,
        self::MODE_OTHER,
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'tenant_id',
        'wallet_id',
        'requested_by_user_id',
        'payment_mode',
        'amount',
        'transaction_date',
        'transaction_ref_number',
        'remarks',
        'status',
        'reviewed_by_user_id',
        'reviewed_at',
        'rejection_reason',
        'wallet_ledger_entry_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_date' => 'date',
        'reviewed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $request) {
            if (empty($request->status)) {
                $request->status = self::STATUS_PENDING;
            }
        });
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(WalletLedgerEntry::class, 'wallet_ledger_entry_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(OfflinePaymentAttachment::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
