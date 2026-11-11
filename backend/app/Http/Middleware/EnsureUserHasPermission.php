<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware: ->middleware('permission:booking.cancel')
 */
class EnsureUserHasPermission
{
    public function handle(Request $request, Closure $next, string $permissionKey): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasPermission($permissionKey)) {
            abort(403, "Missing required permission: {$permissionKey}");
        }

        return $next($request);
    }
}
