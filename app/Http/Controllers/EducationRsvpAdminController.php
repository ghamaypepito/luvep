<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\EducationOutreach;
use App\Models\EducationRsvp;
use App\Models\EducationVisit;
use App\Models\Setting;
use App\Services\EducationRsvpWorkflow;
use App\Services\ProgramSlackNotifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

        $attendanceOverview = EducationRsvp::query()->selectRaw(
            "COUNT(*) as total, COALESCE(SUM(CASE WHEN attendance_status = 'attended' THEN 1 ELSE 0 END), 0) as attended"
        )->first();

        return response()->view('education.rsvp-admin', $attendanceContext + ['attendanceOverview' => $attendanceOverview, 'attendanceFolders' => \App\Models\EducationRsvpFolder::with('lists')->withCount('rsvps')->orderBy('name')->get(), 'page' => app(\App\Services\EducationRsvpContent::class)->get(), 'contentSchema' => app(\App\Services\EducationRsvpContent::class)->schema(), 'visits' => EducationVisit::withCount('rsvps')->orderBy('id')->get(), 'rsvps' => $rsvps, 'slackReady' => app(ProgramSlackNotifier::class)->configured(), 'pending' => EducationOutreach::whereNull('sent_at')->whereNull('cancelled_at')->count(), 'failed' => EducationOutreach::where('attempts', '>=', 8)->whereNull('sent_at')->count()])->header('Cache-Control', 'private, no-store');
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
            'timezone' => ['required', Rule::in(['Asia/Manila', 'Pacific/Auckland'])],
            'location' => 'nullable|string|max:255', 'schedule_confirmed' => 'required|boolean',
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

        return $request->expectsJson() ? response()->json(['ok' => true, 'id' => $visit->id], 201) : redirect()->route('education.rsvps.manage')->with('status', 'Schedule added. It is available in programme registration'.($visit->status === 'open' ? ' and on the RSVP page.' : '.'))->with('open_visit', $visit->id);
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
                ActivityLog::record('education.rsvp.schedule_updated', ['portal' => 'education', 'properties' => ['visit_id' => $v->id, 'schedule_version' => $v->schedule_version], 'description' => 'Updated Education RSVP schedule']);
            }
        });

        return $request->expectsJson() ? response()->json(['ok' => true]) : redirect()->route('education.rsvps.manage')->with('status', 'Schedule saved. Confirmed changes will be sent to verified RSVP contacts.')->with('open_visit', $visit->id);
    }

    public function answers(Request $request, EducationRsvp $rsvp)
    {
        $data = $request->validate([
            'revision' => 'required|string|size:64',
            'full_name' => 'required|string|max:150', 'email' => 'required|email:rfc|max:254',
            'phone' => 'nullable|string|max:40', 'role' => 'nullable|string|max:100',
            'email_reminders' => 'required|boolean', 'future_invites' => 'required|boolean',
            'email_follow_up' => 'required|boolean', 'email_newsletter' => 'required|boolean',
            'legacy_event_follow_up' => 'sometimes|boolean',
        ]);
        $revision = $data['revision'];
        unset($data['revision']);
        $data['full_name'] = preg_replace('/\s+/u', ' ', trim($data['full_name']));
        $data['email'] = strtolower(trim($data['email']));
        $data['phone'] = trim($data['phone'] ?? '') ?: null;
        $data['role'] = trim($data['role'] ?? '') ?: null;
        $data['name_key'] = EducationRsvpWorkflow::nameKey($data['full_name']);
        if (! $data['name_key']) {
            throw ValidationException::withMessages(['full_name' => 'Please enter a name.']);
        }

        try {
            DB::transaction(function () use ($rsvp, $data, $revision) {
                $current = EducationRsvp::whereKey($rsvp->id)->lockForUpdate()->firstOrFail();
                if (! hash_equals($current->answersRevision(), $revision)) {
                    throw ValidationException::withMessages(['revision' => 'This RSVP changed while you were editing. Reload the page and review the latest answers before saving.']);
                }
                if (array_key_exists('legacy_event_follow_up', $data) && $current->legacy_event_follow_up === null) {
                    throw ValidationException::withMessages(['legacy_event_follow_up' => 'This RSVP has no recorded legacy event-contact permission.']);
                }
                if (EducationRsvp::where('education_visit_id', $current->education_visit_id)->whereRaw('LOWER(TRIM(email)) = ?', [$data['email']])->whereKeyNot($current->id)->exists()) {
                    throw ValidationException::withMessages(['email' => 'This email already has an RSVP for this event. Edit that RSVP instead.']);
                }
                $before = $current->only([...array_keys($data), 'legacy_event_follow_up']);
                $identityChanged = $data['email'] !== strtolower(trim($current->email)) || $data['name_key'] !== EducationRsvpWorkflow::nameKey($current->full_name);
                $current->fill($data);
                if (! $current->isDirty()) {
                    return;
                }
                if ($identityChanged) {
                    // A signed link for the original identity must not verify the corrected address.
                    $current->fill(['public_id' => (string) Str::uuid(), 'verified_at' => null, 'lead_id' => null, 'linked_at' => null]);
                    if ($current->legacy_event_follow_up !== null) {
                        $current->legacy_event_follow_up = null;
                    }
                    EducationOutreach::where('education_rsvp_id', $current->id)->whereNull('sent_at')->whereNull('cancelled_at')->update(['cancelled_at' => now()]);
                }
                $current->save();
                ActivityLog::record('education.rsvp.answers_updated', [
                    'portal' => 'education', 'entity_type' => EducationRsvp::class, 'entity_id' => $current->id,
                    'description' => 'Corrected RSVP answers',
                    'changes' => ['before' => $before, 'after' => $current->only(array_keys($before))],
                    'properties' => ['rsvp_id' => $current->id, 'identity_changed' => $identityChanged],
                ]);
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['email' => 'This email already has an RSVP for this event. Reload and review the existing RSVP.']);
        }

        return back()->with('status', 'RSVP answers saved. No email was queued.');
    }

    public function confirmation(Request $request, EducationRsvp $rsvp, EducationRsvpWorkflow $workflow)
    {
        $data = $request->validate(['revision' => 'required|string|size:64']);
        $created = DB::transaction(function () use ($data, $rsvp, $workflow) {
            $current = EducationRsvp::whereKey($rsvp->id)->lockForUpdate()->firstOrFail();
            if (! hash_equals($current->answersRevision(), $data['revision'])) {
                throw ValidationException::withMessages(['revision' => 'This RSVP changed. Reload and check the email address before requesting a verification email.']);
            }
            abort_unless(! $current->verified_at && app(\App\Services\EducationRsvpContactUpdate::class)->contactAllowed($current), 422);
            $prefix = 'rsvp:'.$current->id.':staff-confirmation:'.$current->public_id;
            $prior = EducationOutreach::where('event_key', 'like', $prefix.'%')->latest('id')->first();
            if ($prior && ! $prior->cancelled_at && ($prior->sent_at ? $prior->sent_at->gt(now()->subDays(30)) : $prior->attempts < 8)) {
                return false;
            }
            $delivery = $workflow->receipt($prefix.':'.Str::uuid(), ['education_rsvp_id' => $current->id, 'channel' => 'mail', 'kind' => 'confirmation']);
            EducationOutreach::where('education_rsvp_id', $current->id)->where('kind', 'confirmation')->whereKeyNot($delivery->id)->whereNull('sent_at')->whereNull('cancelled_at')->update(['cancelled_at' => now()]);
            ActivityLog::record('education.rsvp.confirmation_requested', ['portal' => 'education', 'entity_type' => EducationRsvp::class, 'entity_id' => $current->id]);

            return true;
        });

        return back()->with('status', $created ? 'Verification email queued for the current RSVP address.' : 'A verification email has already been requested for these details.');
    }

    public function attendance(Request $request, EducationRsvp $rsvp)
    {
        $d = $request->validate(['attendance_status' => ['required', Rule::in(['rsvp', 'attended', 'cancelled'])]]);
        EducationRsvp::whereKey($rsvp->id)->update($d + ['attendance_source' => 'manual']);
        ActivityLog::record('education.rsvp.attendance_updated', ['portal' => 'education', 'properties' => ['rsvp_id' => $rsvp->id, 'status' => $d['attendance_status']]]);

        return back()->with('status', 'Attendance updated.');
    }

    public function bulkAttendance(Request $request)
    {
        $data = $request->validate([
            'rsvp_ids' => ['required', 'array', 'min:1', 'max:100'],
            'rsvp_ids.*' => ['required', 'integer', 'distinct', 'exists:education_rsvps,id'],
        ]);

        $updated = DB::transaction(function () use ($data) {
            $ids = EducationRsvp::whereKey($data['rsvp_ids'])
                ->where('attendance_status', 'rsvp')
                ->whereHas('visit', fn ($visit) => $visit->where('status', '!=', 'cancelled'))
                ->orderBy('id')->lockForUpdate()->pluck('id');

            if ($ids->isEmpty()) {
                return 0;
            }

            $updated = EducationRsvp::whereKey($ids->all())
                ->update(['attendance_status' => 'attended', 'attendance_source' => 'manual']);
            ActivityLog::record('education.rsvp.attendance_bulk_updated', [
                'portal' => 'education',
                'properties' => ['rsvp_ids' => $ids->all(), 'status' => 'attended', 'updated' => $updated],
            ]);

            return $updated;
        });

        if ($request->expectsJson()) {
            return response()->json(['updated' => $updated]);
        }

        return back()->with('status', $updated
            ? trans_choice('Marked :count selected RSVP as Attended.|Marked :count selected RSVPs as Attended.', $updated)
            : 'The selected RSVPs already have a final status or belong to a cancelled event.');
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
            : redirect()->route('education.rsvps.manage')->with('status', 'School logo updated. It is now shown on the public RSVP page.')->with('open_visit', $visit->id);
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
