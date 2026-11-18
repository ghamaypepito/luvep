<?php

namespace App\Http\Controllers;

use App\Models\CreativeRequest;
use App\Models\DtrSetting;
use App\Models\LeadTask;
use App\Models\User;
use App\Notifications\CreativeRequestAssigned;
use App\Support\TicketAttachments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CreativeRequestController extends Controller
{
    private function staff(Request $request): User
    {
        $user = $request->user();
        abort_unless($user?->is_active && in_array($user->role, [...User::PORTAL_ROLES, ...User::IMMIGRATION_ROLES, 'admin', 'super_admin'], true), 403);

        return $user;
    }

    private function owner(): ?User
    {
        return User::where('email', config('creative.assignee_email'))->where('is_active', true)
            ->whereNotIn('role', ['lead', 'revoked_lead'])->first();
    }

    public function index(Request $request)
    {
        $user = $this->staff($request);
        $filters = $request->validate(['status' => ['nullable', Rule::in(LeadTask::STATUSES)], 'view' => ['nullable', Rule::in(['active', 'deleted'])]]);
        $deleted = ($filters['view'] ?? 'active') === 'deleted';
        abort_if($deleted && ! $user->isAdmin(), 403);
        $requests = CreativeRequest::visibleTo($user)->with(['task.assignee:id,name', 'task.creator:id,name'])
            ->when($deleted, fn ($q) => $q->onlyTrashed())
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->whereHas('task', fn ($q) => $q->where('status', $status)))
            ->latest()->paginate(20)->withQueryString()->through(fn ($r) => $r->row());

        return inertia('creative/Index', ['requests' => $requests, 'filters' => $filters,
            'owner' => $this->owner()?->only(['id', 'name']), 'designTypes' => CreativeRequest::DESIGN_TYPES,
            'isAdmin' => $user->isAdmin()]);
    }

    public function store(Request $request)
    {
        $user = $this->staff($request);
        $rules = [
            'project' => ['required', 'string', 'max:160'], 'deadline' => ['required', 'date_format:Y-m-d'],
            'overview' => ['required', 'string', 'max:5000'], 'types' => ['required', 'array', 'min:1', 'max:4'],
            'types.*' => ['required', 'distinct', Rule::in(CreativeRequest::DESIGN_TYPES)],
            'other' => ['nullable', 'string', 'max:200'],
            'approver' => ['required', 'string', 'max:160'],
            'final_due' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:deadline'],
        ];
        foreach (['goal', 'audience', 'message', 'copy', 'caption', 'cta', 'style', 'references', 'include', 'avoid', 'assets', 'missing', 'budget', 'notes'] as $field) {
            $rules[$field] = ['nullable', 'string', 'max:10000'];
        }
        $final = $request->input('final_due') ?: $request->input('deadline');
        foreach (['draft_due', 'feedback_due'] as $field) {
            $rules[$field] = ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.($final ?: 'deadline')];
        }
        $data = $request->validate($rules + TicketAttachments::rules());
        if (in_array('Other', $data['types'], true) && ! trim($data['other'] ?? '')) {
            throw ValidationException::withMessages(['other' => 'Describe the other design type.']);
        }
        if (! empty($data['draft_due']) && ! empty($data['feedback_due']) && $data['draft_due'] > $data['feedback_due']) {
            throw ValidationException::withMessages(['feedback_due' => 'Feedback must be on or after the first draft.']);
        }
        $owner = $this->owner();
        if (! $owner) {
            throw ValidationException::withMessages(['assignee' => 'Sharry’s active Luvep account could not be found. Contact an administrator before submitting.']);
        }
        unset($data['attachments']);
        $data['final_due'] = $data['final_due'] ?? $data['deadline'];
        if (! filter_var(config('creative.notification_email'), FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['notification' => 'The creative request email alert is not configured. Contact an administrator before submitting.']);
        }
        $data['timezone'] = DtrSetting::where('user_id', $owner->id)->value('timezone') ?: 'Asia/Manila';
        $stored = [];
        try {
            $creative = DB::transaction(function () use ($request, $data, $user, $owner, &$stored) {
                $task = LeadTask::create(['created_by' => $user->id, 'assignee_id' => $owner->id,
                    'title' => $data['project'], 'description' => Str::limit($data['overview'], 1500), 'type' => 'internal', 'department' => 'admin',
                    'category' => 'Creative request', 'priority' => 'normal', 'status' => 'not_started',
                    'due_at' => \Illuminate\Support\Carbon::parse($data['deadline'].' 23:59:59', $data['timezone'])->utc()]);
                $creative = CreativeRequest::create(['request_ref' => 'CR-'.strtoupper(Str::random(8)), 'lead_task_id' => $task->id, 'brief' => $data]);
                $stored = TicketAttachments::store($request->file('attachments', []), "creative-requests/{$creative->id}");
                $creative->update(['attachments' => $stored ?: null]);
                $task->update(['description' => Str::limit($data['overview'], 1500)."\n\nFull creative brief: ".$creative->url()]);

                return $creative;
            });
        } catch (\Throwable $e) {
            TicketAttachments::delete($stored);
            throw $e;
        }
        // Delivery failure must not undo a saved request or delete its private files.
        try {
            $owner->notify(new CreativeRequestAssigned($creative, $user->name));
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            Notification::route('mail', config('creative.notification_email'))->notify(new CreativeRequestAssigned($creative, $user->name));
        } catch (\Throwable $e) {
            report($e);

            return redirect('/creative-requests/'.$creative->id)->with('success', 'Creative request saved and assigned.')->with('error', 'The email alert could not be delivered. The request remains in Sharry’s queue.');
        }

        return redirect('/creative-requests/'.$creative->id)->with('success', 'Creative request assigned to Sharry.');
    }

    private function authorizeRequest(Request $request, CreativeRequest $creative): User
    {
        $user = $this->staff($request);
        abort_unless($creative->canManage($user) || $creative->task->created_by === $user->id, 403);

        return $user;
    }

    public function show(Request $request, CreativeRequest $creative)
    {
        $this->authorizeRequest($request, $creative);
        $creative->load(['task.assignee:id,name', 'task.creator:id,name']);

        return inertia('creative/Show', ['creative' => $creative->row(true), 'isAdmin' => $request->user()->isAdmin()]);
    }

    public function forTask(Request $request, int $task)
    {
        $creative = CreativeRequest::where('lead_task_id', $task)->firstOrFail();
        $this->authorizeRequest($request, $creative);

        return redirect('/creative-requests/'.$creative->id);
    }

    public function status(Request $request, CreativeRequest $creative)
    {
        $user = $this->authorizeRequest($request, $creative);
        abort_unless($creative->canManage($user), 403);
        $data = $request->validate(['status' => ['required', Rule::in(LeadTask::STATUSES)], 'expected_status' => ['required', Rule::in(LeadTask::STATUSES)]]);
        DB::transaction(function () use ($creative, $data, $user) {
            $task = LeadTask::whereKey($creative->lead_task_id)->lockForUpdate()->firstOrFail();
            abort_unless($user->isAdmin() || in_array($user->id, $task->allAssigneeIds(), true), 403);
            if ($task->status !== $data['expected_status']) {
                throw ValidationException::withMessages(['status' => 'This request changed. Refresh before updating its status.']);
            }
            $task->update(['status' => $data['status'], 'completed_by' => $data['status'] === 'completed' ? $user->id : null]);
        });

        return back()->with('success', 'Creative request status updated.');
    }

    public function attachment(Request $request, CreativeRequest $creative, int $index)
    {
        $this->authorizeRequest($request, $creative);

        return TicketAttachments::stream($request, $creative->attachments ?? [], $index);
    }

    public function destroy(Request $request, CreativeRequest $creative)
    {
        abort_unless($this->staff($request)->isAdmin(), 403);
        $data = $request->validate(['expected_status' => ['required', Rule::in(LeadTask::STATUSES)]]);
        DB::transaction(function () use ($creative, $data) {
            $task = LeadTask::whereKey($creative->lead_task_id)->lockForUpdate()->firstOrFail();
            $locked = CreativeRequest::whereKey($creative->id)->lockForUpdate()->firstOrFail();
            if ($task->status !== $data['expected_status']) {
                throw ValidationException::withMessages(['status' => 'This request changed. Refresh before deleting it.']);
            }
            $task->delete();
            $locked->delete();
        });

        return redirect('/admin/system-tickets?type=digital')
            ->with('success', 'Request moved to Deleted requests. Its brief, files and task are kept for recovery.');
    }

    public function restore(Request $request, CreativeRequest $creative)
    {
        abort_unless($this->staff($request)->isAdmin(), 403);
        $data = $request->validate(['expected_status' => ['required', Rule::in(LeadTask::STATUSES)]]);
        DB::transaction(function () use ($creative, $data) {
            $task = LeadTask::withTrashed()->whereKey($creative->lead_task_id)->lockForUpdate()->firstOrFail();
            $locked = CreativeRequest::onlyTrashed()->whereKey($creative->id)->lockForUpdate()->firstOrFail();
            if ($task->status !== $data['expected_status']) {
                throw ValidationException::withMessages(['status' => 'This request changed. Refresh before restoring it.']);
            }
            $task->restore();
            $locked->restore();
        });

        return back()->with('success', 'Creative request restored with its original task status and files.');
    }
}
