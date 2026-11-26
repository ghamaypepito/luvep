<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureSocialTeamAccess
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->canUseSocialMarketing(), 403);

        return $next($request);
    }
}
