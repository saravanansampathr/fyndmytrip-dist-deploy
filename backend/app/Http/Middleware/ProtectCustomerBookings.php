<?php

namespace App\Http\Middleware;

use App\Domains\Booking\Models\Booking;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A customer (including a guest checkout session) may only open or act on
 * bookings they made themselves. The flight/hotel/bus/cab/cruise/activity/
 * insurance booking pages (view, extras, coupon, confirm, pay, ticket) used
 * to load any booking by its number, so any signed-in customer could see
 * another traveller's booking, names and documents by changing the id.
 *
 * Agents, partner admins and platform staff keep their existing access.
 */
class ProtectCustomerBookings
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        if (! $route || ! $route->hasParameter('booking')) {
            return $next($request);
        }

        $user = $request->user('sanctum');
        if (! $user || $user->role !== User::ROLE_CUSTOMER) {
            return $next($request);
        }

        $booking = $route->parameter('booking');
        if (! $booking instanceof Booking) {
            $booking = is_numeric($booking) ? Booking::query()->find($booking) : null;
        }

        if ($booking && (int) $booking->created_by_user_id !== (int) $user->id) {
            return response()->json(['message' => 'That booking is not under your account.'], 403);
        }

        return $next($request);
    }
}
