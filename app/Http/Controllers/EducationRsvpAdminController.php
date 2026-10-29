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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class EducationRsvpAdminController extends Controller
{
    public function index(Request $request)
    {
        if ($request->header('X-Inertia')) {
            return \Inertia\Inertia::location(route('education.rsvps.manage', $request->query()));
        }
        $attendance = app(\App\Services\EducationAttendance::class);
        $attendanceContext = $attendance->context($request);
        $rsvps = $attendance->query($attendanceContext['attendanceFilters'])->with('folders:id,name')->latest('id')->paginate(50)->withQueryString()->fragment('attendance');

        return response()->view('education.rsvp-admin', $attendanceContext + ['attendanceFolders' => \App\Models\EducationRsvpFolder::with('lists')->withCount('rsvps')->orderBy('name')->get(), 'page' => app(\App\Services\EducationRsvpContent::class)->get(), 'contentSchema' => app(\App\Services\EducationRsvpContent::class)->schema(), 'visits' => EducationVisit::withCount('rsvps')->orderBy('id')->get(), 'rsvps' => $rsvps, 'slackReady' => app(ProgramSlackNotifier::class)->configured(), 'pending' => EducationOutreach::whereNull('sent_at')->whereNull('cancelled_at')->count(), 'failed' => EducationOutreach::where('attempts', '>=', 8)->whereNull('sent_at')->count()])->header('Cache-Control', 'private, no-store');
    }

    public function content(Request $request, string $section)
    {
        app(\App\Services\EducationRsvpContent::class)->save($request, $section);

        return $request->expectsJson() ? response()->json(['ok' => true]) : redirect()->route('education.rsvps.manage')->with('status', 'Page section saved and published.')->with('open_content', $section);
    }

    private function scheduleRules(Request $request, bool $creating = false): array
    {
        return [
            'school' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:180'],
            'activity' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:180'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'mode' => [$creating ? 'required' : 'sometimes', Rule::in(['in_person', 'online'])],
            'event_date' => [Rule::requiredIf($request->boolean('schedule_confirmed')), 'nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:'.now()->addYears(2)->format('Y-m-d')],
            'event_time' => 'nullable|date_format:H:i',
            'timezone' => ['required', Rule::in(\App\Support\EducationTimeZones::identifiers())],
            'location' => 'nullable|string|max:255', 'schedule_confirmed' => 'required|boolean',
            'is_hidden' => 'sometimes|boolean',
            'status' => ['required', Rule::in($creating ? ['open', 'closed'] : ['open', 'closed', 'cancelled'])],
        ];
    }

    public function create(Request $request)
    {
        $d = $request->validate($this->scheduleRules($request, true));
        $d['school'] = preg_replace('/\s+/u', ' ', trim($d['school']));
        $d['activity'] = trim($d['activity']);
        $visit = DB::transaction(function () use ($d) {
            $existing = EducationVisit::whereRaw('LOWER(TRIM(school)) = ?', [mb_strtolower($d['school'])])->whereNotNull('logo_path')->latest('id')->first();
            if ($existing) {
                $d['school'] = $existing->school;
            }
            $v = EducationVisit::create($d + ['slug' => \Illuminate\Support\Str::limit(\Illuminate\Support\Str::slug($d['school']), 180, '').'-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(10)), 'kind' => 'school', 'logo_path' => $existing?->logo_path, 'schedule_version' => $d['schedule_confirmed'] ? 1 : 0]);
            ActivityLog::record('education.rsvp.schedule_created', ['portal' => 'education', 'properties' => ['visit_id' => $v->id], 'description' => 'Added Education event schedule']);

            return $v;
        });

        return $request->expectsJson() ? response()->json(['ok' => true, 'id' => $visit->id], 201) : redirect()->route('education.rsvps.manage')->with('status', $visit->is_hidden ? 'Schedule added. It is hidden from public RSVP and programme registration.' : 'Schedule added. It is available in programme registration'.($visit->status === 'open' ? ' and on the RSVP page.' : '.'))->with('open_visit', $visit->id);
    }

    public function update(Request $request, EducationVisit $visit)
    {
        $d = $request->validate($this->scheduleRules($request));
        DB::transaction(function () use ($d, $visit) {
            $v = EducationVisit::whereKey($visit->id)->lockForUpdate()->firstOrFail();
            $v->fill($d);
            if ($v->isDirty()) {
                if ($v->isDirty(['event_date', 'event_time', 'timezone', 'location', 'schedule_confirmed', 'city', 'mode']) || ($v->isDirty('status') && in_array('cancelled', [$v->status, $v->getOriginal('status')], true))) {
                    $v->schedule_version++;
                }
                $v->save();
                ActivityLog::record('education.rsvp.schedule_updated', ['portal' => 'education', 'properties' => ['visit_id' => $v->id, 'schedule_version' => $v->schedule_version, 'is_hidden' => $v->is_hidden], 'description' => 'Updated Education RSVP schedule']);
            }
        });

        return $request->expectsJson() ? response()->json(['ok' => true]) : redirect()->route('education.rsvps.manage')->with('status', $visit->fresh()->is_hidden ? 'Schedule saved. Hidden from public RSVP and programme registration; existing attendance records are retained.' : 'Schedule saved. Confirmed changes will be sent to verified RSVP contacts.')->with('open_visit', $visit->id);
    }

    public function attendance(Request $request, EducationRsvp $rsvp)
    {
        $d = $request->validate(['attendance_status' => ['required', Rule::in(['rsvp', 'attended', 'cancelled'])]]);
        $rsvp->update($d);
        ActivityLog::record('education.rsvp.attendance_updated', ['portal' => 'education', 'properties' => ['rsvp_id' => $rsvp->id, 'status' => $d['attendance_status']]]);

        return back()->with('status', 'Attendance updated.');
    }

    public function logo(Request $request, EducationVisit $visit)
    {
        $request->validate(['logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:max_width=4096,max_height=4096']]);
        $path = $request->file('logo')->store('education/school-logos/'.$visit->id, 'public');
        abort_unless($path, 500, 'The logo could not be saved. Please try again.');
        $url = Storage::disk('public')->url($path);

        try {
            DB::transaction(function () use ($visit, $url) {
                EducationVisit::whereKey($visit->id)->lockForUpdate()->firstOrFail()->update(['logo_path' => $url]);
                ActivityLog::record('education.rsvp.logo_updated', ['portal' => 'education', 'properties' => ['visit_id' => $visit->id], 'description' => 'Updated Education school logo']);
            });
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($path);
            throw $e;
        }

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'logo' => $url])
            : redirect()->route('education.rsvps.manage')->with('status', $visit->is_hidden ? 'School logo updated. It will appear publicly when this event is shown again.' : 'School logo updated. It is now shown on the public RSVP page.')->with('open_visit', $visit->id);
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
