<?php

namespace Tests\Feature;

use App\Domains\Wallet\Exceptions\InsufficientBalanceException;
use App\Domains\Wallet\Models\WalletLedgerEntry;
use App\Domains\Wallet\Services\WalletService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class WalletTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_credit_then_debit_produces_the_correct_running_balance(): void
    {
        $master = $this->createMasterTenant();
        $user = $this->createUserForTenant($master, User::ROLE_CUSTOMER);
        $wallet = $this->walletFor($user);

        $service = app(WalletService::class);

        $service->credit($wallet, '1000.00', WalletLedgerEntry::REF_TOPUP);
        $entry = $service->debit($wallet, '350.50', WalletLedgerEntry::REF_BOOKING_PAYMENT);

        $this->assertSame('649.50', $entry->running_balance);
        $this->assertSame('649.50', $wallet->fresh()->balance);
    }

    public function test_debit_beyond_balance_throws_and_leaves_balance_unchanged(): void
    {
        $master = $this->createMasterTenant();
        $user = $this->createUserForTenant($master, User::ROLE_CUSTOMER);
        $wallet = $this->walletFor($user);

        $service = app(WalletService::class);
        $service->credit($wallet, '100.00', WalletLedgerEntry::REF_TOPUP);

        $this->expectException(InsufficientBalanceException::class);

        try {
            $service->debit($wallet, '500.00', WalletLedgerEntry::REF_BOOKING_PAYMENT);
        } finally {
            $this->assertSame('100.00', $wallet->fresh()->balance, 'A failed debit must not change the balance.');
        }
    }

    public function test_ledger_is_append_only_and_reconciliation_matches_running_balance(): void
    {
        $master = $this->createMasterTenant();
        $user = $this->createUserForTenant($master, User::ROLE_CUSTOMER);
        $wallet = $this->walletFor($user);

        $service = app(WalletService::class);
        $service->credit($wallet, '200.00', WalletLedgerEntry::REF_TOPUP);
        $service->credit($wallet, '300.00', WalletLedgerEntry::REF_CASHBACK);
        $service->debit($wallet, '150.00', WalletLedgerEntry::REF_BOOKING_PAYMENT);

        $this->assertSame('350.00', $wallet->fresh()->balance);
        $this->assertSame('350.00', $service->reconcileBalance($wallet));
        $this->assertSame(3, $wallet->ledgerEntries()->count());
    }
}
