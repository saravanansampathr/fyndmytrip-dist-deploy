<?php

namespace App\Http\Controllers\Api;

use App\Domains\Marketing\Models\NewsletterSubscriber;
use App\Domains\Tenancy\Services\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Storefront footer's "Subscribe for deals" box — public, no auth:sanctum
 * (see routes/api.php), same tenant-resolution pattern as the guest
 * checkout endpoints. A repeat subscribe with the same email is a no-op
 * success (updateOrCreate re-stamps subscribed_at and clears
 * unsubscribed_at) rather than a 422 — a visitor re-submitting the same
 * email shouldn't see an error.
 */
class NewsletterController extends Controller
{
    public function subscribe(Request $request)
    {
        $tenant = $request->attributes->get('resolved_tenant') ?? app(TenantContext::class)->get();

        $data = $request->validate([
            'email' => ['required', 'email', 'max:191'],
        ]);

        NewsletterSubscriber::query()->updateOrCreate(
            ['tenant_id' => $tenant?->id, 'email' => $data['email']],
            ['subscribed_at' => now(), 'unsubscribed_at' => null],
        );

        return response()->json(['subscribed' => true]);
    }
}
