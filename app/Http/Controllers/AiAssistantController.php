<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\AIService;
use App\Services\AiRecordContext;
use App\Support\AiScope;
use Illuminate\Http\Request;

/**
 * The single, dedicated AI Assistant page. General by default; when opened
 * with ?subject_id=… it scopes to that record (authorised here, before the
 * page renders), so the assistant answers grounded to the file — with the
 * immigration no-advice guardrail enforced server-side in AiChatService.
 */
class AiAssistantController extends Controller
{
    public function show(Request $request, AIService $ai)
    {
        $user = $request->user();
        abort_if($user->isLead(), 403, 'The assistant is for staff only.');

        $subject = null;
        if ($request->filled('subject_id')) {
            $lead = Lead::find($request->integer('subject_id'));
            if ($lead && AiScope::canAccessLead($user, $lead)) {
                $ctx = AiRecordContext::for($lead);
                $subject = [
                    'type' => 'lead',
                    'id' => $lead->id,
                    'label' => $ctx['label'],
                    'immigration' => $ctx['is_immigration'],
                ];
            }
            // Silently drop an out-of-scope/unknown id — the page just opens
            // in general mode rather than leaking that the record exists.
        }

        if ($request->wantsJson()) {
            return response()->json(['subject' => $subject, 'aiEnabled' => $ai->isEnabled()]);
        }

        return inertia('ai/Assistant', [
            'subject' => $subject,
            'aiEnabled' => $ai->isEnabled(),
            'backUrl' => url()->previous() !== url()->current() ? url()->previous() : $user->homeRoute(),
        ]);
    }
}
