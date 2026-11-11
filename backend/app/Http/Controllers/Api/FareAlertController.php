<?php

namespace App\Http\Controllers\Api;

use App\Domains\Loyalty\Models\FareAlert;
use App\Domains\Settings\Services\SettingsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** Price-drop alerts on a flight route and date. */
class FareAlertController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(['data' => FareAlert::query()->where('user_id', $request->user()->id)->where('is_active', true)->orderBy('depart_date')->get()
            ->map(fn (FareAlert $a) => $a->only(['id', 'origin', 'destination', 'depart_date', 'adults', 'cabin_class', 'target_price', 'last_price', 'currency', 'last_checked_at', 'notified_at']))]);
    }

    public function store(Request $request)
    {
        $s = app(SettingsService::class)->get('fare_alerts');
        abort_if(empty($s['enabled']), 422, 'Fare alerts are not available right now.');
        $u = $request->user();
        $d = $request->validate([
            'origin' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'destination' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/', 'different:origin'],
            'depart_date' => ['required', 'date', 'after_or_equal:today'],
            'cabin_class' => ['nullable', 'in:economy,premium_economy,business,first'],
            'target_price' => ['nullable', 'numeric', 'min:1'],
            'current_price' => ['nullable', 'numeric', 'min:1'],
        ]);
        abort_if(FareAlert::query()->where('user_id', $u->id)->where('is_active', true)->count() >= (int) ($s['max_per_user'] ?? 10), 422, 'You already have the most alerts allowed. Remove one first.');
        $alert = FareAlert::query()->updateOrCreate(
            ['user_id' => $u->id, 'origin' => strtoupper($d['origin']), 'destination' => strtoupper($d['destination']), 'depart_date' => $d['depart_date'], 'is_active' => true],
            ['tenant_id' => $u->tenant_id, 'email' => $u->email, 'cabin_class' => $d['cabin_class'] ?? 'economy', 'target_price' => $d['target_price'] ?? null, 'last_price' => $d['current_price'] ?? null, 'currency' => 'INR'],
        );

        return response()->json(['data' => $alert->only(['id', 'origin', 'destination', 'depart_date', 'target_price', 'last_price']), 'message' => 'We will email you when the fare drops.'], 201);
    }

    public function destroy(Request $request, int $id)
    {
        FareAlert::query()->where('user_id', $request->user()->id)->whereKey($id)->update(['is_active' => false]);

        return response()->json(['message' => 'Alert removed.']);
    }

    /** One-click stop link from the email (no sign-in). */
    public function stop(string $token)
    {
        $n = FareAlert::query()->where('token', $token)->update(['is_active' => false]);

        return response('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Fare alert</title><body style="font:16px system-ui;padding:40px;text-align:center"><h1 style="font-size:20px">'.($n ? 'Fare alert stopped' : 'This alert was already stopped').'</h1><p>You will not get more emails for this route.</p></body>')
            ->header('Content-Type', 'text/html; charset=utf-8');
    }
}
