<?php

namespace App\Http\Controllers\Immigration;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\PotentialCase;
use App\Models\User;
use App\Services\Immigration\PotentialCaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

/**
 * Potential Cases — the onboarding/vetting pipeline before an official case.
 * Procedural compliance tracking (two consultations + key-matters audio gate
 * the written agreement); the gate override is licence-gated in the service.
 */
class PotentialCaseController extends Controller
{
    public function __construct(private PotentialCaseService $service) {}

    private function guard(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        abort_unless(
            $user->isAdmin()
                || $user->role === 'immigration'
                || in_array($user->role, User::IMMIGRATION_ROLES, true),
            403,
        );

        return $user;
    }

    public function index()
    {
        $this->guard();

        $rows = PotentialCase::with('lead:id,lead_id,first_name,last_name,email,phone')
            ->latest()
            ->get()
            ->map(fn (PotentialCase $pc) => array_merge($this->service->detail($pc), [
                'name' => trim("{$pc->lead?->first_name} {$pc->lead?->last_name}") ?: ($pc->lead?->lead_id ?? 'Unknown'),
                'lead_ref' => $pc->lead?->lead_id,
                'email' => $pc->lead?->email,
                'phone' => $pc->lead?->phone,
                'created_at' => optional($pc->created_at)->toIso8601String(),
            ]));

        // Immigration staff for the consultation attendee picker.
        $staff = User::whereIn('role', array_merge(['immigration', 'admin'], User::IMMIGRATION_ROLES))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values();

        // Leads that can still be marked as potential cases: not already an
        // official case and not already a potential case.
        $taken = PotentialCase::pluck('lead_id');
        $eligible = Lead::where('is_immigration_case', false)
            ->whereNotIn('id', $taken)
            ->latest()
            ->limit(200)
            ->get(['id', 'lead_id', 'first_name', 'last_name', 'email'])
            ->map(fn ($l) => [
                'id' => $l->id,
                'label' => trim("{$l->first_name} {$l->last_name}").($l->lead_id ? " ({$l->lead_id})" : '').($l->email ? " · {$l->email}" : ''),
            ])->values();

        return Inertia::render('portal/immigration/PotentialCases', [
            'potentialCases' => $rows,
            'staff' => $staff,
            'eligibleLeads' => $eligible,
            // Whether the current user can override the consultation gate.
            'canOverride' => (bool) optional(auth()->user())->holdsCurrentLicence(),
        ]);
    }

    /** Mark a lead as a potential case (from the leads list). */
    public function store(Request $request)
    {
        $user = $this->guard();
        $data = $request->validate(['lead_id' => 'required|exists:leads,id']);

        $lead = Lead::findOrFail($data['lead_id']);
        abort_if($lead->is_immigration_case, 422, 'This lead is already an official case.');

        $this->service->createFor($lead, $user);

        return back()->with('success', 'Added to Potential Cases.');
    }

    public function logMeeting(Request $request, PotentialCase $potentialCase)
    {
        $user = $this->guard();
        $data = $request->validate([
            'meeting_no' => 'required|integer|in:1,2',
            'at' => 'required|date',
            'note' => 'nullable|string|max:2000',
            'staff' => 'required|array|min:1',
            'staff.*.name' => 'required|string|max:120',
            'staff.*.id' => 'nullable|integer',
        ]);

        $this->service->logMeeting($potentialCase, (int) $data['meeting_no'], Carbon::parse($data['at']), $data['staff'], $data['note'] ?? null, $user);

        return back()->with('success', 'Consultation logged.');
    }

    public function acknowledgeAudio(PotentialCase $potentialCase)
    {
        $user = $this->guard();
        $this->service->acknowledgeAudio($potentialCase, $user);

        return back()->with('success', 'Key-matters acknowledgment recorded.');
    }

    public function markAgreementSent(PotentialCase $potentialCase)
    {
        $this->guard();
        $this->service->markAgreementSent($potentialCase);

        return back()->with('success', 'Written agreement marked as sent.');
    }

    public function sign(PotentialCase $potentialCase)
    {
        $this->guard();
        $this->service->sign($potentialCase);

        return back()->with('success', 'Written agreement signed.');
    }

    public function override(Request $request, PotentialCase $potentialCase)
    {
        $user = $this->guard();
        $data = $request->validate(['reason' => 'required|string|max:500']);

        $this->service->override($potentialCase, $user, $data['reason']);

        return back()->with('success', 'Consultation gate overridden.');
    }

    public function promote(PotentialCase $potentialCase)
    {
        $user = $this->guard();
        $this->service->promote($potentialCase, $user);

        return redirect("/portal/immigration/cases/{$potentialCase->lead_id}/profile")
            ->with('success', 'Promoted to an official case.');
    }

    public function decline(Request $request, PotentialCase $potentialCase)
    {
        $user = $this->guard();
        $data = $request->validate(['reason' => 'required|string|max:500']);

        $this->service->decline($potentialCase, $user, $data['reason']);

        return back()->with('success', 'Potential case declined.');
    }
}
