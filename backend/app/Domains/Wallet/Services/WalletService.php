<?php

namespace App\Domains\Wallet\Services;

use App\Domains\Wallet\Exceptions\InsufficientBalanceException;
use App\Domains\Wallet\Exceptions\WalletNotActiveException;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Models\WalletLedgerEntry;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * The only code path allowed to write to wallets.balance or insert into
 * wallet_ledger_entries — see Database Design Document §5. Every operation:
 *
 *   1. Row-locks the wallet (SELECT ... FOR UPDATE) inside a DB transaction,
 *      so two concurrent debits (e.g. a double-submitted booking payment
 *      and a refund racing a top-up) can never both read the same stale
 *      balance and overdraw the wallet.
 *   2. Uses brick/math BigDecimal rather than float arithmetic, since PHP
 *      floats cannot represent money exactly and this environment has no
 *      bcmath extension available.
 *   3. Appends an immutable ledger row recording the balance immediately
 *      after the entry, then updates the cached wallets.balance column to
 *      match — the ledger is the source of truth; the column is a cache.
 */
class WalletService
{
    public function __construct(protected WalletNotificationService $notifier) {}

    /**
     * @throws WalletNotActiveException
     */
    public function credit(
        Wallet $wallet,
        string $amount,
        string $referenceType,
        ?int $referenceId = null,
        ?string $remarks = null,
        ?int $createdBy = null,
    ): WalletLedgerEntry {
        return $this->postEntry($wallet, WalletLedgerEntry::CREDIT, $amount, $referenceType, $referenceId, $remarks, $createdBy);
    }

    /**
     * @throws WalletNotActiveException
     * @throws InsufficientBalanceException
     */
    public function debit(
        Wallet $wallet,
        string $amount,
        string $referenceType,
        ?int $referenceId = null,
        ?string $remarks = null,
        ?int $createdBy = null,
    ): WalletLedgerEntry {
        return $this->postEntry($wallet, WalletLedgerEntry::DEBIT, $amount, $referenceType, $referenceId, $remarks, $createdBy);
    }

    /**
     * @throws WalletNotActiveException
     * @throws InsufficientBalanceException
     */
    protected function postEntry(
        Wallet $wallet,
        string $entryType,
        string $amount,
        string $referenceType,
        ?int $referenceId,
        ?string $remarks,
        ?int $createdBy,
    ): WalletLedgerEntry {
        $amountDecimal = BigDecimal::of($amount);

        if ($amountDecimal->isLessThanOrEqualTo(0)) {
            throw new \InvalidArgumentException('Wallet ledger amount must be a positive value; sign is implied by entry_type.');
        }

        $entry = DB::transaction(function () use ($wallet, $entryType, $amountDecimal, $referenceType, $referenceId, $remarks, $createdBy) {
            /** @var Wallet $locked */
            $locked = Wallet::withoutTenantScope()->lockForUpdate()->findOrFail($wallet->id);

            if (! $locked->isActive()) {
                throw WalletNotActiveException::forWallet($locked->id, $locked->status);
            }

            $currentBalance = BigDecimal::of($locked->balance);

            $newBalance = $entryType === WalletLedgerEntry::CREDIT
                ? $currentBalance->plus($amountDecimal)
                : $currentBalance->minus($amountDecimal);

            // A debit may push balance negative down to -credit_limit (0 for
            // a wallet with no credit granted, or whose credit is frozen —
            // see Wallet::debitFloor()) rather than a flat "never negative"
            // rule; a credit entry never needs the floor at all.
            $floor = $entryType === WalletLedgerEntry::DEBIT ? $locked->debitFloor() : BigDecimal::zero();

            if ($newBalance->isLessThan($floor)) {
                throw InsufficientBalanceException::forWallet($locked->id, (string) $amountDecimal, (string) $currentBalance);
            }

            $newBalanceScaled = $newBalance->toScale(2, RoundingMode::HALF_UP);

            $entry = $locked->ledgerEntries()->create([
                'entry_type' => $entryType,
                'amount' => (string) $amountDecimal->toScale(2, RoundingMode::HALF_UP),
                'running_balance' => (string) $newBalanceScaled,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'remarks' => $remarks,
                'created_by' => $createdBy,
                'created_at' => now(),
            ]);

            $locked->update(['balance' => (string) $newBalanceScaled]);

            // Set explicitly rather than left to lazy-load: $locked was
            // fetched via withoutTenantScope() (WalletService may act
            // across tenants, e.g. staff approving another tenant's offline
            // payment), but WalletLedgerEntry::wallet() is a normal scoped
            // belongsTo — a lazy load here could silently return null under
            // a different request's TenantContext and skip the email below.
            $entry->setRelation('wallet', $locked);

            return $entry;
        });

        // Outside the DB transaction and best-effort (see
        // WalletNotificationService's docblock) — a slow/failed mail send
        // must never roll back or delay the money movement it's reporting.
        $this->notifier->notifyLedgerEntry($entry);

        return $entry;
    }

    /**
     * Recomputes wallets.balance by summing wallet_ledger_entries from
     * scratch — used by the periodic reconciliation job referenced in the
     * Payments module specification's reconciliation & reporting section.
     */
    public function reconcileBalance(Wallet $wallet): string
    {
        return DB::transaction(function () use ($wallet) {
            /** @var Wallet $locked */
            $locked = Wallet::withoutTenantScope()->lockForUpdate()->findOrFail($wallet->id);

            $credits = $locked->ledgerEntries()->where('entry_type', WalletLedgerEntry::CREDIT)->sum('amount');
            $debits = $locked->ledgerEntries()->where('entry_type', WalletLedgerEntry::DEBIT)->sum('amount');

            $balance = BigDecimal::of((string) $credits)->minus(BigDecimal::of((string) $debits))->toScale(2, RoundingMode::HALF_UP);

            $locked->update(['balance' => (string) $balance]);

            return (string) $balance;
        });
    }
}
