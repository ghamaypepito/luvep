<?php

namespace App\Http\Middleware;

use App\Services\Hr\HrContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockHrPreviewWrites
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('hr.enabled') && $request->hasSession() && $request->session()->has('hr_preview_employee_id')) {
            $exit = in_array($request->route()?->getName(), ['hr.preview.exit', 'hr.main.preview.exit', 'logout', 'hr.logout'], true);
            if (! $exit) {
                app(HrContext::class)->validate($request);
                abort_unless($request->isMethodSafe(), 403, 'Employee preview is read-only. Return to admin to make changes.');
            }
        }

        return $next($request);
    }
}
