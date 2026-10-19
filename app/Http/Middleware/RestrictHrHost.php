<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictHrHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->getHost() === config('hr.host')) {
            abort_unless(config('hr.enabled') && str_starts_with($request->route()?->getName() ?? '', 'hr.'), 404);
        }

        return $next($request);
    }
}
