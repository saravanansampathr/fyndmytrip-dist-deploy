<?php

use App\Http\Middleware\EnsureUserHasPermission;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ], append: [
            // Guest checkout tokens only work on their own booking's pages.
            \App\Http\Middleware\RestrictGuestCheckoutSessions::class,
            // Customers only ever see and pay for their own bookings.
            \App\Http\Middleware\ProtectCustomerBookings::class,
        ]);

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'permission' => EnsureUserHasPermission::class,
            'partner.api' => \App\Http\Middleware\AuthenticatePartnerApi::class,
        ]);

        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(function ($request, $e) {
            return $request->is('api/*') || $request->expectsJson();
        });

        // Email the alert list (Admin settings → Monitoring) about server
        // errors, at most once every 15 minutes for the same error.
        $exceptions->report(function (\Throwable $e) {
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                || $e instanceof \Illuminate\Validation\ValidationException
                || $e instanceof \Illuminate\Auth\AuthenticationException
                || $e instanceof \App\Domains\Payments\Exceptions\PaymentNotVerifiedException
                || app()->runningUnitTests()) {
                return;
            }
            try {
                $to = array_filter((array) app(\App\Domains\Settings\Services\SettingsService::class)->value('monitoring', 'alert_emails', []));
                $key = 'fyndmytrip:error-alert:'.md5(get_class($e).$e->getMessage());
                if (! $to || ! \Illuminate\Support\Facades\Cache::add($key, 1, now()->addMinutes(15))) {
                    return;
                }
                $body = get_class($e).': '.$e->getMessage()."\n\nAt ".$e->getFile().':'.$e->getLine()
                    ."\nURL: ".(request()?->fullUrl() ?? 'console')."\nTime: ".now()->toDateTimeString()
                    ."\n\nSee storage/logs/laravel.log on the server for the full trace.";
                \Illuminate\Support\Facades\Mail::raw($body, fn ($m) => $m->to($to)->subject('[FyndMyTrip] Server error: '.\Illuminate\Support\Str::limit($e->getMessage(), 80)));
            } catch (\Throwable) {
                // never let alerting break error handling
            }
        });
    })->create();
