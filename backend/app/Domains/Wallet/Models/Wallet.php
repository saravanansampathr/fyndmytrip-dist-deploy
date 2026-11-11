<?php

namespace App\Domains\Wallet\Models;

use App\Domains\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A closed-loop, non-transferable, non-cash-withdrawable ledger wallet
 * belonging to either a tenant (e.g. an Agent's working-capital wallet) or
 * an individual user (e.g. a B2C customer) — Database Design Document §5.1.
 *
 * balance is a cached/derived column: it must never be written directly by
 * booking or refund code. It is only ever recalculated by WalletService from
 * the append-only wallet_ledger_entries table.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $owner_type
 * @property int $owner_id
 * @property string $balance
 * @property string $currency
 * @property string $status
 * @property string $credit_limit
 * @property string $credit_terms
 * @property \Illuminate\Support\Carbon|null $credit_next_due_at
 * @property string $credit_status
 */
class Wallet extends Model
{
    use BelongsToTenant, HasFactory;

    public const OWNER_TENANT = 'tenant';
    public const OWNER_USER = 'user';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_FROZEN = 'frozen';
    public const STATUS_CLOSED = 'closed';

    /** credit_terms — informational settlement cadence only; see the 2024_02_01_000039 migration's docblock. */
    public const CREDIT_TERMS_NONE = 'none';
    public const CREDIT_TERMS_WEEKLY = 'weekly';
    public const CREDIT_TERMS_BIWEEKLY = 'biweekly';
    public const CREDIT_TERMS_MONTHLY = 'monthly';

    public const CREDIT_TERMS = [
        self::CREDIT_TERMS_NONE,
        self::CREDIT_TERMS_WEEKLY,
        self::CREDIT_TERMS_BIWEEKLY,
        self::CREDIT_TERMS_MONTHLY,
    ];

    public const CREDIT_STATUS_ACTIVE = 'active';
    public const CREDIT_STATUS_FROZEN = 'frozen';

    protected $fillable = [
        'tenant_id',
        'owner_type',
        'owner_id',
        'balance',
        'currency',
        'status',
        'credit_limit',
        'credit_terms',
        'credit_next_due_at',
        'credit_status',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'credit_limit' => 'decimal:2',
        'credit_next_due_at' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $wallet) {
            if (empty($wallet->status)) {
                $wallet->status = self::STATUS_ACTIVE;
            }

            if (empty($wallet->currency)) {
                $wallet->currency = 'INR';
            }

            if ($wallet->balance === null) {
                $wallet->balance = '0.00';
            }

            if ($wallet->credit_limit === null) {
                $wallet->credit_limit = '0.00';
            }

            if (empty($wallet->credit_terms)) {
                $wallet->credit_terms = self::CREDIT_TERMS_NONE;
            }

            if (empty($wallet->credit_status)) {
                $wallet->credit_status = self::CREDIT_STATUS_ACTIVE;
            }
        });
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(WalletLedgerEntry::class);
    }

    public function creditAuditLogs(): HasMany
    {
        return $this->hasMany(WalletCreditAuditLog::class)->orderByDesc('created_at');
    }

    /**
     * How far a debit may push balance below zero right now — 0 for a
     * wallet with no credit granted or whose credit is frozen, otherwise
     * -credit_limit. WalletService::postEntry() is the only caller; see
     * its docblock for why this replaces a flat "never go negative" rule.
     */
    public function debitFloor(): \Brick\Math\BigDecimal
    {
        if ($this->credit_status !== self::CREDIT_STATUS_ACTIVE || \Brick\Math\BigDecimal::of((string) $this->credit_limit)->isZero()) {
            return self::zeroScaled();
        }

        return \Brick\Math\BigDecimal::of((string) $this->credit_limit)->negated();
    }

    /** BigDecimal::zero() is scale-0 ("0"); every money value here is scale-2 ("0.00") — this keeps them consistent. */
    protected static function zeroScaled(): \Brick\Math\BigDecimal
    {
        return \Brick\Math\BigDecimal::zero()->toScale(2, \Brick\Math\RoundingMode::HALF_UP);
    }

    /** How much of the granted credit_limit is currently drawn (0 if balance isn't negative). */
    public function creditUsed(): string
    {
        $balance = \Brick\Math\BigDecimal::of((string) $this->balance);

        return $balance->isNegative()
            ? (string) $balance->negated()->toScale(2, \Brick\Math\RoundingMode::HALF_UP)
            : '0.00';
    }

    /** Remaining undrawn credit (0 if credit is frozen or exhausted). */
    public function creditAvailable(): string
    {
        if ($this->credit_status !== self::CREDIT_STATUS_ACTIVE) {
            return '0.00';
        }

        $remaining = \Brick\Math\BigDecimal::of((string) $this->credit_limit)
            ->minus(\Brick\Math\BigDecimal::of($this->creditUsed()));

        return (string) ($remaining->isNegative() ? self::zeroScaled() : $remaining->toScale(2, \Brick\Math\RoundingMode::HALF_UP));
    }

    /**
     * Total the owner could put toward a purchase right now: cash balance
     * plus whatever credit remains — exactly "balance minus debitFloor()",
     * i.e. how much further a debit could take balance before hitting the
     * floor. This is what checkout's wallet-split UI should offer, not the
     * raw balance (see WalletResource).
     */
    public function availableToSpend(): string
    {
        $spendable = \Brick\Math\BigDecimal::of((string) $this->balance)->minus($this->debitFloor());

        return (string) ($spendable->isNegative() ? self::zeroScaled() : $spendable->toScale(2, \Brick\Math\RoundingMode::HALF_UP));
    }

    /**
     * Resolves the polymorphic owner (a Tenant or a User) per owner_type.
     */
    public function owner(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'owner_type', 'owner_id')
            ->morphWith([
                self::OWNER_TENANT => \App\Domains\Tenancy\Models\Tenant::class,
                self::OWNER_USER => User::class,
            ]);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
