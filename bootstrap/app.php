<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: [
            '/api/sync-calendar',
            '/api/chat',
            '/stripe/webhook',
            '/webhook/zernio',
        ]);
        $middleware->prependToPriorityList(\Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class, \App\Http\Middleware\RestrictHrHost::class);
        $middleware->prependToPriorityList(\Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class, \App\Http\Middleware\ApplyUserView::class);
        $middleware->web(replace: [\Illuminate\Session\Middleware\StartSession::class => \App\Http\Middleware\StartLuvepSharedSession::class], append: [
            \App\Http\Middleware\RestrictHrHost::class,
            \App\Http\Middleware\ApplyUserView::class,
            \App\Http\Middleware\BlockHrPreviewWrites::class,
            \App\Http\Middleware\ProtectHrLegacyDtr::class,
            HandleInertiaRequests::class,
            \App\Http\Middleware\EnsureAccountActive::class,
            \App\Http\Middleware\EnsureNotInMaintenance::class,
        ]);
        $middleware->alias([
            'portal' => \App\Http\Middleware\EnsurePortalAccess::class,
            'tracker.enabled' => \App\Http\Middleware\EnsureTrackerEnabled::class,
            'module' => \App\Http\Middleware\EnsureModuleAccess::class,
            // Row-level scoping for the lead-profile routes mirrored under the
            // recruiting portals — `portal:` alone is role-level only.
            'lead.scope' => \App\Http\Middleware\EnsureLeadInPortalScope::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['api_key', 'ghl_api_token']);
        $exceptions->respond(function ($response, $exception, $request) {
            if ($request->attributes->has('user_view_actor') && ! $request->expectsJson() && in_array($response->getStatusCode(), [403, 404])) {
                return \Inertia\Inertia::render('UserViewError', ['status' => $response->getStatusCode(), 'userView' => \App\Services\UserView::props($request)])
                    ->toResponse($request)->setStatusCode($response->getStatusCode());
            }

            return $response;
        });
    })->create();
