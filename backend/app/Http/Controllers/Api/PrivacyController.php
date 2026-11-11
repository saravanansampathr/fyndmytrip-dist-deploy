<?php

namespace App\Http\Controllers\Api;

use App\Domains\Privacy\Services\PrivacyService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/** A signed-in person's own privacy controls (My account → Privacy). */
class PrivacyController extends Controller
{
    public function __construct(protected PrivacyService $privacy)
    {
    }

    public function show(Request $request)
    {
        $u = $request->user();

        return response()->json(['data' => [
            'terms_accepted_at' => optional($u->terms_accepted_at)->toIso8601String(),
            'marketing_consent' => (bool) $u->marketing_consent,
            'can_delete' => $this->privacy->blocker($u) === null,
            'delete_blocked_reason' => $this->privacy->blocker($u),
        ]]);
    }

    public function consent(Request $request)
    {
        $d = $request->validate(['marketing' => ['required', 'boolean']]);
        $this->privacy->setMarketing($request->user(), (bool) $d['marketing'], $request);

        return response()->json(['message' => $d['marketing'] ? 'You will get offers and deals from us.' : 'You will no longer get offers. Booking messages still reach you.']);
    }

    public function export(Request $request)
    {
        $u = $request->user();
        $this->privacy->log($u, 'data_export', true, $request);

        return response()->json($this->privacy->export($u), 200, [
            'Content-Disposition' => 'attachment; filename="my-data-'.now()->format('Y-m-d').'.json"',
            'Cache-Control' => 'private, no-store',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function destroy(Request $request)
    {
        $u = $request->user();
        $request->validate(['password' => ['required', 'string'], 'confirm' => ['required', 'in:DELETE']], ['confirm.in' => 'Type DELETE to confirm.']);
        abort_unless(Hash::check($request->input('password'), $u->password_hash), 422, 'That password is not right.');
        if ($reason = $this->privacy->blocker($u)) {
            abort(422, $reason);
        }
        $this->privacy->erase($u, $request);

        return response()->json(['message' => 'Your account has been deleted. Booking and invoice records are kept for 8 years as tax law requires, without your name or contact details on your login.']);
    }
}
