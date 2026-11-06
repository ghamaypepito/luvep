<?php

namespace App\Http\Controllers\Admin;

use App\EpAgent\PrivateController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EpAgentUsageController extends PrivateController
{
    public function __invoke(Request $request)
    {
        abort_unless(User::findOrFail($request->user()->id)->isAdmin(), 403, 'ep_access_denied');
        $data = $request->validate(['before' => 'sometimes|integer|min:1']);
        $rows = DB::table('ep_runs as r')->leftJoin('ep_usage_reservations as u', 'u.id', '=', 'r.reservation_id')
            ->whereIn('r.portal', array_intersect(['education', 'immigration'], config('ep_agent.portals', [])))
            ->where('r.id', '<', (int) ($data['before'] ?? PHP_INT_MAX))->orderByDesc('r.id')->limit(50)
            ->get(['r.id', 'r.actor_id', 'r.portal', 'r.state', 'r.reason', 'r.created_at', 'u.actual_micros', 'u.upper_bound_micros',
                'u.input_tokens', 'u.output_tokens', 'u.cached_read_tokens', 'u.cached_write_tokens', 'u.tool_units']);

        return response()->json(['data' => $rows]);
    }
}
