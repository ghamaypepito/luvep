<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\HrAuditEvent;
use App\Models\HrEmployee;
use App\Models\HrNotificationDelivery;
use App\Models\User;
use App\Services\Hr\HrAccess;
use App\Services\Hr\HrContext;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AuditController extends Controller
{
    public function index(Request $r)
    {
        abort_if(app(HrContext::class)->isPreview($r), 403);
        abort_unless(app(HrAccess::class)->allows($r->user(), 'audit'), 403);
        $f = $r->validate(['employee_id' => 'nullable|integer', 'action' => 'nullable|string|max:100', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
        $f = ['from' => now()->subDays(30)->toDateString(), 'to' => now()->toDateString(), ...array_filter($f, fn ($v) => $v !== null)];
        if (\Carbon\CarbonImmutable::parse($f['from'])->diffInDays(\Carbon\CarbonImmutable::parse($f['to'])) > 365) {
            throw ValidationException::withMessages(['to' => 'Choose a range of at most 366 days.']);
        }
        $q = HrAuditEvent::whereDate('created_at', '>=', $f['from'])->whereDate('created_at', '<=', $f['to']);
        if (! empty($f['action'])) {
            $q->where('action', $f['action']);
        }if (! empty($f['employee_id'])) {
            $e = HrEmployee::findOrFail($f['employee_id']);
            $q->where(fn ($q) => $q->where('metadata->employee_id', $e->id)->orWhere(fn ($q) => $q->where('subject_type', $e->getMorphClass())->where('subject_id', $e->id))->orWhere(fn ($q) => $q->where('subject_type', (new User)->getMorphClass())->where('subject_id', $e->user_id)));
        }
        $events = $q->latest('id')->limit(200)->get()->map(fn ($e) => ['id' => $e->id, 'action' => $e->action, 'actor' => User::find($e->actor_id)?->name ?? 'Former account', 'subject' => class_basename($e->subject_type).' #'.$e->subject_id, 'metadata' => array_intersect_key($e->metadata ?? [], array_flip(['fields', 'version', 'employee_id', 'previous_status', 'status', 'grants', 'previous_grants'])), 'created_at' => $e->created_at]);
        $employees = HrEmployee::with('user')->get()->map(fn ($e) => ['id' => $e->id, 'name' => $e->user?->name]);
        $actions = HrAuditEvent::distinct()->orderBy('action')->pluck('action');
        $failures = HrNotificationDelivery::whereNull('sent_at')->where('attempts', '>=', 5)->latest('id')->limit(50)->get(['id', 'event_id', 'user_id', 'channel', 'attempts', 'updated_at']);

        return Inertia::render('hr/Audit', compact('events', 'employees', 'actions', 'failures') + ['filters' => $f]);
    }
}
