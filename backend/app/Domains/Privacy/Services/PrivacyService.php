<?php

namespace App\Domains\Privacy\Services;

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\BookingPassenger;
use App\Domains\Marketing\Models\NewsletterSubscriber;
use App\Domains\Privacy\Models\ConsentLog;
use App\Domains\Support\Models\SupportTicket;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Models\WalletLedgerEntry;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Consent records, "download my data" and "delete my account".
 *
 * Erasure removes the person's identity from their login (name, email,
 * phone, password, two-factor) and from passport / ID numbers on trips
 * already completed. Bookings, invoices and wallet history stay, because
 * Indian tax law requires keeping them for eight years; they no longer
 * point at a named person once the login is erased.
 */
class PrivacyService
{
    public function log(?User $user, string $purpose, bool $granted, ?Request $request = null, ?string $email = null): void
    {
        ConsentLog::query()->create([
            'user_id' => $user?->id, 'tenant_id' => $user?->tenant_id, 'email' => $email ?? $user?->email,
            'purpose' => $purpose, 'granted' => $granted,
            'ip' => $request?->ip(), 'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
        ]);
    }

    public function setMarketing(User $user, bool $granted, ?Request $request = null): void
    {
        $user->forceFill(['marketing_consent' => $granted])->save();
        $this->log($user, 'marketing', $granted, $request);
        $sub = NewsletterSubscriber::query()->where('tenant_id', $user->tenant_id)->where('email', $user->email)->first();
        if ($granted) {
            NewsletterSubscriber::query()->updateOrCreate(['tenant_id' => $user->tenant_id, 'email' => $user->email], ['subscribed_at' => $sub?->subscribed_at ?? now(), 'unsubscribed_at' => null]);
        } elseif ($sub && ! $sub->unsubscribed_at) {
            $sub->update(['unsubscribed_at' => now()]);
        }
    }

    public function export(User $user): array
    {
        $bookings = Booking::withoutTenantScope()->where('created_by_user_id', $user->id)->with('passengers')->orderBy('id')->get();
        $wallet = Wallet::query()->where('owner_type', Wallet::OWNER_USER)->where('owner_id', $user->id)->first();

        return [
            'generated_at' => now()->toIso8601String(),
            'profile' => [
                'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone, 'role' => $user->role,
                'created_at' => optional($user->created_at)->toIso8601String(), 'last_login_at' => optional($user->last_login_at)->toIso8601String(),
                'terms_accepted_at' => optional($user->terms_accepted_at)->toIso8601String(), 'marketing_consent' => (bool) $user->marketing_consent,
                'two_factor_enabled' => (bool) $user->two_factor_confirmed_at,
            ],
            'bookings' => $bookings->map(fn (Booking $b) => [
                'booking_code' => $b->booking_code, 'service' => $b->module, 'status' => $b->status, 'amount' => $b->total_amount, 'currency' => $b->currency,
                'travel_date' => optional($b->travel_date_start)->toDateString(), 'contact_email' => $b->contact_email, 'contact_phone' => $b->contact_phone,
                'booked_at' => optional($b->created_at)->toIso8601String(),
                'travellers' => $b->passengers->map(fn (BookingPassenger $p) => array_filter([
                    'name' => $p->name ?: trim($p->first_name.' '.$p->last_name), 'type' => $p->passenger_type, 'gender' => $p->gender,
                    'date_of_birth' => optional($p->dob)->toDateString(), 'age' => $p->age,
                    'passport_number' => $p->passport_number, 'passport_country' => $p->passport_country, 'id_number' => $p->document_number,
                ], fn ($v) => $v !== null && $v !== ''))->values(),
            ])->values(),
            'wallet' => $wallet ? [
                'balance' => $wallet->balance, 'currency' => $wallet->currency ?? 'INR',
                'entries' => WalletLedgerEntry::query()->where('wallet_id', $wallet->id)->orderBy('id')->get()
                    ->map(fn ($e) => ['date' => optional($e->created_at)->toIso8601String(), 'type' => $e->entry_type, 'amount' => $e->amount, 'balance_after' => $e->running_balance, 'note' => $e->remarks])->values(),
            ] : null,
            'support_tickets' => SupportTicket::query()->where('raised_by_user_id', $user->id)->orderBy('id')->get()
                ->map(fn ($t) => ['subject' => $t->subject, 'status' => $t->status, 'created_at' => optional($t->created_at)->toIso8601String()])->values(),
            'consent_history' => ConsentLog::query()->where('user_id', $user->id)->orderBy('id')->get(['purpose', 'granted', 'created_at'])
                ->map(fn ($c) => ['purpose' => $c->purpose, 'granted' => $c->granted, 'at' => optional($c->created_at)->toIso8601String()])->values(),
        ];
    }

    /** @return string|null a reason the account cannot be erased yet */
    public function blocker(User $user): ?string
    {
        if ($user->role !== User::ROLE_CUSTOMER) {
            return 'Business accounts hold records for your company. Ask your account administrator to close this login.';
        }
        $wallet = Wallet::query()->where('owner_type', Wallet::OWNER_USER)->where('owner_id', $user->id)->first();
        if ($wallet && (float) $wallet->balance > 0) {
            return 'Your wallet still has money in it. Use it or ask support to refund it before you delete your account.';
        }
        $open = Booking::withoutTenantScope()->where('created_by_user_id', $user->id)
            ->whereIn('status', [Booking::STATUS_HELD, Booking::STATUS_CONFIRMED, Booking::STATUS_TICKETED, Booking::STATUS_REFUND_PROCESSING])
            ->where(fn ($q) => $q->whereNull('travel_date_start')->orWhere('travel_date_start', '>=', now()->startOfDay()))->exists();

        return $open ? 'You have upcoming trips or a refund in progress. You can delete your account after they are complete.' : null;
    }

    public function erase(User $user, ?Request $request = null): void
    {
        DB::transaction(function () use ($user, $request) {
            $this->log($user, 'erasure', true, $request);
            NewsletterSubscriber::query()->where('tenant_id', $user->tenant_id)->where('email', $user->email)->delete();
            Booking::withoutTenantScope()->where('created_by_user_id', $user->id)->pluck('id')->chunk(200)->each(function ($ids) {
                BookingPassenger::query()->whereIn('booking_id', $ids)->get()->each(fn (BookingPassenger $p) => $p->forceFill([
                    'passport_number' => null, 'document_number' => null, 'passport_issue_date' => null, 'passport_expiry_date' => null,
                ])->saveQuietly());
            });
            $user->tokens()->delete();
            $user->forceFill([
                'name' => 'Deleted user', 'email' => 'erased-'.$user->id.'-'.Str::lower(Str::random(6)).'@invalid.local', 'phone' => null,
                'password_hash' => Hash::make(Str::random(40)), 'status' => User::STATUS_DEACTIVATED, 'marketing_consent' => false,
                'two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'erased_at' => now(),
            ])->save();
        });
    }
}
