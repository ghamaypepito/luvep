<?php

namespace App\Http\Middleware;

use App\Models\HrEmployee;
use App\Services\Hr\HrAccess;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProtectHrLegacyDtr
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user() && in_array($request->route()?->getName(), ['dtr.timein', 'dtr.timeout', 'dtr.entry', 'dtr.pending.toggle', 'dtr.setup', 'admin.dtr.entry.update', 'admin.dtr.entry.delete'], true)) {
            $target = in_array($request->route()?->getName(), ['admin.dtr.entry.update', 'admin.dtr.entry.delete', 'dtr.setup'], true) ? (int) ($request->input('user_id') ?? $request->user()->id) : $request->user()->id;

            try {
                return DB::transaction(function () use ($request, $next, $target) {
                    if (config('hr.enabled')) {
                        // Serialize legacy writes with HR activation and live clock transitions.
                        HrEmployee::where('user_id', $target)->lockForUpdate()->first();
                        if (! $request->routeIs('dtr.timein', 'dtr.timeout')) {
                            abort_if(app(HrAccess::class)->requiresHrDtr($target), 409, 'Use Time & Attendance in the HR portal to update or request a correction to this record.');
                        }
                    }

                    $response = $next($request);
                    // The routing pipeline can render a controller exception before returning here.
                    if ($response->getStatusCode() >= 400) {
                        throw new HttpResponseException($response);
                    }

                    return $response;
                });
            } catch (HttpResponseException $exception) {
                return $exception->getResponse();
            }
        }

        return $next($request);
    }
}
