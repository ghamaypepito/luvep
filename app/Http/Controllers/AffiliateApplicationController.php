<?php

namespace App\Http\Controllers;

use App\Models\AffiliateApplication;
use App\Models\AgentAgreement;
use App\Services\AffiliateOnboarding;
use App\Services\AgentAgreementService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class AffiliateApplicationController extends Controller
{
    public function form(Request $request)
    {
        return inertia('affiliate/Apply', ['submitted' => (bool) $request->session()->get('application_submitted')]);
    }

    public function store(Request $request, AffiliateOnboarding $onboarding)
    {
        $request->validate(['intent' => 'sometimes|in:save,submit']);
        $draft = $request->input('intent') === 'save';
        $data = $request->validate([
            'name' => 'required|string|max:255', 'email' => 'required|string|email|max:255',
            'phone' => ($draft ? 'nullable' : 'required').'|string|max:60', 'location' => ($draft ? 'nullable' : 'required').'|string|max:255', 'company' => 'nullable|string|max:255', 'consent' => 'required|accepted',
        ]);
        $data['email'] = mb_strtolower(trim($data['email']));
        $data['phone'] = $data['phone'] ?? '';
        $data['location'] = $data['location'] ?? '';
        unset($data['consent']);
        $onboarding->submit($data, $draft);

        return to_route('affiliate.apply')->with('application_submitted', true);
    }

    public function save(Request $request, AffiliateApplication $application, AffiliateOnboarding $onboarding)
    {
        abort_unless($application->verified_at, 403);
        $request->validate(['intent' => 'required|in:save,submit', 'draft_version' => 'required|integer|min:0']);
        $submit = $request->input('intent') === 'submit';
        $data = $request->validate([
            'name' => 'required|string|max:255', 'phone' => ($submit ? 'required' : 'nullable').'|string|max:60',
            'location' => ($submit ? 'required' : 'nullable').'|string|max:255', 'company' => 'nullable|string|max:255',
        ]);
        $data['phone'] = $data['phone'] ?? '';
        $data['location'] = $data['location'] ?? '';
        $onboarding->saveDraft($application, $data, (int) $request->input('draft_version'), $submit);

        return redirect()->to($application->signedUrl('status', null, $this->expiry($request)))->with('success', $submit ? 'Application submitted for review.' : 'Application saved. Return using your private link.');
    }

    public function resend(Request $request, AffiliateOnboarding $onboarding)
    {
        $email = $request->validate(['email' => 'required|string|email|max:255'])['email'];
        $application = AffiliateApplication::where('email', mb_strtolower(trim($email)))->first();
        if ($application) {
            $onboarding->sendStatus($application);
        }

        return to_route('affiliate.apply')->with('application_submitted', true);
    }

    public function verify(Request $request, AffiliateApplication $application)
    {
        AffiliateApplication::whereKey($application->id)->whereNull('verified_at')->update(['verified_at' => now()]);

        return redirect()->to($application->signedUrl('status', null, $this->expiry($request)));
    }

    public function status(Request $request, AffiliateApplication $application)
    {
        $expires = $this->expiry($request);
        // The original continuation link follows progress without granting access
        // to an unsent draft. Signature actions remain pinned to one version.
        if (! $request->query('agreement') && $application->matchesPartner($application->user) && $application->agreement?->sent_at) {
            return redirect()->to($application->signedUrl('status', $application->agreement_id, $expires));
        }
        $agreement = $request->query('agreement') ? $this->selected($request, $application) : null;
        $partner = $application->user;
        $canSign = $agreement && ! $application->activated_at && ! $agreement->isSignedByAgent() && $agreement->canSign();

        return inertia('affiliate/Progress', [
            'application' => ['name' => $application->name, 'status' => $application->status()],
            'draft' => $application->verified_at && ! $application->submitted_at && ! $application->decision ? $application->only(['name', 'email', 'phone', 'location', 'company', 'draft_version']) + [
                'save_url' => $application->signedUrl('save', null, $expires),
            ] : null,
            'verifyUrl' => ! $application->verified_at ? $application->signedUrl('verify', null, $expires) : null,
            'setupUrl' => $application->activated_at && $application->matchesPartner($partner) && $partner->is_active ? route('password.request') : null,
            'agreement' => $agreement ? [
                'id' => $agreement->id, 'version' => $agreement->version, 'status' => $agreement->statusLabel(),
                'agent_signed' => $agreement->isSignedByAgent(), 'company_signed' => $agreement->isSignedByCompany(),
                'expires_on' => $agreement->expires_on?->toDateString(),
                'document_url' => $application->signedUrl('document', $agreement->id, $expires),
                'review_url' => $application->signedUrl('review', $agreement->id, $expires),
                'download_url' => $application->signedUrl('download', $agreement->id, $expires),
                'sign_url' => $canSign ? $application->signedUrl('sign', $agreement->id, $expires) : null,
                'fields' => $canSign && $agreement->canEditDetails() ? AgentAgreementService::affiliateFields() : [],
                'initial' => $canSign && $agreement->canEditDetails() ? collect($agreement->fields)->only(array_column(AgentAgreementService::affiliateFields(), 'key'))->all() : [],
            ] : null,
        ]);
    }

    public function review(Request $request, AffiliateApplication $application, AgentAgreementService $service)
    {
        $agreement = $this->selected($request, $application);

        return response($service->previewHtmlForAgreement($agreement))->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Content-Security-Policy', "sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'");
    }

    public function document(Request $request, AffiliateApplication $application)
    {
        $agreement = $this->selected($request, $application);
        abort_unless(Storage::disk('local')->exists($agreement->file_path), 404);

        return Storage::disk('local')->response($agreement->file_path, $agreement->original_name, ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff'],
            $request->routeIs('affiliate.application.download') ? 'attachment' : 'inline');
    }

    public function sign(Request $request, AffiliateApplication $application, AgentAgreementService $service)
    {
        $agreement = $this->selected($request, $application);
        $keys = array_column(AgentAgreementService::affiliateFields(), 'key');
        $data = $request->validate([
            'agreement_id' => 'required|integer', 'signer_name' => 'required|string|max:200', 'signature_data' => 'required|string|max:5000000', 'terms_accepted' => 'required|accepted',
        ] + array_fill_keys($keys, 'nullable|string|max:500'));
        abort_unless((int) $data['agreement_id'] === $agreement->id, 422, 'Reopen the selected agreement before signing.');
        $service->recordAgentSignature($agreement, $data['signer_name'], $data['signature_data'], $request->ip(), $request->userAgent(), $request->only($keys), $application);

        return redirect()->to($application->signedUrl('status', $agreement->id, $this->expiry($request)));
    }

    private function selected(Request $request, AffiliateApplication $application): AgentAgreement
    {
        $agreement = $application->agreement;
        abort_unless($application->matchesPartner($application->user) && $agreement && (string) $agreement->id === $request->query('agreement')
            && $agreement->agent_id === $application->user_id && ! $agreement->is_draft, 404);
        abort_unless($application->activated_at || ($agreement->isCurrent() && ! $agreement->expires_on?->isBefore(today())), 422, 'This agreement is no longer open. Request a new invitation from EP.');

        return $agreement;
    }

    private function expiry(Request $request): Carbon
    {
        return Carbon::createFromTimestamp((int) $request->query('expires'));
    }
}
