<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Program;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Program Verification — a restricted module (module:program_verification,
 * default super-admin-only, grantable per user). Staff submit a Study Proposal
 * "for verification"; here it is reviewed — programs checked, fees/schools/
 * intakes confirmed — then Verify → Approve. Only on approval does the proposal
 * become visible on the client's tracker and the client email fire.
 */
class ProgramVerificationController extends Controller
{
    /** Checklist keys that count as a "transcript" on file. */
    private const TRANSCRIPT_KEYS = ['transcript', 'diploma', 'academic_transcript'];

    // ── Read ────────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $data = $request->validate(['status' => 'nullable|in:pending,verified,approved', 'page' => 'nullable|integer|min:1', 'lead_id' => 'nullable|integer|min:1']);
        $status = $data['status'] ?? 'pending';
        $base = Lead::query()->whereNotNull('proposed_program_ids');
        $selectedLead = isset($data['lead_id']) ? (clone $base)->whereIn('proposal_review->status', ['pending', 'verified', 'approved'])->findOrFail($data['lead_id']) : null;
        if ($selectedLead) {
            $status = $selectedLead->proposal_review['status'];
        }
        $counts = [];
        foreach (['pending', 'verified', 'approved'] as $state) {
            $counts[$state] = (clone $base)->where('proposal_review->status', $state)->count();
        }
        $counts['approved_today'] = (clone $base)->where('proposal_review->approved_at', '>=', now()->startOfDay()->toIso8601String())->count();
        $page = (clone $base)->where('proposal_review->status', $status)
            ->when($selectedLead, fn ($q) => $q->whereKey($selectedLead->id))
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->with(['documents:id,lead_id,checklist_key,status', 'agent:id,name'])
            ->paginate(50, ['*'], 'page', $selectedLead ? 1 : null)->withQueryString();
        $leads = $page->getCollection();

        // Resolve every program + staff name referenced, in two queries.
        $allIds = $leads->flatMap(fn ($l) => is_array($l->proposed_program_ids) ? $l->proposed_program_ids : [])
            ->map(fn ($x) => (int) $x)->unique()->values();
        $programs = Program::whereIn('id', $allIds)
            ->with('school:id,name')
            ->get([
                'id', 'school_id', 'title', 'level', 'category', 'location', 'institution',
                'price_text', 'tuition_fee', 'tuition_fees', 'intake_months',
                'duration_months', 'credits', 'residency_points',
            ])
            ->keyBy('id');

        $userIds = $leads->flatMap(function (Lead $l) {
            $r = is_array($l->proposal_review) ? $l->proposal_review : [];

            return [$r['submitted_by'] ?? null, $r['verified_by'] ?? null, $r['approved_by'] ?? null];
        })->filter()->unique()->values();
        $userNames = User::whereIn('id', $userIds)->pluck('name', 'id');

        $rows = $leads->map(function (Lead $l) use ($programs, $userNames) {
            $ids = is_array($l->proposed_program_ids) ? array_map('intval', $l->proposed_program_ids) : [];
            $reasons = is_array($l->proposed_program_reasons) ? $l->proposed_program_reasons : [];
            $meta = is_array($l->proposed_program_meta) ? $l->proposed_program_meta : [];
            $review = is_array($l->proposal_review) ? $l->proposal_review : [];
            $status = $review['status'] ?? 'pending';

            $progRows = collect($ids)
                ->map(fn ($id) => $programs->get($id))
                ->filter()
                ->map(function (Program $p) use ($meta, $reasons, $l) {
                    $m = is_array($meta[(string) $p->id] ?? null) ? $meta[(string) $p->id] : [];
                    $fee = array_key_exists('fee', $m) && $m['fee'] !== null && $m['fee'] !== ''
                        ? (float) $m['fee']
                        : $this->programFeeNumeric($p);

                    return [
                        'id' => $p->id,
                        'title' => $p->title,
                        'level' => $p->level,
                        'category' => $p->category,
                        'school' => $m['school'] ?? (optional($p->school)->name ?: $p->institution),
                        'intake' => $m['intake'] ?? $p->intake_months,
                        'fee' => $fee,
                        'fee_confirmed' => (bool) ($m['fee_confirmed'] ?? false),
                        'p_status' => $m['status'] ?? 'needs_check', // verified | needs_check
                        'edited' => (bool) ($m['edited'] ?? false),
                        // Internal staff note (private) vs. the client-facing reason.
                        'note' => trim((string) ($m['note'] ?? '')) ?: null,
                        // Threaded per-programme notes — the SAME thread the
                        // Proposals tab uses, so notes/replies added there are
                        // visible (and repliable) here and vice versa.
                        'notes' => collect(is_array($m['notes'] ?? null) ? $m['notes'] : [])
                            ->map(fn ($n) => [
                                'id' => $n['id'] ?? (string) \Illuminate\Support\Str::uuid(),
                                'tag' => $n['tag'] ?? 'note',
                                'body' => $n['body'] ?? '',
                                'author' => $n['author'] ?? 'Staff',
                                'role' => $n['role'] ?? null,
                                'created_at' => $n['created_at'] ?? null,
                                'actioned_at' => $n['actioned_at'] ?? null,
                                'replies' => array_values(is_array($n['replies'] ?? null) ? $n['replies'] : []),
                            ])->values(),
                        'reason' => trim((string) ($reasons[(string) $p->id] ?? '')) ?: null,
                        'is_first_choice' => (int) $l->preferred_program_id === (int) $p->id,
                    ];
                })->values();

            $checks = $this->buildChecks($l, $progRows);
            $totalTuition = $progRows->sum(fn ($r) => $r['fee_confirmed'] ? (float) $r['fee'] : 0.0);

            return [
                'id' => $l->id,
                'lead_id' => $l->lead_id,
                'agent' => optional($l->agent)->name,
                'name' => trim("{$l->first_name} {$l->last_name}") ?: '—',
                'initials' => $this->initials("{$l->first_name} {$l->last_name}"),
                'email' => $l->email,
                'status' => $status,
                'submitted_at' => $review['submitted_at'] ?? null,
                'verified_at' => $review['verified_at'] ?? null,
                'approved_at' => $review['approved_at'] ?? null,
                'submitted_by' => $userNames[$review['submitted_by'] ?? null] ?? null,
                'verified_by' => $userNames[$review['verified_by'] ?? null] ?? null,
                'approved_by' => $userNames[$review['approved_by'] ?? null] ?? null,
                'staff_proposed_count' => (int) ($review['staff_proposed_count'] ?? count($ids)),
                'edited_count' => $progRows->where('edited', true)->count(),
                'documents_count' => $l->documents->count(),
                'programs' => $progRows,
                'programs_count' => $progRows->count(),
                'total_tuition' => $totalTuition,
                'checks' => $checks,
                'note' => $this->sidebarNote($status, $progRows, $checks),
                'changes_requested' => $review['changes_requested'] ?? null,
            ];
        })->values();

        // Education staff (e.g. Dinah) get the Education-portal chrome; everyone
        // else (super admins) get the admin layout — same page, two wrappers.
        $isEducation = $request->user()?->role === 'education';
        $component = $isEducation ? 'portal/education/ProgramVerification' : 'admin/ProgramVerification';

        // Full program catalogue so staff can swap / add / remove programs.
        $catalogue = Program::orderBy('title')
            ->with('school:id,name')
            ->get(['id', 'school_id', 'title', 'level', 'category', 'location', 'institution', 'price_text', 'tuition_fee', 'tuition_fees', 'intake_months'])
            ->map(fn ($p) => [
                'id' => $p->id, 'title' => $p->title, 'level' => $p->level,
                'category' => $p->category, 'location' => $p->location,
                'school' => optional($p->school)->name ?: $p->institution,
                'intake' => $p->intake_months,
                'fee' => $this->programFeeNumeric($p),
                'price_text' => $p->price_text,
            ])->values();

        return inertia($component, [
            'proposals' => $rows,
            'counts' => $counts,
            'selectedStatus' => $status,
            'selectedLeadId' => $selectedLead?->id,
            'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'prev_url' => $page->previousPageUrl(), 'next_url' => $page->nextPageUrl()],
            'programs' => $catalogue,
            'schools' => \App\Models\School::orderBy('name')->pluck('name')->values(),
            'leadBase' => $isEducation ? '/portal/education' : '/admin',
        ]);
    }

    // ── Write ─────────────────────────────────────────────────────────────────

    /**
     * Swap / add / remove programs on the shortlist and update reasons. Keeps
     * the current review status so it isn't accidentally re-submitted.
     */
    public function updatePrograms(Request $request, Lead $lead)
    {
        $review = is_array($lead->proposal_review) ? $lead->proposal_review : [];
        abort_unless(in_array($review['status'] ?? null, ['pending', 'verified'], true), 422, 'This proposal is not under review.');

        $validated = $request->validate([
            'program_ids' => 'required|array|min:1|max:5',
            'program_ids.*' => 'integer|exists:programs,id',
            'reasons' => 'nullable|array',
            'reasons.*' => 'nullable|string|max:1000',
        ]);

        $ids = array_values(array_unique(array_map('intval', $validated['program_ids'])));

        $reasons = [];
        foreach (($validated['reasons'] ?? []) as $pid => $text) {
            $pid = (int) $pid;
            $text = trim((string) $text);
            if ($text !== '' && in_array($pid, $ids, true)) {
                $reasons[(string) $pid] = $text;
            }
        }

        // Drop per-program meta for programs no longer shortlisted.
        $meta = is_array($lead->proposed_program_meta) ? $lead->proposed_program_meta : [];
        $meta = array_intersect_key($meta, array_flip(array_map('strval', $ids)));

        // Editing clears any client pick that's no longer in the shortlist.
        if ($lead->preferred_program_id && ! in_array((int) $lead->preferred_program_id, $ids, true)) {
            $lead->preferred_program_id = null;
            $lead->preferred_program_chosen_at = null;
        }

        $lead->proposed_program_ids = $ids;
        $lead->proposed_program_reasons = $reasons ?: null;
        $lead->proposed_program_meta = $meta ?: null;
        $lead->save();

        return back()->with('success', 'Programs updated.');
    }

    /**
     * Per-program overrides — Edit fee, Reassign school, change intake, and the
     * per-program Verify / Needs-check toggle. Accepts a partial `meta` map
     * keyed by program id and merges it onto what's stored.
     */
    public function updateProgramMeta(Request $request, Lead $lead)
    {
        $review = is_array($lead->proposal_review) ? $lead->proposal_review : [];
        abort_unless(in_array($review['status'] ?? null, ['pending', 'verified'], true), 422, 'This proposal is not under review.');

        $validated = $request->validate([
            'meta' => 'required|array',
            'meta.*.fee' => 'nullable|numeric|min:0|max:100000000',
            'meta.*.fee_confirmed' => 'nullable|boolean',
            'meta.*.school' => 'nullable|string|max:255',
            'meta.*.intake' => 'nullable|string|max:255',
            'meta.*.status' => 'nullable|in:verified,needs_check',
            'meta.*.note' => 'nullable|string|max:1000',
        ]);

        $ids = array_map('strval', is_array($lead->proposed_program_ids) ? $lead->proposed_program_ids : []);
        $meta = is_array($lead->proposed_program_meta) ? $lead->proposed_program_meta : [];

        foreach ($validated['meta'] as $pid => $patch) {
            $pid = (string) $pid;
            if (! in_array($pid, $ids, true)) {
                continue; // only programs on this shortlist
            }
            $current = is_array($meta[$pid] ?? null) ? $meta[$pid] : [];

            // Any fee / school / intake change marks the row as staff-edited.
            $touchedFields = array_intersect_key($patch, array_flip(['fee', 'school', 'intake']));
            if (! empty($touchedFields)) {
                $current['edited'] = true;
            }
            foreach (['fee', 'fee_confirmed', 'school', 'intake', 'status', 'note'] as $k) {
                if (array_key_exists($k, $patch)) {
                    $current[$k] = $patch[$k] === '' ? null : $patch[$k];
                }
            }
            // Confirming a fee (or verifying the row) implies the fee is confirmed.
            if (($patch['status'] ?? null) === 'verified') {
                $current['fee_confirmed'] = true;
            }
            $meta[$pid] = $current;
        }

        $lead->proposed_program_meta = $meta ?: null;
        $lead->save();

        return back()->with('success', 'Updated.');
    }

    /** Bounce the proposal back to the submitting staff with a note. */
    public function requestChanges(Request $request, Lead $lead)
    {
        $review = is_array($lead->proposal_review) ? $lead->proposal_review : [];
        abort_unless(in_array($review['status'] ?? null, ['pending', 'verified'], true), 422, 'This proposal is not under review.');

        $validated = $request->validate([
            'message' => 'nullable|string|max:2000',
            'program_ids' => 'nullable|array',
            'program_ids.*' => 'integer',
        ]);

        // Keep only ids that are actually on this shortlist.
        $shortlist = array_map('intval', is_array($lead->proposed_program_ids) ? $lead->proposed_program_ids : []);
        $flagged = array_values(array_intersect(
            array_map('intval', $validated['program_ids'] ?? []),
            $shortlist
        ));

        $review['status'] = 'pending'; // stays in the queue, flagged
        $review['changes_requested'] = [
            'message' => trim((string) ($validated['message'] ?? '')) ?: 'Please revise this proposal.',
            'program_ids' => $flagged,
            'by' => $request->user()->id,
            'at' => now()->toIso8601String(),
        ];
        $lead->proposal_review = $review;

        // Also drop a first-class "change requested" note on each flagged
        // programme so it shows in that programme's note thread on the
        // Proposals review inbox (with reply / mark-actioned support).
        if (! empty($flagged)) {
            $u = $request->user();
            $meta = is_array($lead->proposed_program_meta) ? $lead->proposed_program_meta : [];
            foreach ($flagged as $pid) {
                $key = (string) $pid;
                $entry = is_array($meta[$key] ?? null) ? $meta[$key] : [];
                $notes = is_array($entry['notes'] ?? null) ? $entry['notes'] : [];
                $notes[] = [
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'tag' => 'change_requested',
                    'body' => $review['changes_requested']['message'],
                    'author' => $u->name,
                    'author_id' => $u->id,
                    'role' => $u->role,
                    'created_at' => now()->toIso8601String(),
                    'actioned_at' => null,
                    'actioned_by' => null,
                    'replies' => [],
                ];
                $entry['notes'] = $notes;
                $meta[$key] = $entry;
            }
            $lead->proposed_program_meta = $meta;
        }

        $lead->save();

        return back()->with('success', 'Changes requested — the submitter has been flagged.');
    }

    /** Step 1 — mark a pending proposal as verified (reviewed). */
    public function verify(Request $request, Lead $lead)
    {
        $review = is_array($lead->proposal_review) ? $lead->proposal_review : [];
        abort_unless(($review['status'] ?? null) === 'pending', 422, 'This proposal is not awaiting verification.');

        $review['status'] = 'verified';
        $review['verified_at'] = now()->toIso8601String();
        $review['verified_by'] = $request->user()->id;
        unset($review['changes_requested']);
        $lead->proposal_review = $review;
        $lead->save();

        return back()->with('success', 'Proposal verified — approve it to send to the client.');
    }

    /**
     * Step 2 — approve: make it live on the tracker. Emails the client unless
     * `send_email=0` (the "Approve without email" action). `verify_all=1`
     * (from "Verify & approve all") first stamps verification + confirms every
     * program in one go.
     */
    public function approve(Request $request, Lead $lead)
    {
        $review = is_array($lead->proposal_review) ? $lead->proposal_review : [];
        abort_unless(in_array($review['status'] ?? null, ['pending', 'verified'], true), 422, 'This proposal cannot be approved.');

        $sendEmail = $request->boolean('send_email', true);
        $verifyAll = $request->boolean('verify_all', false);

        if ($verifyAll) {
            // Confirm every shortlisted program (fee + status) in one action.
            $ids = array_map('strval', is_array($lead->proposed_program_ids) ? $lead->proposed_program_ids : []);
            $meta = is_array($lead->proposed_program_meta) ? $lead->proposed_program_meta : [];
            foreach ($ids as $pid) {
                $meta[$pid] = array_merge(is_array($meta[$pid] ?? null) ? $meta[$pid] : [], [
                    'status' => 'verified', 'fee_confirmed' => true,
                ]);
            }
            $lead->proposed_program_meta = $meta ?: null;
            if (($review['status'] ?? null) === 'pending') {
                $review['verified_at'] = now()->toIso8601String();
                $review['verified_by'] = $request->user()->id;
            }
        }

        $review['status'] = 'approved';
        $review['approved_at'] = now()->toIso8601String();
        $review['approved_by'] = $request->user()->id;
        $review['emailed'] = $sendEmail;
        unset($review['changes_requested']);
        $lead->proposal_review = $review;
        $lead->save();

        if ($sendEmail) {
            try {
                // Fire the Education "Proposal verified & approved" automation
                // (client / agent / education-team messages, each off until an
                // admin configures it). Its return says whether a CLIENT message
                // was sent, so the built-in client email falls back only when the
                // admin hasn't set one — no double client email.
                $ids = is_array($lead->proposed_program_ids) ? array_map('intval', $lead->proposed_program_ids) : [];
                $titles = \App\Models\Program::whereIn('id', $ids)->orderBy('title')->pluck('title')->all();
                $firedClient = app(\App\Services\EmailAutomationService::class)->fire('education.proposal.approved', $lead->fresh(), [
                    'program_list' => implode(', ', $titles),
                    'program_count' => count($ids),
                ]);

                app(\App\Http\Controllers\LeadDocumentController::class)->sendProposalReadyEmail($lead->fresh(), ! $firedClient);
            } catch (\Throwable $e) {
                Log::warning('Proposal approval email failed', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);
            }
        }

        return back()->with('success', $sendEmail
            ? 'Proposal approved — the client can now see it and has been emailed.'
            : 'Proposal approved — live on the tracker (no email sent).');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Lowest structured/legacy tuition amount as a number, else null. */
    private function programFeeNumeric(Program $p): ?float
    {
        $rows = is_array($p->tuition_fees) && count($p->tuition_fees) > 0
            ? $p->tuition_fees
            : ($p->tuition_fee ? [['amount' => $p->tuition_fee]] : []);

        $amounts = collect($rows)
            ->map(fn ($r) => (float) ($r['amount'] ?? 0))
            ->filter(fn ($n) => $n > 0);

        if ($amounts->isNotEmpty()) {
            return (float) $amounts->min();
        }

        // Fall back to a bare number embedded in price_text ("$180,000" → 180000).
        if ($p->price_text && preg_match('/[\d,]+(?:\.\d+)?/', str_replace(' ', '', $p->price_text), $m)) {
            $n = (float) str_replace(',', '', $m[0]);

            return $n > 0 ? $n : null;
        }

        return null;
    }

    /** The four pre-approval checks the panel renders. */
    private function buildChecks(Lead $lead, $progRows): array
    {
        $total = $progRows->count();
        $confirmed = $progRows->where('fee_confirmed', true)->count();

        $intakesOpen = $total > 0 && $progRows->every(fn ($r) => ! empty($r['intake']));

        $docKeys = $lead->documents->pluck('checklist_key')->filter()->map(fn ($k) => strtolower((string) $k));
        $hasPassport = $docKeys->contains('passport');
        $hasTranscript = $docKeys->intersect(self::TRANSCRIPT_KEYS)->isNotEmpty();

        // Duplicate = the same program shortlisted twice (ids are de-duped, so
        // this is Clear in practice, but the panel still reports it).
        $titles = $progRows->pluck('title');
        $noDuplicate = $titles->count() === $titles->unique()->count();

        return [
            'fees' => ['done' => $confirmed === $total && $total > 0, 'label' => "{$confirmed} of {$total}"],
            'intakes' => ['done' => $intakesOpen, 'label' => $intakesOpen ? 'Yes' : 'Check'],
            'documents' => ['done' => $hasPassport && $hasTranscript, 'label' => ($hasPassport && $hasTranscript) ? 'Complete' : 'Missing'],
            'duplicate' => ['done' => $noDuplicate, 'label' => $noDuplicate ? 'Clear' : 'Found'],
        ];
    }

    /** Short status note shown on the sidebar card, in priority order. */
    private function sidebarNote(string $status, $progRows, array $checks): ?string
    {
        if ($status === 'verified') {
            return 'Ready to approve';
        }
        if (! $checks['documents']['done']) {
            return 'Missing transcript';
        }
        if (! $checks['duplicate']['done']) {
            return 'Duplicate programme';
        }
        $edited = $progRows->where('edited', true)->count();
        if ($edited > 0) {
            return $edited.' fee edited';
        }

        return null;
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $letters = collect($parts)->filter()->take(2)->map(fn ($w) => strtoupper($w[0] ?? ''));

        return $letters->implode('') ?: '—';
    }
}
