<?php

namespace App\Http\Middleware;

use App\Models\HrEmployee;
use Closure;
use Illuminate\Http\Request;

class ProtectHrLegacyDtr
{
    public function handle(Request $request, Closure $next)
    {
        if (config('hr.enabled') && $request->user() && in_array($request->route()?->getName(), ['dtr.entry', 'dtr.pending.toggle', 'dtr.setup', 'admin.dtr.entry.update', 'admin.dtr.entry.delete'], true)) {
            $target = in_array($request->route()?->getName(), ['admin.dtr.entry.update', 'admin.dtr.entry.delete', 'dtr.setup'], true) ? (int) ($request->input('user_id') ?? $request->user()->id) : $request->user()->id;
            abort_if(HrEmployee::where('user_id', $target)->exists(), 409, 'Use Time & Attendance in the HR portal to update or request a correction to this record.');
        }

        return $next($request);
    }
}
