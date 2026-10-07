<?php

namespace App\Http\Controllers\Immigration;

use App\Http\Controllers\Controller;
use App\Models\PreScreening;
use App\Models\User;
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
}
