<?php

namespace App\Http\Controllers\Immigration;

use App\Http\Controllers\Controller;
use App\Models\AgreementTemplate;
use App\Models\Booking;
use App\Models\Installment;
use App\Models\PreScreening;
use App\Models\User;
use App\Services\Immigration\AgreementService;
use App\Services\Immigration\InstallmentPlanService;
use App\Services\Immigration\PreScreeningService;
use Illuminate\Http\Request;

/**
 * Staff actions on a Visa Assessment's pre-screening (potential-client vetting).
 * Procedural — not advice. Eligible = flag as a potential client; Convert (the
 * existing convert-to-case flow) = make it an official case.
 */
class PreScreeningController extends Controller
{
    public function __construct(private PreScreeningService $service) {}

    private function guard(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        abort_unless(
            $user->isAdmin() || $user->role === 'immigration' || in_array($user->role, User::IMMIGRATION_ROLES, true),
            403,
        );

        return $user;
    }

    /** Mark the client acknowledged the consent + key-matters audio. */
    public function consentSent(PreScreening $preScreening)
    {
        $this->guard();
        $this->service->markConsentSent($preScreening);

        return back()->with('success', 'Consent marked as sent.');
    }

    public function consentAck(PreScreening $preScreening)
    {
        $this->guard();
        $this->service->acknowledgeConsent($preScreening);

        return back()->with('success', 'Consent acknowledgment recorded.');
    }

    /** Skip the consultation/consent steps, logged with a reason. */
    public function skip(Request $request, PreScreening $preScreening)
    {
        $user = $this->guard();
        $data = $request->validate(['reason' => 'required|string|max:500']);
        $this->service->skip($preScreening, $user, $data['reason']);

        return back()->with('success', 'Pre-screening steps skipped.');
    }

    /** Flag as an eligible potential client (distinct from converting to a case). */
    public function eligible(PreScreening $preScreening)
    {
        $user = $this->guard();
        $this->service->markEligible($preScreening, $user);

        return back()->with('success', 'Marked as an eligible potential client.');
    }

    public function decline(Request $request, PreScreening $preScreening)
    {
        $user = $this->guard();
        $data = $request->validate(['reason' => 'required|string|max:500']);
        $this->service->decline($preScreening, $user, $data['reason']);

        return back()->with('success', 'Pre-screening declined.');
    }

    /**
     * Staff schedule (or reschedule) one of the pre-screening meetings directly
     * from the profile, instead of waiting for the client to request a time.
     * Staff-scheduled meetings are confirmed, and may be booked for any future
     * date (the client-side 5-day lead time doesn't bind staff).
     */
    public function book(Request $request, PreScreening $preScreening)
    {
        $this->guard();
        $data = $request->validate([
            'meeting' => 'required|in:consult,consent',
            'date' => 'required|date|after_or_equal:' . now()->toDateString(),
            'time' => 'required|string|max:20',
            'note' => 'nullable|string|max:500',
        ]);

        $lead = $this->service->ensureWorkingLead($preScreening);
        $subject = $preScreening->subject;

        $at = null;
        try {
            $at = \Illuminate\Support\Carbon::parse("{$data['date']} {$data['time']}");
        } catch (\Throwable $e) {
            // Leave appointment_at null if the time string doesn't parse.
        }

        $booking = Booking::create([
            'first_name' => $subject->first_name ?? $lead->first_name,
            'last_name' => $subject->last_name ?? $subject->family_name ?? $lead->last_name,
            'email' => $subject->email ?? $lead->email,
            'phone' => $subject->phone ?? $lead->phone,
            'service_type' => $data['meeting'] === 'consult' ? 'Immigration Consultation' : 'Consent Meeting',
            'consultant_name' => 'Immigration Adviser',
            'message' => $data['note'] ?? null,
            'appointment_date' => $data['date'],
            'appointment_time' => $data['time'],
            'appointment_at' => $at,
            'status' => 'confirmed',
            'lead_id' => $lead->id,
        ]);

        if ($data['meeting'] === 'consult') {
            $this->service->setConsultBooking($preScreening, $booking);
        } else {
            $this->service->setConsentBooking($preScreening, $booking);
        }

        return back()->with('success', 'Meeting scheduled.');
    }

    /** Staff set the pay-later terms (deposit % + plan length) → a draft plan. */
    public function setPlan(Request $request, PreScreening $preScreening, InstallmentPlanService $plans)
    {
        $user = $this->guard();
        $data = $request->validate([
            'total_amount' => 'required|numeric|min:0',
            'inz_fee' => 'nullable|numeric|min:0',
            'plan_months' => 'required|integer|min:1|max:24',
            'deposit_percent' => 'required|numeric|min:0|max:100',
        ]);

        $lead = $this->service->ensureWorkingLead($preScreening);
        $plans->draft($lead, $data, $user);

        return back()->with('success', 'Payment plan saved.');
    }

    /** Staff generate + send the engagement agreement for the working lead. */
    public function generateAgreement(PreScreening $preScreening, AgreementService $agreements)
    {
        $this->guard();
        $lead = $this->service->ensureWorkingLead($preScreening);

        // Prefer an active template matching this visa, else any active one.
        $template = AgreementTemplate::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('visa_type')->orWhere('visa_type', $lead->inz_visa_type))
            ->orderByRaw('visa_type IS NULL') // visa-specific first
            ->first()
            ?? AgreementTemplate::where('is_active', true)->first();

        abort_unless($template, 422, 'No active agreement template is configured.');

        try {
            $agreement = $agreements->generate($lead, $template);
            $agreements->send($agreement);
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not generate the agreement: '.$e->getMessage());
        }

        return back()->with('success', 'Engagement agreement sent to the client.');
    }

    /** Staff record one installment as paid (writes a ledger receipt). */
    public function payInstallment(Request $request, PreScreening $preScreening, Installment $installment, InstallmentPlanService $plans)
    {
        $this->guard();
        abort_unless($installment->plan && $installment->plan->lead_id === $preScreening->lead_id, 403);

        $data = $request->validate([
            'method' => 'nullable|string|max:60',
            'reference' => 'nullable|string|max:120',
            'paid_at' => 'nullable|date',
        ]);
        $plans->recordPayment($installment, $data, auth()->user());

        return back()->with('success', 'Payment recorded.');
    }
}
