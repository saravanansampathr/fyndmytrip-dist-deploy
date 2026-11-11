<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A guest checkout token (see GuestCheckoutService) is not a login. It may
 * only be used on the pages needed to finish and view the guest's own
 * booking. Any other signed-in page answers 401, which also makes the
 * portal and the website drop the guest session.
 *
 * Public pages (search, package details, ...) are left alone: they work
 * without signing in anyway.
 */
class RestrictGuestCheckoutSessions
{
    /** [methods, path pattern relative to /api]. */
    protected const ALLOWED = [
        [['GET', 'POST'], '#^auth/(me|logout)$#'],
        [['GET'], '#^wallet$#'],
        [['GET'], '#^flights/ancillary-catalog$#'],
        [['GET', 'POST'], '#^coupons/(active|validate)$#'],
        [['GET', 'POST', 'DELETE'], '#^(flights|hotels|bus)/bookings/\d+(/[a-z.\-]+)?$#'],
        [['POST'], '#^(holiday|visa)-bookings/\d+/(confirm|pay)$#'],
        [['POST'], '#^payments/checkout$#'],
        [['GET'], '#^payments/checkout/[^/]+/status$#'],
        [['GET'], '#^bookings/\d+/invoice\.pdf$#'],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->bearerToken()) {
            return $next($request);
        }

        $route = $request->route();
        $needsLogin = $route && collect($route->gatherMiddleware())
            ->contains(fn ($m) => is_string($m) && str_starts_with($m, 'auth'));
        if (! $needsLogin) {
            return $next($request);
        }

        $user = $request->user('sanctum');
        if (! $user || ! $user->is_guest) {
            return $next($request);
        }

        $path = preg_replace('#^api/#', '', $request->path());
        foreach (self::ALLOWED as [$methods, $pattern]) {
            if (in_array($request->method(), $methods, true) && preg_match($pattern, $path)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'You are booking as a guest. Please sign in or create an account to continue.',
            'guest_session' => true,
        ], 401);
    }
}
