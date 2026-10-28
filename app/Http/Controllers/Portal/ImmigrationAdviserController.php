<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Immigration\CaseProfileController;
use App\Models\CaseAttestation;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use App\Services\Immigration\CaseChecklistService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Licensed Immigration Adviser's portal. Separate from the manager's full
 * immigration portal — scoped to the LIA's own casework: their assigned cases,
 * the sign-off / approval queue (verdict + lodgement, licence-gated), and their
 * profile. Case reads are ROW-SCOPED to the adviser (admins see all).
 */
class ImmigrationAdviserController extends Controller
{
    /** Adviser's own immigration cases (admins see all — for oversight/testing). */
    private function caseScope(User $user)
    {
        $q = Lead::immigrationCase();

        return $user->isAdmin() ? $q : $q->where('current_owner_id', $user->id);
    }

    public function dashboard(Request $request)
    {
        $user = $request->user();

        $cases = (clone $this->caseScope($user))
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get(['id', 'lead_id', 'first_name', 'last_name', 'inz_visa_type', 'inz_status', 'immigration_stage', 'updated_at']);

        $myCaseIds = (clone $this->caseScope($user))->pluck('id');

        // Awaiting the adviser's attestation = a case with no current verdict.
        $pending = CaseAttestation::pendingCountsFor($myCaseIds);

        return inertia('portal/immigration-adviser/Dashboard', [
            'stats' => [
                'my_cases' => $myCaseIds->count(),
                'awaiting_verdict' => $pending['awaiting_verdict'],
                'awaiting_lodgement' => $pending['awaiting_lodgement'],
                'lodged' => (clone $this->caseScope($user))->whereIn('inz_status', ['Lodged', 'Decision Pending', 'Info Requested'])->count(),
            ],
            'recentCases' => $cases->map(fn ($l) => $this->caseRow($l))->values(),
            'licence' => $this->licence($user),
        ]);
    }

    /** All immigration cases — the exact manager "List of Cases" UI/UX, under the
     *  adviser chrome. Reuses the manager's payload builder so nothing drifts. */
    public function cases()
    {
        return inertia('portal/immigration-adviser/Cases', array_merge(
            app(\App\Http\Controllers\Portal\ImmigrationController::class)->casesPayload(),
            ['pageTitle' => 'Cases', 'pageSubtitle' => 'Every immigration case']
        ));
    }

    /** Visa Assessment — the same manager Assessments UI, under adviser chrome. */
    public function assessments()
    {
        return inertia('portal/immigration-adviser/Assessments',
            app(\App\Http\Controllers\Portal\ImmigrationController::class)->assessmentsPayload()
        );
    }

    /** Cases referred to (owned by) this adviser — same UI, scoped to their book. */
    public function myCases(Request $request)
    {
        $user = $request->user();
        $scope = $user->isAdmin() ? null : fn ($q) => $q->where('current_owner_id', $user->id);

        return inertia('portal/immigration-adviser/MyCases',
            app(\App\Http\Controllers\Portal\ImmigrationController::class)->casesPayload($scope)
        );
    }

    /** Reuse the full case profile under the adviser's chrome. The LIA may open
     *  any immigration case (they verify advice-bearing content across the book). */
    public function showCase(Lead $lead, CaseChecklistService $checklist)
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        abort_unless($lead->is_immigration_case, 404);

        return app(CaseProfileController::class)->show($lead, $checklist, 'portal/immigration-adviser/CaseProfile');
    }

    /** Engagement workspace — reuses the manager builder under adviser chrome. */
    public function engagement()
    {
        return app(ImmigrationController::class)->engagement('portal/immigration-adviser/Engagement');
    }

    /** Invoice workspace — reuses the manager builder under adviser chrome. */
    public function invoice()
    {
        return app(ImmigrationController::class)->invoice('portal/immigration-adviser/Invoice');
    }

    /** INZ Forms workspace — reuses the manager builder under adviser chrome. */
    public function inzForms()
    {
        return app(ImmigrationController::class)->inzForms('portal/immigration-adviser/InzForms');
    }

    /** Visas catalogue — the same VisaType manager under adviser chrome. */
    public function visas(\App\Http\Controllers\VisaTypeController $visaTypes)
    {
        return $visaTypes->index('portal/immigration-adviser/VisaTypes');
    }

    /**
     * Verification queue — documents a manager has marked "Checked" (referred to
     * the adviser). The LIA makes the final Approve/Reject call, which is what the
     * client sees. The manager's "Checked" is never shown to the client.
     */
    public function verification(Request $request)
    {
        $docs = LeadDocument::where('status', LeadDocument::STATUS_CHECKED)
            ->whereHas('lead', fn ($q) => $q->immigrationCase())
            ->with(['lead:id,lead_id,first_name,last_name,inz_visa_type', 'reviewer:id,name'])
            ->orderBy('reviewed_at')
            ->get()
            ->map(fn (LeadDocument $d) => [
                'id' => $d->id,
                'original_name' => $d->original_name,
                'checklist_key' => $d->checklist_key,
                'note' => $d->note,
                // The manager who checked & referred this document to the adviser.
                'referred_by' => optional($d->reviewer)->name,
                'mime' => $d->mime,
                'size' => $d->size,
                // A system-generated Visa Information Form referred straight to
                // the adviser — flagged so the queue can badge it distinctly.
                'is_vif' => $d->source_variant === 'vif',
                'checked_at' => optional($d->reviewed_at)->toIso8601String(),
                // Whether the file actually exists on disk — a stale row with no
                // backing file makes the preview 404, so the modal shows a
                // graceful "unavailable" state instead of the raw error page.
                'has_file' => (bool) $d->file_path && (
                    \Illuminate\Support\Facades\Storage::disk('local')->exists($d->file_path)
                    || \Illuminate\Support\Facades\Storage::disk('public')->exists($d->file_path)
                ),
                'view_url' => "/admin/documents/{$d->id}/download?inline=1",
                'download_url' => "/admin/documents/{$d->id}/download",
                'case' => [
                    'id' => $d->lead->id,
                    'lead_id' => $d->lead->lead_id,
                    'name' => trim("{$d->lead->first_name} {$d->lead->last_name}") ?: ($d->lead->lead_id ?: 'Case'),
                    'visa' => $d->lead->inz_visa_type,
                    'case_url' => route('portal.immigration-adviser.cases.show', $d->lead->id),
                ],
            ])->values();

        // The adviser's recent verdicts — a running history of what they've
        // decided, so the queue is never a dead end when it's empty.
        $decided = LeadDocument::whereIn('status', [LeadDocument::STATUS_APPROVED, LeadDocument::STATUS_REJECTED])
            ->where('reviewed_by', $request->user()->id)
            ->whereHas('lead', fn ($q) => $q->immigrationCase())
            ->with('lead:id,lead_id,first_name,last_name')
            ->orderByDesc('reviewed_at')
            ->limit(40)
            ->get()
            ->map(fn (LeadDocument $d) => [
                'id' => $d->id,
                'name' => $d->original_name,
                'checklist_key' => $d->checklist_key,
                'status' => $d->status,
                'note' => $d->note,
                'reviewed_at' => optional($d->reviewed_at)->toIso8601String(),
                'case' => [
                    'id' => $d->lead->id,
                    'lead_id' => $d->lead->lead_id,
                    'name' => trim("{$d->lead->first_name} {$d->lead->last_name}") ?: ($d->lead->lead_id ?: 'Case'),
                ],
            ])->values();

        return inertia('portal/immigration-adviser/Verification', [
            'documents' => $docs,
            'decided' => $decided,
            'licence' => $this->licence($request->user()),
        ]);
    }

    /** The adviser's final Approve/Reject on a checked document. */
    public function verifyDocument(Request $request, LeadDocument $document)
    {
        $user = $request->user();
        abort_unless($document->lead && $document->lead->is_immigration_case, 404);

        $data = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject'])],
            'note' => 'nullable|string|max:500',
        ]);

        $status = $data['action'] === 'approve'
            ? LeadDocument::STATUS_APPROVED
            : LeadDocument::STATUS_REJECTED;

        $document->forceFill([
            'status' => $status,
            'note' => $data['note'] ?: $document->note,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ])->save();

        if ($status === LeadDocument::STATUS_APPROVED
            && \App\Services\GoogleDriveService::isConfigured()) {
            \App\Jobs\PushApprovedDocumentToDrive::dispatch($document->id);
        }

        $document->lead?->recordStaffActivity($status.' '.($document->original_name ?: 'file'));

        return back()->with('success', $status === LeadDocument::STATUS_APPROVED ? 'Document approved.' : 'Document rejected.');
    }

    /**
     * Full-page document review for one case — the referred (Checked) documents,
     * shown one at a time with the manager's referral context and the AI
     * "document vs client record" scan. Replaces the in-queue modal.
     */
    public function review(Request $request, Lead $lead)
    {
        abort_unless($lead->is_immigration_case, 404);

        $docs = LeadDocument::where('lead_id', $lead->id)
            ->where('status', LeadDocument::STATUS_CHECKED)
            ->with('reviewer:id,name')
            ->orderBy('reviewed_at')
            ->get()
            ->map(fn (LeadDocument $d) => [
                'id' => $d->id,
                'original_name' => $d->original_name,
                'checklist_key' => $d->checklist_key,
                'note' => $d->note,
                'referred_by' => optional($d->reviewer)->name,
                'mime' => $d->mime,
                'size' => $d->size,
                'is_vif' => $d->source_variant === 'vif',
                'checked_at' => optional($d->reviewed_at)->toIso8601String(),
                'has_file' => (bool) $d->file_path && (
                    \Illuminate\Support\Facades\Storage::disk('local')->exists($d->file_path)
                    || \Illuminate\Support\Facades\Storage::disk('public')->exists($d->file_path)
                ),
                'view_url' => "/admin/documents/{$d->id}/download?inline=1",
                'download_url' => "/admin/documents/{$d->id}/download",
                'ai_scan_url' => route('portal.immigration-adviser.verification.ai-scan', $d->id),
            ])->values();

        if ($docs->isEmpty()) {
            return redirect()->route('portal.immigration-adviser.verification');
        }

        $ai = app(\App\Services\AIService::class);

        return inertia('portal/immigration-adviser/VerificationReview', [
            'case' => [
                'id' => $lead->id,
                'lead_id' => $lead->lead_id,
                'name' => trim("{$lead->first_name} {$lead->last_name}") ?: ($lead->lead_id ?: 'Case'),
                'visa' => $lead->inz_visa_type,
                'case_url' => route('portal.immigration-adviser.cases.show', $lead->id),
            ],
            'documents' => $docs,
            'licence' => $this->licence($request->user()),
            'ai_enabled' => $ai->isEnabled() && $ai->configured(),
        ]);
    }

    /**
     * AI "Document vs client record" scan for the review page — reads the
     * document and compares extracted identity fields to the case file.
     * Indicative only; the LIA still makes the verdict.
     */
    public function aiScanDocument(Request $request, \App\Services\Immigration\VerificationScanService $scanner, LeadDocument $document)
    {
        abort_unless($document->lead && $document->lead->is_immigration_case, 404);

        $result = $scanner->scan($document);

        // One audit line per AI retrieval, attributable to the adviser.
        \Illuminate\Support\Facades\Log::info('Verification AI scan', [
            'user' => $request->user()?->id,
            'document' => $document->id,
            'ok' => $result['ok'] ?? false,
            'conflicts' => $result['conflicts'] ?? null,
        ]);

        return response()->json($result);
    }

    /** Adviser reports — verification throughput + casework at a glance. */
    public function reports(Request $request)
    {
        $user = $request->user();
        $myIds = (clone $this->caseScope($user))->pluck('id');

        $verified = LeadDocument::whereIn('status', [LeadDocument::STATUS_APPROVED, LeadDocument::STATUS_REJECTED])
            ->where('reviewed_by', $user->id);

        return inertia('portal/immigration-adviser/Reports', [
            'stats' => [
                'total_cases' => Lead::immigrationCase()->count(),
                'my_cases' => $myIds->count(),
                'pending_verification' => LeadDocument::where('status', LeadDocument::STATUS_CHECKED)
                    ->whereHas('lead', fn ($q) => $q->immigrationCase())->count(),
                'approved_by_me' => (clone $verified)->where('status', LeadDocument::STATUS_APPROVED)->count(),
                'rejected_by_me' => (clone $verified)->where('status', LeadDocument::STATUS_REJECTED)->count(),
            ],
            'byStage' => Lead::immigrationCase()
                ->selectRaw('immigration_stage as stage, count(*) as total')
                ->groupBy('immigration_stage')
                ->get()
                ->map(fn ($r) => ['stage' => $r->stage ?: 'Unassigned', 'total' => (int) $r->total])
                ->values(),
            'licence' => $this->licence($user),
        ]);
    }

    /** Cases awaiting the adviser's verdict or lodgement sign-off. */
    public function signOff(Request $request)
    {
        $user = $request->user();

        $cases = (clone $this->caseScope($user))
            ->orderByDesc('updated_at')
            ->limit(300)
            ->get(['id', 'lead_id', 'first_name', 'last_name', 'inz_visa_type', 'inz_status', 'immigration_stage', 'updated_at']);

        $rows = $cases->map(function ($l) {
            $verdict = CaseAttestation::currentVerdict($l->id);

            return array_merge($this->caseRow($l), [
                'verdict' => $verdict?->verdict,
                'has_lodgement_signoff' => CaseAttestation::hasLodgementSignoff($l->id),
                'needs_verdict' => $verdict === null,
            ]);
        })
            // Only those needing something from the adviser.
            ->filter(fn ($r) => $r['needs_verdict'] || ! $r['has_lodgement_signoff'])
            ->values();

        return inertia('portal/immigration-adviser/SignOff', ['cases' => $rows]);
    }

    public function profile(Request $request)
    {
        $user = $request->user();

        return inertia('portal/immigration-adviser/Profile', [
            'adviser' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
            ],
            'licence' => $this->licence($user),
            // The adviser's e-signature — drawn or uploaded, rendered onto the
            // documents they sign. Saved via the shared staff-signature endpoint.
            'signature' => [
                'data_uri' => $user->signatureDataUri(),
                'updated_at' => optional($user->signature_updated_at)?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Leads pipeline — the same shared Leads screen the immigration portal
     * uses, rendered under the adviser layout (page path drives the layout;
     * the portal prop drives the in-page action URLs so everything stays on
     * /portal/immigration-adviser).
     */
    public function leads()
    {
        return app(ImmigrationController::class)
            ->leads('portal/immigration-adviser/Leads', 'immigration-adviser');
    }

    /**
     * Students dashboard — the shared Education students screen. Its component
     * + portal are resolved from the request prefix, so hitting it under
     * /portal/immigration-adviser renders the adviser page/layout.
     */
    public function students()
    {
        return app(\App\Http\Controllers\Portal\EducationController::class)->students();
    }

    /**
     * Client Documents — the same scaffold module as the immigration portal,
     * rendered under the adviser layout.
     */
    public function clientDocuments()
    {
        return app(ImmigrationController::class)
            ->clientDocuments('portal/immigration-adviser/ClientDocuments', 'immigration-adviser');
    }

    /**
     * Cross-portal Task Board for the adviser — the same shared board the
     * immigration portal uses, scoped to the adviser's own tasks or the
     * immigration department set.
     */
    public function tasks(Request $request)
    {
        try {
            $userId = $request->user()->id;
            $scope = $request->input('scope', 'mine');
            $now = now();
            $todayEnd = $now->copy()->endOfDay();
            $weekEnd = $now->copy()->endOfWeek();

            $base = \App\Models\LeadTask::with(['lead:id,lead_id,first_name,last_name,email,status', 'assignee:id,name,avatar_path', 'creator:id,name,avatar_path', 'attachments'])
                ->withCount('comments')
                ->when($scope === 'mine', fn ($q) => $q->where('assignee_id', $userId))
                ->when($scope === 'department', fn ($q) => $q->where('department', 'immigration'));

            $serialize = fn ($t) => [
                'id' => $t->id,
                'title' => $t->title,
                'description' => $t->description,
                'note' => $t->note,
                'comments_count' => (int) ($t->comments_count ?? 0),
                'priority' => $t->priority,
                'progress' => (int) ($t->progress ?? 0),
                'due_at' => $t->due_at,
                'completed' => $t->completed,
                'completed_at' => $t->completed_at,
                'overdue' => ! $t->completed && $t->due_at && $t->due_at->isPast(),
                'type' => $t->type,
                'category' => $t->category,
                'department' => $t->department,
                'tags' => $t->tags,
                'status' => $t->status,
                'completion_notes' => $t->completion_notes,
                'attachments' => $t->attachments->map(fn ($a) => [
                    'id' => $a->id,
                    'url' => $a->url,
                    'original_filename' => $a->original_filename,
                    'is_image' => $a->is_image,
                    'mime_type' => $a->mime_type,
                    'size' => $a->size,
                ])->values(),
                'assignee' => $t->assignee ? ['id' => $t->assignee->id, 'name' => $t->assignee->name, 'avatar_url' => $t->assignee->avatar_url] : null,
                'additional_assignee_ids' => $t->additional_assignee_ids ?? [],
                'additional_lead_ids' => $t->additional_lead_ids ?? [],
                'creator' => $t->creator ? ['id' => $t->creator->id, 'name' => $t->creator->name, 'avatar_url' => $t->creator->avatar_url] : null,
                'lead' => $t->lead ? [
                    'id' => $t->lead->id,
                    'lead_id' => $t->lead->lead_id,
                    'name' => trim("{$t->lead->first_name} {$t->lead->last_name}"),
                    'status' => $t->lead->status,
                ] : null,
            ];

            $allTasks = (clone $base)->orderByDesc('created_at')->limit(1000)->get()->map($serialize);
            $today = (clone $base)->where('completed', false)->whereBetween('due_at', [$now, $todayEnd])->orderBy('due_at')->get()->map($serialize);
            $overdue = (clone $base)->where('completed', false)->whereNotNull('due_at')->where('due_at', '<', $now)->orderBy('due_at')->get()->map($serialize);
            $thisWeek = (clone $base)->where('completed', false)->whereBetween('due_at', [$todayEnd, $weekEnd])->orderBy('due_at')->get()->map($serialize);
            $undated = (clone $base)->where('completed', false)->whereNull('due_at')->orderByDesc('created_at')->limit(50)->get()->map($serialize);
            $recentlyDone = (clone $base)->where('completed', true)->where('completed_at', '>=', $now->copy()->subDays(7))->orderByDesc('completed_at')->limit(50)->get()->map($serialize);

            return inertia('portal/immigration-adviser/Tasks', [
                'portal' => 'immigration-adviser',
                'scope' => $scope,
                'all_tasks' => $allTasks,
                'today' => $today,
                'overdue' => $overdue,
                'this_week' => $thisWeek,
                'undated' => $undated,
                'recently_done' => $recentlyDone,
                'staffOptions' => \App\Models\User::whereNotIn('role', ['lead', 'revoked_lead'])->orderBy('name')->get(['id', 'name', 'role', 'avatar_path']),
                'recent_activity' => \App\Models\ActivityLog::where('action', 'like', 'lead_task.%')
                    ->latest()->limit(30)
                    ->get(['id', 'action', 'description', 'actor_name', 'actor_role', 'properties', 'created_at']),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Adviser tasks page failed', ['error' => $e->getMessage()]);

            return inertia('portal/immigration-adviser/Tasks', ['portal' => 'immigration-adviser', 'scope' => 'mine', 'all_tasks' => [], 'today' => [], 'overdue' => [], 'this_week' => [], 'undated' => [], 'recently_done' => [], 'staffOptions' => []]);
        }
    }

    /** @return array<string, mixed> */
    private function caseRow(Lead $l): array
    {
        return [
            'id' => $l->id,
            'lead_id' => $l->lead_id,
            'name' => trim("{$l->first_name} {$l->last_name}") ?: 'Unknown',
            'visa_type' => $l->inz_visa_type,
            'inz_status' => $l->inz_status,
            'stage' => $l->immigration_stage,
            'updated_at' => optional($l->updated_at)->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function licence(User $user): array
    {
        return [
            'number' => $user->iaa_licence_number,
            'type' => $user->iaa_licence_type,
            'expiry' => optional($user->iaa_licence_expiry)->toDateString(),
            'verified' => optional($user->iaa_licence_verified_at)->toDateString(),
            'current' => $user->holdsCurrentLicence(),
        ];
    }
}
