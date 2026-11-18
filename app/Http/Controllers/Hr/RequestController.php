<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\HrRequest;
use App\Models\User;
use App\Policies\HrRequestPolicy;
use App\Services\Hr\HrAccess;
use App\Services\Hr\HrContext;
use App\Services\Hr\RequestService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RequestController extends Controller
{
    public function index(Request $request)
    {
        $actor = $request->user();
        $access = app(HrAccess::class);
        $context = app(HrContext::class);
        $preview = $context->isPreview($request);
        $employee = $preview ? $context->subject($request) : $access->employee($actor);
        $policy = app(HrRequestPolicy::class);
        $query = HrRequest::query();
        if ($preview) {
            $query->where('hr_employee_id', $employee->id);
        } elseif (! $access->allows($actor, 'cases.manage') && ! $actor->isAdmin()) {
            $query->where(function ($q) use ($actor, $employee, $access) {
                $q->where('hr_employee_id', $employee?->id ?? 0);
                if ($access->allows($actor, 'cases.assigned')) {
                    $q->orWhere('assigned_user_id', $actor->id);
                }
            });
        }
        $requests = $query->orderByDesc('id')->limit(200)->get()->filter(fn ($c) => ! $preview || $policy->manage($actor, $c))->map(function ($c) use ($actor, $policy) {
            $view = $policy->view($actor, $c);
            $row = $c->only(['id', 'category', 'status', 'created_at']);
            $row['can_view'] = $view;
            if ($view) {
                $row['subject'] = $c->subject;
            }

return $row;
        })->values();

        return Inertia::render('hr/Requests', ['requests' => $requests, 'case' => null, 'assignees' => []]);
    }

    public function show(Request $request, HrRequest $case)
    {
        $actor = $request->user();
        $policy = app(HrRequestPolicy::class);
        $context = app(HrContext::class);
        $preview = $context->isPreview($request);
        abort_unless($policy->view($actor, $case), 404);
        if ($preview) {
            abort_unless($case->hr_employee_id === $context->subject($request)->id && $policy->manage($actor, $case), 404);
        }
        $manage = ! $preview && $policy->manage($actor, $case);
        $row = $case->only(['id', 'subject', 'category', 'status', 'created_at']);
        $row['can_manage'] = $manage;
        $row['can_assign'] = $manage && app(HrAccess::class)->allows($actor, 'cases.manage');
        if ($manage) {
            $row['version'] = $case->version;
            $row['assigned_user_id'] = $case->assigned_user_id;
            $row['employee_name'] = $case->employee->user?->name;
        }
        $row['messages'] = $case->messages()->with('actor:id,name')->when(! $manage, fn ($q) => $q->where('internal', false))->latest('id')->limit(200)->get()->reverse()->values()->map(fn ($m) => ['id' => $m->id, 'body' => $m->body, 'internal' => $m->internal, 'actor_name' => $m->actor?->name, 'created_at' => $m->created_at]);
        $assignees = $row['can_assign'] ? User::where('is_active', true)->where('id', '!=', $case->employee->user_id)->get()->filter(fn ($u) => $policy->eligibleAssignee($u))->map(fn ($u) => $u->only(['id', 'name']))->values() : [];

        return Inertia::render('hr/Requests', ['requests' => [], 'case' => $row, 'assignees' => $assignees]);
    }

    public function store(Request $request)
    {
        $d = $request->validate(['category' => 'required|string', 'subject' => 'required|string', 'body' => 'required|string', 'request_id' => 'required|uuid']);
        $case = app(RequestService::class)->open($request->user(), $d['category'], $d['subject'], $d['body'], $d['request_id']);

        return $request->expectsJson() ? response()->json(['id' => $case->id], 201) : redirect(app(HrContext::class)->url('requests/'.$case->id));
    }

    public function reply(Request $request, HrRequest $case)
    {
        $d = $request->validate(['body' => 'required|string|max:20000', 'internal' => 'required|boolean']);
        app(RequestService::class)->reply($request->user(), $case, $d['body'], (bool) $d['internal']);

        return back()->with('success', 'Message saved.');
    }

    public function assign(Request $request, HrRequest $case)
    {
        $d = $request->validate(['assignee_id' => 'required|integer|exists:users,id', 'version' => 'required|integer|min:0']);
        app(RequestService::class)->assign($request->user(), $case, User::findOrFail($d['assignee_id']), (int) $d['version']);

        return back()->with('success', 'Case assignment updated.');
    }

    public function close(Request $request, HrRequest $case)
    {
        $d = $request->validate(['version' => 'required|integer|min:0']);
        app(RequestService::class)->close($request->user(),$case,(int) $d['version']);

        return back()->with('success','HR request closed.');
    }
}
