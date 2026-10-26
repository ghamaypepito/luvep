<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;

class RequireSavedMarketingApproval
{
    public function handle(Request $request, Closure $next)
    {
        if (Setting::get('ghl_connector.workflow_enabled', false)) {
            return response()->json(['error' => 'saved_approval_required', 'message' => 'Use the Marketing Workspace saved approval workflow before scheduling a post.'], 409);
        }

        return $next($request);
    }
}
