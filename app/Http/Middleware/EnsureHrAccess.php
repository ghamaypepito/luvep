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
        $actor = $request->user();
        abort_unless($actor, 401);
        $access = app(HrAccess::class);
        abort_unless($access->employee($actor) || $access->allows($actor, 'people') || $access->allows($actor, 'preview'), 403);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
