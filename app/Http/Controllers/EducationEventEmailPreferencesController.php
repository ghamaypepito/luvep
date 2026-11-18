<?php

namespace App\Http\Controllers;

use App\Models\EducationEventEmailSubscription;
use App\Models\EducationEventRegistration;
use App\Models\EducationRsvp;
use App\Models\Lead;
use App\Services\EducationRsvpWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EducationEventEmailPreferencesController extends Controller
{
    public function show(EducationEventRegistration $registration)
    {
        $details = $registration->contact_details;
        $rsvp = $registration->education_visit_id
            ? EducationRsvp::where('lead_id', $registration->lead_id)
                ->where('education_visit_id', $registration->education_visit_id)
                ->where('email', mb_strtolower(trim($details['email'])))
                ->where('name_key', EducationRsvpWorkflow::nameKey(($details['first_name'] ?? '').' '.($details['last_name'] ?? '')))
                ->whereNotNull('verified_at')
                ->where('attendance_status', '!=', 'cancelled')
                ->first()
            : null;

        return $this->page(false, [
            'follow_up' => $registration->email_follow_up || $rsvp?->email_follow_up,
            'newsletter' => $registration->email_newsletter || $rsvp?->email_newsletter,
        ]);
    }

    public function store(Request $request, EducationEventRegistration $registration)
    {
        $request->validate([
            'follow_up' => ['sometimes', 'accepted'],
            'newsletter' => ['sometimes', 'accepted'],
        ]);

        if (! $request->boolean('follow_up') && ! $request->boolean('newsletter')) {
            throw ValidationException::withMessages(['follow_up' => 'Choose at least one type of email.']);
        }

        DB::transaction(function () use ($request, $registration) {
            $lead = Lead::query()->lockForUpdate()->findOrFail($registration->lead_id);
            $email = mb_strtolower(trim($registration->contact_details['email']));
            abort_unless(mb_strtolower(trim((string) $lead->email)) === $email, 409);

            $subscription = EducationEventEmailSubscription::firstOrNew(['lead_id' => $lead->id]);
            abort_if($subscription->exists && $subscription->email !== $email, 409);
            $subscription->email = $email;
            $subscription->registration_id ??= $registration->id;
            if ($request->boolean('follow_up') && ! $subscription->follow_up_confirmed_at) {
                $subscription->registration_id = $registration->id;
                $subscription->follow_up_confirmed_at = now();
            }
            if ($request->boolean('newsletter')) {
                $subscription->newsletter_confirmed_at ??= now();
            }
            if ($subscription->follow_up_confirmed_at || $subscription->newsletter_confirmed_at) {
                $subscription->save();
            }
        });

        return $this->page(true);
    }

    private function page(bool $saved, array $choices = [])
    {
        return response()->view('education.event-email-preferences', ['saved' => $saved, 'choices' => $choices])
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
