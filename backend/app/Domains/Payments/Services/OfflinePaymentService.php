<?php

namespace App\Domains\Payments\Services;

use App\Domains\Payments\Models\OfflinePaymentRequest;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Models\WalletLedgerEntry;
use App\Domains\Wallet\Services\WalletService;
use App\Models\User;
use App\Support\TenantMailBranding;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Offline payment capture (Platform Roadmap): a partner/agent transferred
 * funds outside the platform (NEFT/RTGS/IMPS/UPI/cash deposit/netbanking/
 * other) and is telling us so, attaching proof, for staff to verify against
 * their own bank statement before it becomes real wallet balance. See
 * OfflinePaymentRequest's docblock and OfflinePaymentController.
 */
class OfflinePaymentService
{
    public function __construct(protected WalletService $walletService) {}

    /**
     * @param array<int, UploadedFile> $attachments
     */
    public function submit(User $submittedBy, Wallet $wallet, array $data, array $attachments = []): OfflinePaymentRequest
    {
        return DB::transaction(function () use ($submittedBy, $wallet, $data, $attachments) {
            $request = OfflinePaymentRequest::query()->create([
                'tenant_id' => $wallet->tenant_id,
                'wallet_id' => $wallet->id,
                'requested_by_user_id' => $submittedBy->id,
                'payment_mode' => $data['payment_mode'],
                'amount' => $data['amount'],
                'transaction_date' => $data['transaction_date'],
                'transaction_ref_number' => $data['transaction_ref_number'],
                'remarks' => $data['remarks'] ?? null,
            ]);

            foreach ($attachments as $file) {
                $this->storeAttachment($request, $file);
            }

            return $request->load('attachments');
        });
    }

    protected function storeAttachment(OfflinePaymentRequest $request, UploadedFile $file): void
    {
        // Private disk — never publicly served directly; see
        // OfflinePaymentController::downloadAttachment() for the only
        // access path, gated by ownership/review permission.
        $path = $file->storeAs(
            "offline-payments/{$request->id}",
            Str::uuid().'.'.$file->getClientOriginalExtension(),
            'local',
        );

        $request->attachments()->create([
            'disk_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
        ]);
    }

    public function approve(OfflinePaymentRequest $request, User $reviewer): OfflinePaymentRequest
    {
        abort_unless($request->isPending(), 422, 'This request has already been reviewed.');

        return DB::transaction(function () use ($request, $reviewer) {
            $wallet = Wallet::withoutTenantScope()->lockForUpdate()->findOrFail($request->wallet_id);

            $entry = $this->walletService->credit(
                $wallet,
                (string) $request->amount,
                WalletLedgerEntry::REF_OFFLINE_PAYMENT,
                $request->id,
                "Offline payment verified — {$request->payment_mode} ref {$request->transaction_ref_number}",
                $reviewer->id,
            );

            $request->update([
                'status' => OfflinePaymentRequest::STATUS_APPROVED,
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'wallet_ledger_entry_id' => $entry->id,
            ]);

            return $request;
        });
    }

    public function reject(OfflinePaymentRequest $request, User $reviewer, string $reason): OfflinePaymentRequest
    {
        abort_unless($request->isPending(), 422, 'This request has already been reviewed.');

        $request->update([
            'status' => OfflinePaymentRequest::STATUS_REJECTED,
            'reviewed_by_user_id' => $reviewer->id,
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ]);

        $this->notifyRejection($request);

        return $request;
    }

    /**
     * Rejection moves no money — WalletService's own notifier never fires
     * for it, so this is the one offline-payment path that has to send its
     * own email (approval rides WalletService::credit()'s existing hook).
     */
    protected function notifyRejection(OfflinePaymentRequest $request): void
    {
        $user = $request->requestedBy;

        if (! $user) {
            return;
        }

        $branding = TenantMailBranding::resolveForTenantId($request->tenant_id);

        try {
            \Illuminate\Support\Facades\Mail::raw(
                "Your offline payment claim of ₹{$request->amount} ({$request->payment_mode}, ref {$request->transaction_ref_number}) could not be verified and was not credited to your wallet.\n\nReason: {$request->rejection_reason}\n\nIf you believe this is a mistake, please contact support with your transaction proof.",
                function ($message) use ($user, $branding) {
                    $message->to($user->email)->subject('Your offline payment request was not approved');

                    if ($branding['from_address']) {
                        $message->from($branding['from_address'], $branding['from_name']);
                    }
                    if ($branding['support_email']) {
                        $message->replyTo($branding['support_email']);
                    }
                },
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
