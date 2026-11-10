<?php

namespace App\Domains\Wallet\Services;

use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Models\WalletLedgerEntry;
use App\Models\User;
use App\Support\TenantMailBranding;
use Illuminate\Support\Facades\Mail;

/**
 * "All transactions and change need to drop email to respective users, Not
 * to the margins or markup kept to them" — every wallet ledger entry and
 * every credit change emails the wallet's own owner (never platform staff),
 * and never mentions markup/commission/net-supplier-cost: WalletLedgerEntry
 * and the credit fields on Wallet simply don't carry that data, so there is
 * nothing margin-shaped for this class to leak by construction.
 *
 * Deliberately mail()-not-queue()d, same as AuthController's password reset
 * code — this environment has no queue worker running on cPanel shared
 * hosting, only the sync driver. Every call is wrapped so a mail hiccup
 * (SMTP timeout, etc.) can never fail the booking/payment/credit-change it
 * is reporting on; failures are reported to the log instead.
 *
 * Only OWNER_USER wallets resolve to a single person's inbox. An
 * OWNER_TENANT wallet (a reseller's commission-payout wallet, credited by
 * CommissionService) has no one individual owner, so it's silently skipped
 * here rather than guessed at.
 */
class WalletNotificationService
{
    public function notifyLedgerEntry(WalletLedgerEntry $entry): void
    {
        $wallet = $entry->wallet;
        $user = $this->resolveOwnerUser($wallet);

        if (! $user) {
            return;
        }

        $verb = $entry->entry_type === WalletLedgerEntry::CREDIT ? 'credited' : 'debited';
        $label = self::REFERENCE_LABELS[$entry->reference_type] ?? ucfirst(str_replace('_', ' ', $entry->reference_type));
        $branding = TenantMailBranding::resolveForTenantId($wallet->tenant_id);

        $this->send(
            $user->email,
            "Wallet {$verb}: ₹{$entry->amount}",
            "Your {$branding['brand_name']} wallet was {$verb} ₹{$entry->amount} ({$label})."
                .($entry->remarks ? "\nRemarks: {$entry->remarks}" : '')
                ."\n\nNew balance: ₹{$entry->running_balance}"
                ."\n\nIf this wasn't you, please contact support immediately.",
            $branding,
        );
    }

    public function notifyCreditChange(Wallet $wallet, array $old, ?string $remarks = null): void
    {
        $user = $this->resolveOwnerUser($wallet);

        if (! $user) {
            return;
        }

        $branding = TenantMailBranding::resolveForTenantId($wallet->tenant_id);
        $lines = ["Your {$branding['brand_name']} credit terms have been updated:"];

        if ($old['credit_limit'] !== (string) $wallet->credit_limit) {
            $lines[] = "Credit limit: ₹{$old['credit_limit']} → ₹{$wallet->credit_limit}";
        }
        if ($old['credit_status'] !== $wallet->credit_status) {
            $lines[] = 'Credit status: '.ucfirst($old['credit_status']).' → '.ucfirst($wallet->credit_status);
        }
        if ($old['credit_terms'] !== $wallet->credit_terms) {
            $lines[] = 'Payment terms: '.ucfirst($old['credit_terms']).' → '.ucfirst($wallet->credit_terms);
        }
        $newDueAt = $wallet->credit_next_due_at?->toDateString();
        if ($old['credit_next_due_at'] !== $newDueAt) {
            $lines[] = 'Next due date: '.($old['credit_next_due_at'] ?: '—').' → '.($wallet->credit_next_due_at?->format('d M Y') ?? '—');
        }

        if (count($lines) === 1) {
            return; // Nothing actually changed — no-op update, no email.
        }

        if ($remarks) {
            $lines[] = "\nNote from our team: {$remarks}";
        }

        $this->send($user->email, 'Your credit terms have been updated', implode("\n", $lines), $branding);
    }

    /** @return array{credit_limit: string, credit_terms: string, credit_next_due_at: string|null, credit_status: string} */
    public function snapshotCredit(Wallet $wallet): array
    {
        return [
            'credit_limit' => (string) $wallet->credit_limit,
            'credit_terms' => $wallet->credit_terms,
            'credit_next_due_at' => $wallet->credit_next_due_at?->toDateString(),
            'credit_status' => $wallet->credit_status,
        ];
    }

    protected function resolveOwnerUser(Wallet $wallet): ?User
    {
        $owner = $wallet->owner;

        return $owner instanceof User ? $owner : null;
    }

    /**
     * @param array{from_name: string, from_address: ?string, support_email: ?string} $branding
     */
    protected function send(string $to, string $subject, string $body, array $branding): void
    {
        try {
            Mail::raw($body, function ($message) use ($to, $subject, $branding) {
                $message->to($to)->subject($subject);

                if ($branding['from_address']) {
                    $message->from($branding['from_address'], $branding['from_name']);
                }
                if ($branding['support_email']) {
                    $message->replyTo($branding['support_email']);
                }
            });
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected const REFERENCE_LABELS = [
        'topup' => 'wallet top-up',
        'booking_payment' => 'booking payment',
        'refund' => 'refund',
        'cashback' => 'cashback',
        'commission_payout' => 'commission payout',
        'adjustment' => 'manual adjustment',
        'offline_payment' => 'offline payment approved',
        'group_deposit' => 'group booking deposit',
        'group_balance' => 'group booking balance payment',
        'hotel_group_deposit' => 'hotel group booking deposit',
        'hotel_group_balance' => 'hotel group booking balance payment',
    ];
}
