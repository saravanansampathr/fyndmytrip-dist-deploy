<?php

namespace App\Http\Controllers\Api;

use App\Domains\Loyalty\Models\LoyaltyPoint;
use App\Domains\Loyalty\Services\LoyaltyService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

/** My account → Rewards: points balance, history, referral link, use points. */
class LoyaltyController extends Controller
{
    public function __construct(protected LoyaltyService $loyalty)
    {
    }

    public function show(Request $request)
    {
        $u = $request->user();
        $c = $this->loyalty->settings();
        $enabled = ! empty($c['enabled']) && $u->role === User::ROLE_CUSTOMER;
        $balance = $this->loyalty->balance($u);
        $site = rtrim((string) ($u->tenant?->custom_domain ? 'https://'.$u->tenant->custom_domain : config('app.frontend_url', 'https://demo.fyndmytrip.in')), '/');
        $refOn = $enabled && ! empty($c['referral']['enabled']);

        return response()->json(['data' => [
            'enabled' => $enabled,
            'points' => $balance,
            'worth' => round($balance * (float) ($c['point_value'] ?? 0), 2),
            'point_value' => (float) ($c['point_value'] ?? 0),
            'points_per_100' => (float) ($c['points_per_100'] ?? 0),
            'min_redeem_points' => (int) ($c['min_redeem_points'] ?? 0),
            'expiry_months' => (int) ($c['expiry_months'] ?? 12),
            'referral' => $refOn ? [
                'code' => $this->loyalty->referralCode($u),
                'link' => $site.'/#/register?ref='.$this->loyalty->referralCode($u),
                'you_get' => (int) ($c['referral']['referrer_points'] ?? 0),
                'friend_gets' => (int) ($c['referral']['referee_points'] ?? 0),
                'min_booking_amount' => (float) ($c['referral']['min_booking_amount'] ?? 0),
                'friends_joined' => User::query()->where('referred_by_user_id', $u->id)->count(),
            ] : null,
            'history' => LoyaltyPoint::query()->where('user_id', $u->id)->orderByDesc('id')->limit(100)->get(['points', 'reason', 'note', 'expires_at', 'created_at']),
        ]]);
    }

    public function redeem(Request $request)
    {
        $d = $request->validate(['points' => ['required', 'integer', 'min:1']]);
        abort_unless($request->user()->role === User::ROLE_CUSTOMER, 403);
        $amount = $this->loyalty->redeem($request->user(), (int) $d['points']);

        return response()->json(['data' => ['credited' => $amount, 'points' => $this->loyalty->balance($request->user())], 'message' => "{$amount} added to your wallet."]);
    }
}
