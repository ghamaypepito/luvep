<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgentAgreement;
use App\Models\Lead;
use App\Models\User;
use App\Services\AgentAgreementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Agents module — a dedicated surface (gated by `module:agents`) to manage
 * referral agents, view their leads, and generate/store their Referral Agent
 * Agreement. Agents are Users with role=agent.
 */
class AgentModuleController extends Controller
{
    public function index(Request $request)
    {
        return inertia('admin/agents/Index', app(\App\Services\AffiliatePerformance::class)->report($request));
    }

    public function show(Request $request, User $agent, AgentAgreementService $service)
    {
        abort_unless($agent->role === 'agent', 404);

        $leads = Lead::where('agent_id', $agent->id)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get(['id', 'lead_id', 'first_name', 'last_name', 'status', 'education_stage', 'english_stage', 'immigration_stage', 'is_student', 'is_english_student', 'is_immigration_case', 'email', 'phone', 'residence_city', 'residence_country', 'country', 'created_at'])
            ->map(fn (Lead $l) => [
                'id' => $l->id,
                'lead_id' => $l->lead_id,
                'name' => trim("{$l->first_name} {$l->last_name}") ?: '—',
                // Show the department stage the lead has actually moved to
                // (e.g. education "Started Course") rather than the stale sales
                // status — matches the commission counter, which keys on
                // education_stage.
                'status' => $l->education_stage
                    ?: ($l->english_stage
                    ?: (($l->is_immigration_case ? $l->immigration_stage : null)
                    ?: $l->status)),
                'email' => $l->email,
                'phone' => $l->phone,
                // Combined "Location" label — city + country when present.
                'location' => trim(implode(', ', array_filter([
                    $l->residence_city,
                    $l->residence_country ?: $l->country,
                ]))) ?: null,
                'created_at' => optional($l->created_at)?->toIso8601String(),
            ]);

        $agreement = AgentAgreement::where('agent_id', $agent->id)->latest()->first();

        // Count the agent's recruited leads by their effective stage (same
        // resolution the table/commission use), over ALL their leads — not just
        // the capped 200 shown in the table — so the cards are accurate.
        $statusCounts = Lead::where('agent_id', $agent->id)
            ->get(['status', 'education_stage', 'english_stage', 'immigration_stage', 'is_immigration_case'])
            ->map(fn (Lead $l) => $l->education_stage
                ?: ($l->english_stage
                ?: (($l->is_immigration_case ? $l->immigration_stage : null)
                ?: $l->status)))
            ->filter(fn ($s) => filled($s))
            ->countBy()
            ->sortDesc()
            ->map(fn ($count, $status) => ['status' => $status, 'count' => $count])
            ->values();

        return inertia('admin/agents/Show', [
            'commission' => $this->commissionSummary($agent, $agreement),
            'statusCounts' => $statusCounts,
            'agent' => [
                'id' => $agent->id,
                'name' => $agent->name,
                'email' => $agent->email,
                'phone' => $agent->phone,
                'location' => $agent->location,
                'avatar_url' => $agent->avatar_url,
                'referral_code' => $agent->referral_code,
            ],
            'leads' => $leads,
            'leadsCount' => $leads->count(),
            'agreement' => $agreement ? [
                'id' => $agreement->id,
                'original_name' => $agreement->original_name,
                'size' => $agreement->size,
                'fields' => $agreement->fields,
                'created_at' => optional($agreement->created_at)?->toIso8601String(),
                'download_url' => "/admin/agents/{$agent->id}/agreement/download",
                'view_url' => "/admin/agents/{$agent->id}/agreement/view",
                'sign_url' => "/admin/agents/{$agent->id}/agreement/sign",
                'agent_signed' => $agreement->isSignedByAgent(),
                'agent_signer_name' => $agreement->agent_signer_name,
                'agent_signed_at' => optional($agreement->agent_signed_at)?->toIso8601String(),
                'company_signed' => $agreement->isSignedByCompany(),
                'company_signer_name' => $agreement->company_signer_name,
                'company_signed_at' => optional($agreement->company_signed_at)?->toIso8601String(),
            ] : null,
            'agreementFieldGroups' => AgentAgreementService::fieldGroups(),
            'agreementDefaults' => $agreement->fields ?? $service->defaultFields($agent),
            'previewBase' => "/admin/agents/{$agent->id}/agreement/preview",
        ]);
    }

    /**
     * Commission dashboard for an agent: how many of their referred students
     * have started their course (education_stage = 'Started Course'), which
     * Schedule A tier that qualifies for (1–5 vs 6+), and the resulting
     * per-student rate + estimated total — all read from the signed/generated
     * agreement's editable fields (blank until an agreement exists).
     */
    private function commissionSummary(User $agent, ?AgentAgreement $agreement): array
    {
        // Qualifying students = the agent's referred leads who have started
        // their course. Each one counts as 1 toward the commission.
        $qualifying = Lead::where('agent_id', $agent->id)
            ->where('education_stage', 'Started Course')
            ->count();

        $fields = is_array($agreement?->fields) ? $agreement->fields : [];
        // Parse a "20,000" style string to a number; non-numeric (e.g.
        // "Negotiable") yields null so the card shows the text as-is.
        $num = function ($s) {
            $clean = preg_replace('/[^\d.]/', '', (string) $s);

            return $clean === '' ? null : (float) $clean;
        };

        // Tier is set by the TOTAL count: 6+ qualifying students moves every
        // qualifying student to the higher New Zealand rate (per Schedule A).
        $tier = $qualifying >= 6 ? '6plus' : '1_5';

        $tiers = [
            [
                'key' => '1_5',
                'label' => 'New Zealand — 1 to 5 students',
                'percent' => trim((string) ($fields['nz_1_5_percent'] ?? '')),
                'amount' => trim((string) ($fields['nz_1_5_amount'] ?? '')),
                'amount_num' => $num($fields['nz_1_5_amount'] ?? ''),
                'active' => $tier === '1_5',
            ],
            [
                'key' => '6plus',
                'label' => 'New Zealand — 6 or more students',
                'percent' => trim((string) ($fields['nz_6plus_percent'] ?? '')),
                'amount' => trim((string) ($fields['nz_6plus_amount'] ?? '')),
                'amount_num' => $num($fields['nz_6plus_amount'] ?? ''),
                'active' => $tier === '6plus',
            ],
        ];

        $activeAmount = $tier === '6plus' ? ($tiers[1]['amount_num']) : ($tiers[0]['amount_num']);
        $total = $activeAmount !== null ? $activeAmount * $qualifying : null;

        return [
            'has_agreement' => $agreement !== null,
            'qualifying' => $qualifying,
            'tier' => $tier,
            'currency' => trim((string) ($fields['currency'] ?? '')),
            'tiers' => $tiers,
            'per_student_amount' => $activeAmount,
            'total' => $total,
        ];
    }

    /** Live HTML preview of the agreement with the current field values. */
    public function previewAgreement(Request $request, User $agent, AgentAgreementService $service)
    {
        abort_unless($agent->role === 'agent', 404);

        $fields = $request->only(AgentAgreementService::fieldKeys());
        $html = $service->renderHtml($agent, $fields);

        return response($html)->header('Content-Type', 'text/html; charset=utf-8');
    }

    public function generateAgreement(Request $request, User $agent, AgentAgreementService $service)
    {
        abort_unless($agent->role === 'agent', 404);

        $validated = $request->validate(
            collect(AgentAgreementService::fieldKeys())
                ->mapWithKeys(fn ($key) => [$key => ['nullable', 'string', 'max:500']])
                ->all()
        );

        $service->generate($agent, $validated);

        return back()->with('success', "Affiliate Partner Agreement generated for {$agent->name}.");
    }

    public function downloadAgreement(Request $request, User $agent)
    {
        abort_unless($agent->role === 'agent', 404);

        $agreement = AgentAgreement::where('agent_id', $agent->id)->latest()->firstOrFail();

        abort_unless(Storage::disk('local')->exists($agreement->file_path), 404);

        return Storage::disk('local')->download($agreement->file_path, $agreement->original_name);
    }

    /** Stream the agreement PDF inline (viewable in the browser, no download). */
    public function viewAgreement(Request $request, User $agent)
    {
        abort_unless($agent->role === 'agent', 404);

        $agreement = AgentAgreement::where('agent_id', $agent->id)->latest()->firstOrFail();
        abort_unless(Storage::disk('local')->exists($agreement->file_path), 404);

        return response()->file(Storage::disk('local')->path($agreement->file_path), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$agreement->original_name.'"',
        ]);
    }

    /** Staff signs the "For ePathways" side — same draw/upload capture. */
    public function signAgreement(Request $request, User $agent, AgentAgreementService $service)
    {
        abort_unless($agent->role === 'agent', 404);

        $validated = $request->validate([
            'signer_name' => ['required', 'string', 'max:200'],
            'signature_data' => ['required', 'string', 'max:5000000'],
        ]);

        $agreement = AgentAgreement::where('agent_id', $agent->id)->latest()->firstOrFail();
        abort_if($agreement->isSignedByCompany(), 422, 'The ePathways side is already signed.');

        $service->recordCompanySignature($agreement, trim($validated['signer_name']), $validated['signature_data']);

        return back()->with('success', 'Agreement signed for ePathways.');
    }
}
