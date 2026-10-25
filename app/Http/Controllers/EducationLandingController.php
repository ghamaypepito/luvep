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
        return response()->view('education.landing', ['contact' => $this->contact(), 'goals' => self::GOALS, 'timelines' => self::TIMELINES, 'levels' => self::LEVELS])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, LeadIntakeService $intake)
    {
        $data = $request->validate([
            'submission_id' => ['required', 'uuid'], 'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'], 'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['required', 'string', 'min:7', 'max:40'], 'goal' => ['required', Rule::in(self::GOALS)],
            'event_name' => ['required', 'string', 'max:180'], 'event_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:'.now()->addYears(2)->format('Y-m-d')],
            'event_hour' => ['required', 'integer', 'between:1,12'], 'event_minute' => ['required', 'integer', 'between:0,59'],
            'event_period' => ['required', Rule::in(['AM', 'PM'])], 'event_timezone' => ['required', Rule::in(['Asia/Manila', 'Pacific/Auckland'])],
            'country' => ['required', 'string', 'max:100'], 'school' => ['required', 'string', 'max:180'],
            'planning_timeline' => ['required', Rule::in(self::TIMELINES)], 'education_level' => ['required', Rule::in(self::LEVELS)],
            'consent' => ['required', 'accepted'], 'website' => ['nullable', 'string', 'max:0'],
        ]);
        $data['email'] = mb_strtolower(trim($data['email']));
        foreach (['first_name', 'last_name', 'event_name', 'country', 'school', 'phone'] as $field) {
            $data[$field] = trim($data[$field]);
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

                return response()->json(['ok' => true]);
            }
            $rateKey = 'education-email:'.hash('sha256', $data['email']);
            $registration = Cache::lock('education-email-lock:'.hash('sha256', $data['email']), 15)->block(5, function () use ($data, $fingerprint, $intake, $request, $rateKey) {
                abort_if(RateLimiter::tooManyAttempts($rateKey, 3), 429, 'Please wait before sending another registration.');
                $result = DB::transaction(function () use ($data, $fingerprint, $intake, $request) {
                    $existing = Lead::whereRaw('LOWER(email) = ?', [$data['email']])->exists();
                    // Country of origin is retained on this registration, not guessed into residence/citizenship.
                    $lead = $intake->ingest('education-school-event', collect($data)->only(['first_name', 'last_name', 'email', 'phone'])->all(), $request);
                    if (! $existing && ($owner = $this->owner())) {
                        $lead->update(['assigned_to' => $owner->id]);
                    }
                    $hour = ((int) $data['event_hour'] % 12) + ($data['event_period'] === 'PM' ? 12 : 0);
                    $registration = EducationEventRegistration::create([
                        'submission_id' => $data['submission_id'], 'lead_id' => $lead->id, 'payload_hash' => $fingerprint,
                        'event_name' => $data['event_name'], 'event_date' => $data['event_date'],
                        'event_time' => sprintf('%02d:%02d', $hour, $data['event_minute']), 'event_timezone' => $data['event_timezone'],
                        'country' => $data['country'], 'school' => $data['school'], 'goal' => $data['goal'],
                        'planning_timeline' => $data['planning_timeline'], 'education_level' => $data['education_level'],
                        'contact_details' => collect($data)->only(['first_name', 'last_name', 'email', 'phone'])->all(), 'consented_at' => now(),
                    ]);
                    $lead->educationExps()->firstOrCreate(['institution' => $data['school'], 'level' => $data['education_level']]);
                    if (! $lead->studyPlans()->exists()) {
                        $lead->studyPlans()->create(['preferred_intake' => $data['planning_timeline'], 'preferred_city' => 'New Zealand', 'qualification_level' => $data['education_level']]);
                    }
                    $lead->notes()->create([
                        'author_name' => 'Education event registration', 'author_role' => 'system', 'kind' => 'note',
                        'body' => "School event registration #{$registration->id}\n".$registration->eventSummary()."\nCountry of origin: {$data['country']}\nSchool / university: {$data['school']}\nGoal: {$data['goal']}\nStudy timeline: {$data['planning_timeline']}\nContact consent received. Event details awaiting team confirmation.",
                    ]);

                    return $registration;
                });
                RateLimiter::hit($rateKey, 600);

                return $result;
            });
            $request->session()->put('education_registration', $registration->submission_id);
            app(\App\Services\EducationRegistrationDelivery::class)->deliver($registration);

            return response()->json(['ok' => true], 201);
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
