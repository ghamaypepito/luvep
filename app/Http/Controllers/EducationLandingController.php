<?php

namespace App\Http\Controllers;

use App\Models\EducationEventRegistration;
use App\Models\Lead;
use App\Models\Setting;
use App\Models\User;
use App\Services\LeadIntakeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

class EducationLandingController extends Controller
{
    public const GOALS = ['Find the right course', 'Plan my next intake', 'Understand the study pathway', 'Help me get started'];

    public const TIMELINES = ['Just exploring', 'Within 3 months', '3–6 months', '6–12 months', '1–2 years'];

    public const LEVELS = ['High School Graduate', 'Associate / Vocational', "Bachelor's Degree", "Master's Degree", 'Doctorate / PhD'];

    public function show(Request $request)
    {
        return response()->view('education.landing', ['registrationVisits' => \App\Models\EducationVisit::publiclyVisible()->where('status', '!=', 'cancelled')->orderBy('school')->orderBy('id')->get()->map->publicData()->values(), 'contact' => $this->contact(), 'goals' => self::GOALS, 'timelines' => self::TIMELINES, 'levels' => self::LEVELS])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, LeadIntakeService $intake)
    {
        $managed = $request->filled('visit_id');
        $data = $request->validate([
            'visit_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'event_location_only' => ['sometimes', 'boolean'],
            'submission_id' => ['required', 'uuid'], 'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'], 'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['required', 'string', 'min:7', 'max:40'], 'goal' => ['required', Rule::in(self::GOALS)],
            'event_name' => [Rule::excludeIf($managed), 'required', 'string', 'max:180'], 'event_date' => [Rule::excludeIf($managed), 'required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:'.now()->addYears(2)->format('Y-m-d')],
            'event_hour' => [Rule::excludeIf($managed), 'required', 'integer', 'between:1,12'], 'event_minute' => [Rule::excludeIf($managed), 'required', 'integer', 'between:0,59'],
            'event_period' => [Rule::excludeIf($managed), 'required', Rule::in(['AM', 'PM'])], 'event_timezone' => [Rule::excludeIf($managed), 'required', Rule::in(\App\Support\EducationTimeZones::identifiers())],
            'country' => ['required', 'string', 'max:100'], 'school' => [Rule::excludeIf($managed), 'required', 'string', 'max:180'],
            'planning_timeline' => ['required', Rule::in(self::TIMELINES)], 'education_level' => ['required', Rule::in(self::LEVELS)],
            'consent' => ['required', 'accepted'], 'website' => ['nullable', 'string', 'max:0'],
        ]);
        $data['email'] = mb_strtolower(trim($data['email']));
        foreach (['first_name', 'last_name', 'event_name', 'country', 'school', 'phone'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = trim($data[$field]);
            }
        }
        if ($managed) {
            $data['visit_id'] = (int) $data['visit_id'];
        }
        if (array_key_exists('event_location_only', $data)) {
            $data['event_location_only'] = (bool) $data['event_location_only'];
        }
        $fingerprint = hash('sha256', json_encode(collect($data)->except(['submission_id', 'website', 'consent'])->sortKeys()->all()));

        return Cache::lock('education-submit:'.$data['submission_id'], 30)->block(5, function () use ($data, $fingerprint, $intake, $request) {
            $prior = EducationEventRegistration::where('submission_id', $data['submission_id'])->first();
            if ($prior) {
                abort_unless(hash_equals($prior->payload_hash, $fingerprint), 409, 'This submission has already been used. Please reload the page.');
                // Only the original submitting session can continue to next actions.
                if ($request->session()->get('education_registration') === $prior->submission_id) {
                    app(\App\Services\EducationRegistrationDelivery::class)->deliver($prior);
                }

                return response()->json(['ok' => true, 'event_summary' => $prior->eventSummary()]);
            }
            $rateKey = 'education-email:'.hash('sha256', $data['email']);
            $registration = Cache::lock('education-email-lock:'.hash('sha256', $data['email']), 15)->block(5, function () use ($data, $fingerprint, $intake, $request, $rateKey) {
                abort_if(RateLimiter::tooManyAttempts($rateKey, 3), 429, 'Please wait before sending another registration.');
                $result = DB::transaction(function () use ($data, $fingerprint, $intake, $request) {
                    if (! empty($data['visit_id'])) {
                        $visit = \App\Models\EducationVisit::publiclyVisible()->whereKey($data['visit_id'])->where('status', '!=', 'cancelled')->lockForUpdate()->first();
                        if (! $visit) {
                            throw \Illuminate\Validation\ValidationException::withMessages(['visit_id' => 'This schedule is no longer available. Choose another event.']);
                        }
                        $details = $visit->publicData();
                        $time = $details['time'] ? \Illuminate\Support\Carbon::parse($details['time']) : null;
                        $data = array_merge($data, ['school' => $visit->school, 'event_name' => $visit->activity, 'event_date' => $details['date'], 'event_hour' => $time ? (int) $time->format('g') : null, 'event_minute' => $time ? (int) $time->format('i') : null, 'event_period' => $time?->format('A'), 'event_timezone' => $visit->timezone]);
                    }

                    $existing = Lead::whereRaw('LOWER(email) = ?', [$data['email']])->exists();
                    // Country of origin is retained on this registration, not guessed into residence/citizenship.
                    $lead = $intake->ingest('education-school-event', collect($data)->only(['first_name', 'last_name', 'email', 'phone'])->all(), $request);
                    if (! $existing && ($owner = $this->owner())) {
                        $lead->update(['assigned_to' => $owner->id]);
                    }
                    $hour = isset($data['event_hour']) ? ((int) $data['event_hour'] % 12) + ($data['event_period'] === 'PM' ? 12 : 0) : null;
                    $registration = EducationEventRegistration::create([
                        'education_visit_id' => $data['visit_id'] ?? null, 'event_match_status' => ! empty($data['visit_id']) ? 'matched' : 'pending',
                        'submission_id' => $data['submission_id'], 'lead_id' => $lead->id, 'payload_hash' => $fingerprint,
                        'event_name' => $data['event_name'], 'event_date' => $data['event_date'],
                        'event_time' => $hour !== null ? sprintf('%02d:%02d', $hour, $data['event_minute']) : null, 'event_timezone' => $data['event_timezone'],
                        'country' => $data['country'], 'school' => $data['school'], 'goal' => $data['goal'],
                        'planning_timeline' => $data['planning_timeline'], 'education_level' => $data['education_level'],
                        'contact_details' => collect($data)->only(['first_name', 'last_name', 'email', 'phone'])->all(), 'consented_at' => now(),
                    ]);
                    if (empty($data['event_location_only'])) {
                        $lead->educationExps()->firstOrCreate(['institution' => $data['school'], 'level' => $data['education_level']]);
                    }
                    $locationLabel = ! empty($data['event_location_only']) ? 'School / business / venue' : 'School / university';
                    if (! $lead->studyPlans()->exists()) {
                        $lead->studyPlans()->create(['preferred_intake' => $data['planning_timeline'], 'preferred_city' => 'New Zealand', 'qualification_level' => $data['education_level']]);
                    }
                    $lead->notes()->create([
                        'author_name' => 'Education event registration', 'author_role' => 'system', 'kind' => 'note',
                        'body' => "School event registration #{$registration->id}\n".$registration->eventSummary()."\nCountry of origin: {$data['country']}\n{$locationLabel}: {$data['school']}\nGoal: {$data['goal']}\nStudy timeline: {$data['planning_timeline']}\nContact consent received.".(! empty($data['visit_id']) ? ' Event selected from the Education schedule.' : ' Event details awaiting team confirmation.'),
                    ]);

                    return $registration;
                });
                RateLimiter::hit($rateKey, 600);

                return $result;
            });
            $request->session()->put('education_registration', $registration->submission_id);
            app(\App\Services\EducationRegistrationDelivery::class)->deliver($registration);

            return response()->json(['ok' => true, 'event_summary' => $registration->eventSummary()], 201);
        });
    }

    public function prepare(Request $request)
    {
        $data = $request->validate(['submission_id' => ['required', 'uuid'], 'questions' => ['required', 'string', 'max:3000']]);
        abort_unless($request->session()->get('education_registration') === $data['submission_id'], 403);

        return DB::transaction(function () use ($data) {
            $r = EducationEventRegistration::where('submission_id', $data['submission_id'])->lockForUpdate()->firstOrFail();
            if ($r->preparation_notes !== $data['questions']) {
                $r->update(['preparation_notes' => $data['questions'], 'prepared_at' => now()]);
                $r->lead->notes()->create(['author_name' => 'Applicant', 'author_role' => 'lead', 'kind' => 'note', 'body' => "Questions for Education registration #{$r->id}:\n".$data['questions']]);
            }

            return response()->json(['ok' => true]);
        });
    }

    private function owner(): ?User
    {
        $id = Setting::get('education_landing_owner_id');
        $user = $id ? User::find($id) : null;

        return $user && $user->canAccessPortal('education') ? $user : null;
    }

    private function contact(): array
    {
        $email = config('services.contact.reply_to') ?: config('services.contact.email');

        return ['email' => $email, 'email_url' => $email ? 'mailto:'.$email : null, 'messenger' => config('services.contact.messenger'), 'phone' => config('services.contact.phone')];
    }
}
