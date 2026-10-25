<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\EducationOutreach;
use App\Models\EducationRsvp;
use App\Models\EducationVisit;
use App\Models\Setting;
use App\Services\ProgramSlackNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class EducationRsvpAdminController extends Controller
{
    public function index(Request $request)
    {
        $filter = $request->validate(['kind' => ['nullable', Rule::in(['school', 'partnership'])], 'email' => 'nullable|email:rfc|max:254']);
        $rsvps = EducationRsvp::with('visit')->when($filter['kind'] ?? null, fn ($q, $kind) => $q->whereHas('visit', fn ($v) => $v->where('kind', $kind)))->when($filter['email'] ?? null, fn ($q, $email) => $q->where('email', strtolower(trim($email))))->latest()->paginate(50)->withQueryString();

        return response()->view('education.rsvp-admin', ['visits' => EducationVisit::withCount('rsvps')->orderBy('id')->get(), 'rsvps' => $rsvps, 'slackReady' => app(ProgramSlackNotifier::class)->configured(), 'pending' => EducationOutreach::whereNull('sent_at')->whereNull('cancelled_at')->count(), 'failed' => EducationOutreach::where('attempts', '>=', 8)->whereNull('sent_at')->count()])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, EducationVisit $visit)
    {
        $d = $request->validate(['event_date' => 'nullable|date_format:Y-m-d', 'event_time' => 'nullable|date_format:H:i', 'timezone' => ['required', Rule::in(['Asia/Manila', 'Pacific/Auckland'])], 'location' => 'nullable|string|max:255', 'schedule_confirmed' => 'required|boolean', 'status' => ['required', Rule::in(['open', 'cancelled', 'closed'])]]);
        if ($d['schedule_confirmed'] && ! ($d['event_date'] ?? null)) {
            return response()->json(['message' => 'Choose a confirmed date first.'], 422);
        }DB::transaction(function () use ($d, $visit) {
            $v = EducationVisit::whereKey($visit->id)->lockForUpdate()->firstOrFail();
            $v->fill($d);
            if ($v->isDirty()) {
                if ($v->isDirty(['event_date', 'event_time', 'timezone', 'location', 'schedule_confirmed']) || ($v->isDirty('status') && in_array('cancelled', [$v->status, $v->getOriginal('status')], true))) {
                    $v->schedule_version++;
                }
                $v->save();
                ActivityLog::record('education.rsvp.schedule_updated', ['portal' => 'education', 'properties' => ['visit_id' => $v->id, 'schedule_version' => $v->schedule_version], 'description' => 'Updated Education RSVP schedule']);
            }
        });

        return $request->expectsJson() ? response()->json(['ok' => true]) : back()->with('status', 'Schedule saved. Confirmed changes will be sent to verified RSVP contacts.');
    }

    public function attendance(Request $request, EducationRsvp $rsvp)
    {
        $d = $request->validate(['attendance_status' => ['required', Rule::in(['rsvp', 'attended', 'cancelled'])]]);
        $rsvp->update($d);
        ActivityLog::record('education.rsvp.attendance_updated', ['portal' => 'education', 'properties' => ['rsvp_id' => $rsvp->id, 'status' => $d['attendance_status']]]);

        return back()->with('status', 'Attendance updated.');
    }

    public function slack(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $validator = Validator::make($request->only(['slack_webhook', 'channel_id']), ['slack_webhook' => ['nullable', 'string', 'max:2000', 'regex:#^https://hooks\.slack\.com/services/[A-Za-z0-9/_-]+$#'], 'channel_id' => ['required', 'regex:/^C[A-Z0-9]+$/']]);
        if ($validator->fails()) {
            return $request->expectsJson() ? response()->json(['errors' => $validator->errors()], 422) : back()->withErrors($validator);
        }$d = $validator->validated();
        if (! empty($d['slack_webhook'])) {
            Setting::set('education_slack_webhook_encrypted', Crypt::encryptString($d['slack_webhook']));
        }Setting::set('education_slack_channel_id', $d['channel_id']);
        EducationOutreach::where('channel', 'slack')->whereNull('sent_at')->whereNull('cancelled_at')->update(['next_attempt_at' => null, 'attempts' => 0]);
        ActivityLog::record('education.rsvp.slack_configured', ['portal' => 'education', 'description' => 'Updated RSVP Slack connection']);

        return back()->with('status', 'Slack settings saved. Pending alerts will retry.');
    }
}
