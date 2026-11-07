<?php

namespace App\Http\Controllers;

use App\Models\EducationEventEmailSubscription;
use App\Models\EducationEventRegistration;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EducationEventEmailPreferencesController extends Controller
{
    public function show()
    {
        return $this->page(false);
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

    private function page(bool $saved)
    {
        return response()->view('education.event-email-preferences', ['saved' => $saved])
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
