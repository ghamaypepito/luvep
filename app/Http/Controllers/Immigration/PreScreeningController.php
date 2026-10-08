<?php

namespace App\Http\Controllers\Immigration;

use App\Http\Controllers\Controller;
use App\Models\Installment;
use App\Models\PreScreening;
use App\Models\User;
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
