<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\HrAccess;
use App\Services\Hr\HrContext;
use App\Services\Hr\TeamWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;

class TeamController extends Controller
{
    public function __invoke(Request $request, TeamWorkspace $workspace)
    {
        abort_if(app(HrContext::class)->isPreview($request), 403);
        $actor = $request->user();
        $team = $workspace->forActor($actor, app(HrAccess::class)->employee($actor) !== null);
        abort_if($team === null, 403);

        return Inertia::render('hr/Team', Arr::except($team, 'summary'));
    }
}
