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
        $middleware->web(replace: [\Illuminate\Session\Middleware\StartSession::class => \App\Http\Middleware\StartLuvepSharedSession::class], append: [
            \App\Http\Middleware\RestrictHrHost::class,
            \App\Http\Middleware\BlockHrPreviewWrites::class,
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
        //
    })->create();
