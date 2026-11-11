<?php

namespace App\Domains\Loyalty\Services;

use App\Domains\Booking\Models\Booking;
use App\Domains\Loyalty\Models\LoyaltyPoint;
use App\Domains\Settings\Services\SettingsService;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Services\WalletService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Loyalty points and referrals for customers (Admin settings → Loyalty).
 * Points are earned when a booking is completed, taken back if it is
 * cancelled or refunded, used oldest first, and turned into wallet money.
 */
class LoyaltyService
{
    public const REF_LOYALTY = 'loyalty';

    public function settings(): array
    {
        return app(SettingsService::class)->get('loyalty');
    }

    public function balance(User $user): int
    {
        return (int) LoyaltyPoint::query()->where('user_id', $user->id)->sum('points');
    }

    public function referralCode(User $user): string
    {
        if (! $user->referral_code) {
            do {
                $code = strtoupper(Str::random(8));
            } while (User::query()->where('referral_code', $code)->exists());
            $user->forceFill(['referral_code' => $code])->save();
        }

        return $user->referral_code;
    }

    public function earn(Booking $booking): ?LoyaltyPoint
    {
        $c = $this->settings();
        $user = $booking->createdBy;
        if (empty($c['enabled']) || ! $user || $user->role !== User::ROLE_CUSTOMER || $user->is_guest || $user->erased_at) {
            return null;
        }
        if (LoyaltyPoint::query()->where('booking_id', $booking->id)->where('reason', 'earn')->exists()) {
            return null;
        }
        $points = (int) floor((float) $booking->total_amount / 100 * (float) ($c['points_per_100'] ?? 1));
        $row = $points > 0 ? LoyaltyPoint::query()->create([
            'user_id' => $user->id, 'tenant_id' => $booking->tenant_id, 'points' => $points, 'reason' => 'earn', 'booking_id' => $booking->id,
            'note' => 'Booking '.$booking->booking_code, 'expires_at' => now()->addMonths((int) ($c['expiry_months'] ?? 12)),
        ]) : null;
        $this->rewardReferral($user, $booking, $c);

        return $row;
    }

    /** A cancelled or refunded booking takes back its points (and any referral bonus it triggered). */
    public function reverse(Booking $booking): void
    {
        LoyaltyPoint::query()->where('booking_id', $booking->id)->whereIn('reason', ['earn', 'referral'])->get()
            ->groupBy('user_id')->each(function ($rows, $uid) use ($booking) {
                if (LoyaltyPoint::query()->where('booking_id', $booking->id)->where('user_id', $uid)->where('reason', 'reverse')->exists()) {
                    return;
                }
                LoyaltyPoint::query()->create([
                    'user_id' => $uid, 'tenant_id' => $rows->first()->tenant_id, 'points' => -$rows->sum('points'), 'reason' => 'reverse',
                    'booking_id' => $booking->id, 'note' => 'Booking '.$booking->booking_code.' cancelled',
                ]);
            });
    }

    /** First completed booking of a referred customer rewards both people once. */
    protected function rewardReferral(User $user, Booking $booking, array $c): void
    {
        $r = $c['referral'] ?? [];
        if (empty($r['enabled']) || ! $user->referred_by_user_id || (float) $booking->total_amount < (float) ($r['min_booking_amount'] ?? 0)) {
            return;
        }
        if (LoyaltyPoint::query()->where('user_id', $user->id)->where('reason', 'referral')->exists()) {
            return;
        }
        $referrer = User::query()->find($user->referred_by_user_id);
        $expires = now()->addMonths((int) ($c['expiry_months'] ?? 12));
        if ((int) ($r['referee_points'] ?? 0) > 0) {
            LoyaltyPoint::query()->create(['user_id' => $user->id, 'tenant_id' => $booking->tenant_id, 'points' => (int) $r['referee_points'], 'reason' => 'referral', 'booking_id' => $booking->id, 'note' => 'Welcome bonus for joining with a referral', 'expires_at' => $expires]);
        }
        if ($referrer && ! $referrer->erased_at && (int) ($r['referrer_points'] ?? 0) > 0) {
            LoyaltyPoint::query()->create(['user_id' => $referrer->id, 'tenant_id' => $referrer->tenant_id, 'points' => (int) $r['referrer_points'], 'reason' => 'referral', 'booking_id' => $booking->id, 'note' => 'Your friend '.Str::of($user->name)->before(' ').' made their first booking', 'expires_at' => $expires]);
        }
    }

    /** Turn points into wallet money. Returns the amount credited. */
    public function redeem(User $user, int $points): string
    {
        $c = $this->settings();
        abort_if(empty($c['enabled']), 422, 'Loyalty points are not available right now.');
        abort_if($points < (int) ($c['min_redeem_points'] ?? 0), 422, 'You can use at least '.(int) $c['min_redeem_points'].' points at a time.');

        return DB::transaction(function () use ($user, $points, $c) {
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            abort_if($points > $this->balance($user), 422, 'You do not have that many points.');
            $amount = number_format(round($points * (float) ($c['point_value'] ?? 0.25), 2), 2, '.', '');
            $row = LoyaltyPoint::query()->create(['user_id' => $user->id, 'tenant_id' => $user->tenant_id, 'points' => -$points, 'reason' => 'redeem', 'note' => "Moved to wallet ({$amount})"]);
            $wallet = Wallet::query()->firstOrCreate(['tenant_id' => $user->tenant_id, 'owner_type' => Wallet::OWNER_USER, 'owner_id' => $user->id]);
            app(WalletService::class)->credit($wallet, $amount, self::REF_LOYALTY, $row->id, "{$points} loyalty points", $user->id);

            return $amount;
        });
    }

    /** Points earned more than expiry_months ago that were not used are removed (oldest used first). */
    public function expire(): int
    {
        $n = 0;
        $users = LoyaltyPoint::query()->where('points', '>', 0)->whereNotNull('expires_at')->where('expires_at', '<=', now())->distinct()->pluck('user_id');
        foreach ($users as $uid) {
            $expiredEarned = (int) LoyaltyPoint::query()->where('user_id', $uid)->where('points', '>', 0)->whereNotNull('expires_at')->where('expires_at', '<=', now())->sum('points');
            $used = -(int) LoyaltyPoint::query()->where('user_id', $uid)->where('points', '<', 0)->sum('points');
            $balance = (int) LoyaltyPoint::query()->where('user_id', $uid)->sum('points');
            $toExpire = min($balance, max(0, $expiredEarned - $used));
            if ($toExpire > 0) {
                LoyaltyPoint::query()->create(['user_id' => $uid, 'tenant_id' => (int) User::query()->whereKey($uid)->value('tenant_id'), 'points' => -$toExpire, 'reason' => 'expire', 'note' => 'Points older than the expiry period']);
                $n++;
            }
        }

        return $n;
    }
}
