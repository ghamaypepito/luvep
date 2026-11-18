<?php

namespace App\Http\Middleware;

use App\Services\Hr\HrAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureHrAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('hr.enabled'), 404);
        $hosts = array_filter([config('hr.host'), parse_url(config('hr.main_url'), PHP_URL_HOST), parse_url(config('app.url'), PHP_URL_HOST)]);
        abort_unless(in_array($request->getHost(), $hosts, true), 404);
        $actor = $request->user();
        abort_unless($actor, 401);
        $access = app(HrAccess::class);
        $permitted = $access->employee($actor) || collect(config('hr.abilities'))->contains(fn ($ability) => $access->allows($actor, $ability));
        if (! $permitted && $access->internal($actor) && $request->is('portal/hr', 'portal/hr/dashboard') && $request->isMethod('GET')) {
            $response = \Inertia\Inertia::render('hr/Pending')->toResponse($request)->setStatusCode(403);
            $response->headers->set('Cache-Control', 'private, no-store');

            return $response;
        }
        abort_unless($permitted, 403);
        if (str_starts_with($request->route()?->getName() ?? '', 'hr.main.') && $request->isMethod('GET') && ! $request->expectsJson() && ! $request->is('hr/clock*', 'hr/api/*')) {
            $path = substr($request->getPathInfo(), 3);
            $query = $request->getQueryString();

            return \Inertia\Inertia::location(app(\App\Services\Hr\HrContext::class)->url($path).($query ? '?'.$query : ''));
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
