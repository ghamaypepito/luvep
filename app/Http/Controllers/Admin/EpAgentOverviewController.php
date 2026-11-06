<?php

namespace App\Http\Controllers\Admin;

use App\EpAgent\AdminOverview;
use App\EpAgent\PrivateController;
use Illuminate\Http\Request;

class EpAgentOverviewController extends PrivateController
{
    public function __construct(private AdminOverview $overview) {}

    public function page(Request $request)
    {
        $this->overview->authorize($request->user());

        return inertia('admin/EpAgentOverview');
    }

    public function overview(Request $request)
    {
        $data = $request->validate(['period' => 'sometimes|in:month,7d,30d']);

        return response()->json($this->overview->overview($request->user(), $data['period'] ?? 'month'));
    }

    public function runs(Request $request)
    {
        $data = $request->validate(['period' => 'sometimes|in:month,7d,30d', 'portal' => 'nullable|in:education,immigration', 'state' => 'nullable|in:'.implode(',', AdminOverview::STATES), 'before' => 'nullable|integer|min:1']);

        return response()->json($this->overview->runs($request->user(), $data['period'] ?? 'month', $data['portal'] ?? null, $data['state'] ?? null, isset($data['before']) ? (int) $data['before'] : null));
    }

    public function run(Request $request, int $id)
    {
        return response()->json(['run' => $this->overview->run($request->user(), $id)]);
    }
}
