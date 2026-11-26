<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AffiliateApplication;
use App\Services\AffiliateOnboarding;
use Illuminate\Http\Request;

class AffiliateApplicationController extends Controller
{
    public function index()
    {
        return inertia('admin/agents/Applications', [
            'applications' => AffiliateApplication::latest('id')->paginate(25)->through(fn ($application) => [
                'id' => $application->id, 'name' => $application->name, 'email' => $application->email, 'location' => $application->location,
                'status' => $application->status(), 'submitted_at' => $application->submitted_at?->toIso8601String(),
                'detail_url' => route('admin.agents.applications.show', $application),
            ]),
        ]);
    }

    public function show(AffiliateApplication $application)
    {
        $reviewable = $application->verified_at && $application->submitted_at && ! $application->decision;
        $partner = $application->user()->withTrashed()->first();

        return inertia('admin/agents/Application', [
            'application' => $application->only(['name', 'email', 'phone', 'location', 'company', 'created_at', 'submitted_at', 'verified_at', 'reviewed_at', 'activated_at']) + [
                'status' => $application->status(), 'reviewer' => \App\Models\User::withTrashed()->whereKey($application->reviewed_by)->value('name'),
                'partner_url' => $partner?->role === 'agent' ? route('admin.agents.show', $partner) : null,
                'agreement_version' => $application->agreement?->version,
                'approve_url' => $reviewable ? route('admin.agents.applications.approve', $application) : null,
                'decline_url' => $reviewable ? route('admin.agents.applications.decline', $application) : null,
            ],
        ]);
    }

    public function approve(Request $request, AffiliateApplication $application, AffiliateOnboarding $onboarding)
    {
        $onboarding->review($application, $request->user(), true);

        return to_route('admin.agents.show', $application->fresh()->user_id)->with('success', 'Application approved. Prepare and send the affiliate agreement.');
    }

    public function decline(Request $request, AffiliateApplication $application, AffiliateOnboarding $onboarding)
    {
        $onboarding->review($application, $request->user(), false);

        return to_route('admin.agents.applications.show', $application)->with('success', 'Application declined.');
    }
}
