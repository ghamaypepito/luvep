<?php

namespace App\Http\Controllers\Api;

use App\EpAgent\ManagerService;
use App\EpAgent\PrivateController;
use Illuminate\Http\Request;

class EpManagerController extends PrivateController
{
    public function __construct(private ManagerService $manager) {}

    private function portal(Request $request): string
    {
        return $request->validate(['portal' => 'required|string|max:32'])['portal'];
    }

    public function index(Request $request)
    {
        $data = $request->validate(['before' => 'nullable|integer|min:1', 'attention' => 'sometimes|boolean']);

        return response()->json($this->manager->overview($request->user(), $this->portal($request), isset($data['before']) ? (int) $data['before'] : null, (bool) ($data['attention'] ?? false)));
    }

    public function client(Request $request, int $id)
    {
        return response()->json(['client' => $this->manager->client($request->user(), $this->portal($request), $id)]);
    }

    public function personnel(Request $request, int $id)
    {
        return response()->json(['personnel' => $this->manager->personnel($request->user(), $this->portal($request), $id)]);
    }
}
