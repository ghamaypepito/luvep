<?php

namespace App\Http\Controllers;

use App\EpAgent\Access;
use App\EpAgent\ConversationService;
use App\EpAgent\Pilot;
use App\EpAgent\PrivateController;
use App\Models\Lead;
use Illuminate\Http\Request;

class EpWorkspaceController extends PrivateController
{
    public function __construct(private Pilot $pilot, private Access $access, private ConversationService $conversations) {}

    private function contextFor(Request $request, array $state): array
    {
        $data = $request->validate(['portal' => 'sometimes|string|max:32', 'lead_id' => 'nullable|integer|min:1', 'conversation_id' => 'nullable|integer|min:1']);
        $portal = $data['portal'] ?? $state['portals'][0];
        $leadId = $data['lead_id'] ?? null;
        $id = $data['conversation_id'] ?? null;
        if ($id) {
            $conversation = $this->conversations->read($request->user(), (int) $id);
            $portal = $conversation->portal;
            $leadId = $conversation->lead_id;
        }
        $this->access->assertPortal($request->user(), $portal);
        $context = null;
        if ($leadId) {
            $this->access->assertLead($request->user(), $portal, Lead::findOrFail($leadId));
            $lead = $this->access->leads($request->user(), $portal)->whereKey($leadId)->firstOrFail(['id', 'first_name', 'last_name']);
            $context = ['id' => $lead->id, 'label' => trim($lead->first_name.' '.$lead->last_name), 'portal' => $portal];
        }

        return ['portals' => $state['portals'], 'portal' => $portal, 'context' => $context, 'conversation_id' => $id ? (int) $id : null, 'paid_enabled' => false];
    }

    public function show(Request $request)
    {
        $state = $this->pilot->requireUser($request->user());

        return inertia('ai/EpWorkspace', ['ep' => $this->contextFor($request, $state)]);
    }

    public function context(Request $request)
    {
        $state = $this->pilot->requireUser($request->user());

        return response()->json($this->contextFor($request, $state));
    }

    public function manager(Request $request)
    {
        $state = $this->pilot->requireManager($request->user());

        return inertia('ai/EpManager', ['ep' => ['portals' => $state['portals'], 'paid_enabled' => false]]);
    }
}
