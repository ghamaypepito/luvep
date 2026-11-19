<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\HrEmployee;
use App\Models\HrPolicy;
use App\Models\HrPolicyAcknowledgement;
use App\Models\HrPolicyVersion;
use App\Services\Hr\HrAccess;
use App\Services\Hr\HrContext;
use App\Services\Hr\PolicyService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PolicyController extends Controller
{
    public function index(Request $request)
    {
        $context = app(HrContext::class);
        $preview = $context->isPreview($request);
        $actor = $request->user();
        $mine = $request->query('scope') === 'mine';
        $manage = ! $preview && ! $mine && app(HrAccess::class)->allows($actor, 'policies.publish');
        $subject = $preview ? $context->subject($request)->user_id : $actor->id;
        $acks = HrPolicyAcknowledgement::where('user_id', $subject)->get()->keyBy('hr_policy_version_id');
        $policies = HrPolicy::with(['versions' => fn ($q) => $q->orderByDesc('version')])->when(! $manage, fn ($q) => $q->whereHas('versions'))->orderByDesc('id')->limit(100)->get()->map(function ($p) use ($manage, $acks) {
            $row = ['id' => $p->id, 'revision' => $p->revision, 'versions' => $p->versions->map(fn ($v) => [...$v->only(['id', 'version', 'title', 'body', 'published_at']), 'acknowledged_at' => $acks->get($v->id)?->acknowledged_at, 'attachment' => app(PolicyService::class)->attachment($v->attachment, 'policies/versions/'.$v->id.'/attachment')])];
            if ($manage) {
                $row['draft_title'] = $p->draft_title;
                $row['draft_body'] = $p->draft_body;
                $row['draft_attachment'] = app(PolicyService::class)->attachment($p->draft_attachment, 'policies/'.$p->id.'/draft/attachment');
            }

            return $row;
        });

        return Inertia::render('hr/Policies', ['policies' => $policies, 'manage' => $manage, 'scope' => $mine ? 'mine' : 'manage']);
    }

    public function draft(Request $request, ?HrPolicy $policy = null)
    {
        $data = $request->validate(['title' => 'required|string|max:200', 'body' => 'required|string|max:100000', 'version' => 'required|integer|min:0', 'file' => 'nullable|file']);
        app(PolicyService::class)->saveDraft($request->user(), $data['title'], $data['body'], $policy, (int) $data['version'], $request->file('file'));

        return back()->with('success', 'Policy draft saved.');
    }

    public function publish(Request $request, HrPolicy $policy)
    {
        abort_unless(app(HrAccess::class)->allows($request->user(), 'policies.publish'), 403);
        $data = $request->validate(['title' => 'required|string|max:200', 'body' => 'required|string|max:100000', 'version' => 'required|integer|min:0', 'file' => 'nullable|file']);
        abort_unless($policy->revision === (int) $data['version'], 409, 'The policy changed. Refresh before publishing.');
        app(PolicyService::class)->publish($request->user(), $policy, $data['title'], $data['body'], $request->file('file'));

        return back()->with('success', 'New policy version published.');
    }

    public function download(Request $request, HrPolicyVersion $version)
    {
        return app(PolicyService::class)->download($request->user(), $version);
    }

    public function downloadDraft(Request $request, HrPolicy $policy)
    {
        abort_if(app(HrContext::class)->isPreview($request), 403);

        return app(PolicyService::class)->download($request->user(), $policy);
    }

    public function acknowledge(Request $request, HrPolicyVersion $version)
    {
        $request->validate(['declaration' => 'required|accepted']);
        $ack = app(PolicyService::class)->acknowledge($request->user(), $version);

        return $request->expectsJson() ? response()->json(['acknowledged_at' => $ack->acknowledged_at]) : back()->with('success', 'Your acknowledgement has been recorded for this version.');
    }

    public function acknowledgements(Request $request, HrPolicyVersion $version)
    {
        abort_unless(! app(HrContext::class)->isPreview($request) && app(HrAccess::class)->allows($request->user(), 'policies.publish'), 403);
        $acks = HrPolicyAcknowledgement::where('hr_policy_version_id', $version->id)->get()->keyBy('user_id');
        $employees = HrEmployee::with('user')->where('status', 'active')->get()->filter(fn ($e) => $e->user && app(HrAccess::class)->employee($e->user))->map(fn ($e) => ['name' => $e->user->name, 'employee_id' => $e->id, 'acknowledged_at' => $acks->get($e->user_id)?->acknowledged_at])->values();

        return response()->json(['title' => $version->title, 'version' => $version->version, 'employees' => $employees]);
    }
}
