<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a route behind a RESTRICTED module (config/modules.php). Access passes
 * when the user holds that module — super admins always do; everyone else
 * needs it granted via Module Management (users.module_permissions).
 *
 * Usage: Route::middleware('module:agents'). Complements the sidebar gate so a
 * hidden module can't be reached by typing the URL.
 */
class EnsureModuleAccess
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $user = $request->user();

        abort_if($module === 'accommodation_finance' && $request->attributes->has('user_view_actor'), 403);
        abort_unless($user && $user->canSeeModule($module), 403);

        return $next($request);
    }
}
