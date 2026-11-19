<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\User;
use App\Services\ConsultancyReviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Consultancy Agreement Verification — the consultancy counterpart of Program
 * (proposal) verification. A generated consultancy agreement enters here as
 * PENDING; a reviewer confirms / edits each fee line-item, adds internal notes,
 * then Verify → Approve. Only on approval is the client emailed the agreement.
 *
 * State lives on the lead in a single JSON column `consultancy_review`: the
 * workflow envelope PLUS the per fee-item meta under `items` (amount / status /
 * note thread). One column, because the wide `leads` row can't take a second.
 */
class ConsultancyVerificationController extends Controller
{
    // ── Read ────────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $data = $request->validate(['lead_id' => 'nullable|integer|min:1']);
        $selectedLead = isset($data['lead_id']) ? Lead::whereNotNull('consultancy_review')->findOrFail($data['lead_id']) : null;
        $todayStart = now()->startOfDay()->toIso8601String();
        $leads = Lead::query()
            ->whereNotNull('consultancy_review')
            ->when($selectedLead, fn ($q) => $q->whereKey($selectedLead->id))
            ->when(! $selectedLead, fn ($query) => $query->where(function ($q) use ($todayStart) {
                $q->whereIn('consultancy_review->status', ['pending', 'verified'])
                    ->orWhere('consultancy_review->approved_at', '>=', $todayStart)
                    ->orWhere('consultancy_review->verified_at', '>=', $todayStart);
            }))
            ->orderByDesc('updated_at')
            ->limit(300)
            ->with(['documents' => fn ($q) => $q->whereIn('checklist_key', ['agree.consultancy', 'agree.engagement_english'])
                ->where('source', 'generated')->latest()])
            ->get();

        $userIds = $leads->flatMap(function (Lead $l) {
            $r = is_array($l->consultancy_review) ? $l->consultancy_review : [];

            return [$r['submitted_by'] ?? null, $r['verified_by'] ?? null, $r['approved_by'] ?? null, $r['default_approver_id'] ?? null];
        })->filter()->unique()->values();
        $userNames = User::whereIn('id', $userIds)->pluck('name', 'id');

        $counts = ['pending' => 0, 'verified' => 0, 'approved_today' => 0];

        $defaultApprover = ConsultancyReviewService::defaultApprover();
        $isAuthorizedApprover = $request->user()->is_active && $request->user()->isAdmin();
        $rows = $leads->map(function (Lead $l) use ($userNames, &$counts, $defaultApprover, $isAuthorizedApprover) {
            $review = is_array($l->consultancy_review) ? $l->consultancy_review : [];
            $meta = is_array($review['items'] ?? null) ? $review['items'] : [];
            $status = $review['status'] ?? 'pending';
            $educationConsultancy = ConsultancyReviewService::isEducationConsultancy($review['scenario'] ?? null);

            if ($status === 'pending') {
                $counts['pending']++;
            } elseif ($status === 'verified') {
                $counts['verified']++;
            }
            if (! empty($review['approved_at']) && $review['approved_at'] >= now()->startOfDay()->toIso8601String()) {
                $counts['approved_today']++;
            }

            $itemKeys = is_array($review['item_keys'] ?? null) ? $review['item_keys'] : array_keys($meta);
            $items = collect($itemKeys)->map(function ($key) use ($meta, $review) {
                $m = is_array($meta[$key] ?? null) ? $meta[$key] : [];

                return [
                    'key' => $key,
                    // Always resolve the friendly label from the canonical map
                    // (it's never user-edited), so older reviews that snapshotted
                    // the raw key — or a missing label — still read as a name,
                    // e.g. "school_enrolment" → "School Enrolment & Documentation".
                    'label' => \App\Services\ConsultancyReviewService::ITEM_LABELS[$key]
                        ?? ($m['label'] ?? \Illuminate\Support\Str::headline($key)),
                    'amount' => $m['amount'] ?? null,
                    'currency_symbol' => ($review['scenario'] ?? null) === 'english_engagement' && $key === 'pte_exam' ? 'US$' : (($review['currency'] ?? 'php') === 'nzd' ? 'NZ$' : 'Php'),
                    'status' => $m['status'] ?? 'needs_check', // verified | needs_check
                    'edited' => (bool) ($m['edited'] ?? false),
                    'note' => trim((string) ($m['note'] ?? '')) ?: null,
                    'notes' => collect(is_array($m['notes'] ?? null) ? $m['notes'] : [])
                        ->map(fn ($n) => [
                            'id' => $n['id'] ?? (string) Str::uuid(),
                            'tag' => $n['tag'] ?? 'note',
                            'body' => $n['body'] ?? '',
                            'author' => $n['author'] ?? 'Staff',
                            'role' => $n['role'] ?? null,
                            'created_at' => $n['created_at'] ?? null,
                            'actioned_at' => $n['actioned_at'] ?? null,
                            'replies' => array_values(is_array($n['replies'] ?? null) ? $n['replies'] : []),
                        ])->values(),
                ];
            })->values();

            $total = $items->count();
            $confirmed = $items->where('status', 'verified')->count();

            // The generated agreement PDF the reviewer opens to verify. Staff
            // download route (re-checks ownership); ?inline=1 renders in-tab.
            $doc = $l->documents->first();
            $document = $doc ? [
                'id' => $doc->id,
                'name' => $doc->original_name ?: 'Consultancy Agreement.pdf',
                'created_at' => optional($doc->created_at)->toIso8601String(),
                'view_url' => "/admin/documents/{$doc->id}/download?inline=1",
                'download_url' => "/admin/documents/{$doc->id}/download",
            ] : null;

            return [
                'id' => $l->id,
                'delete_token' => $this->canDeleteDraft($review) ? hash('sha256', json_encode($review, JSON_THROW_ON_ERROR)) : null,
                'document' => $document,
                'preview_url' => "/consultancy-verification/{$l->id}/preview",
                'lead_id' => $l->lead_id,
                'name' => trim("{$l->first_name} {$l->last_name}") ?: '—',
                'initials' => $this->initials("{$l->first_name} {$l->last_name}"),
                'email' => $l->email,
                'status' => $status,
                'scenario' => $review['scenario'] ?? null,
                'scenario_label' => $review['scenario_label'] ?? 'Consultancy Agreement',
                'applicant_mode' => $review['applicant_mode'] ?? 'single',
                'currency' => $review['currency'] ?? 'php',
                'currency_symbol' => ($review['currency'] ?? 'php') === 'nzd' ? 'NZ$' : 'Php',
                'submitted_at' => $review['submitted_at'] ?? null,
                'verified_at' => $review['verified_at'] ?? null,
                'approved_at' => $review['approved_at'] ?? null,
                'submitted_by' => $userNames[$review['submitted_by'] ?? null] ?? null,
                'verified_by' => $userNames[$review['verified_by'] ?? null] ?? null,
                'approved_by' => $userNames[$review['approved_by'] ?? null] ?? null,
                'default_approver' => $educationConsultancy
                    ? ($userNames[$review['default_approver_id'] ?? null] ?? $defaultApprover?->name)
                    : null,
                'can_approve' => ! $educationConsultancy || $isAuthorizedApprover,
                'items' => $items,
                'items_count' => $total,
                'confirmed_count' => $confirmed,
                'total_amount' => ConsultancyReviewService::totalAmount($review),
                'note' => $review['note'] ?? null, // document-level review note
                'changes_requested' => $review['changes_requested'] ?? null,
            ];
        })->values();

        $isEducation = $request->user()?->role === 'education';
        $component = $isEducation ? 'portal/education/ConsultancyVerification' : 'admin/ConsultancyVerification';

        return inertia($component, [
            'proposals' => $rows,
            'selectedLeadId' => $selectedLead?->id,
            'counts' => $counts,
            'leadBase' => $isEducation ? '/portal/education' : '/admin',
        ]);
    }

    /**
     * Live HTML preview of the agreement — rendered from the lead's stored
     * consultancy_review (with the reviewer's edited fees), NOT a generated PDF.
     * Same look as the generation modal's preview; no PDF exists until approval.
     */
    public function preview(Request $request, Lead $lead, \App\Services\AgreementGenerator $generator)
    {
        $review = is_array($lead->consultancy_review) ? $lead->consultancy_review : [];
        abort_if(empty($review), 404, 'No consultancy agreement to preview.');

        [$view, $payload] = \App\Services\ConsultancyReviewService::previewPayload(
            $generator,
            $lead,
            $review['scenario'] ?? null,
            \App\Services\ConsultancyReviewService::overridesForGeneration($review),
        );

        return response(view($view, $payload)->render())
            ->header('Content-Type', 'text/html; charset=utf-8');
    }

    // ── Write ─────────────────────────────────────────────────────────────────

    /** Partial per fee-item patch: edit the amount, set the status, or set the note. */
    public function updateMeta(Request $request, Lead $lead)
    {
        $this->guardUnderReview($lead);

        $validated = $request->validate([
            'meta' => 'nullable|array',
            'meta.*.amount' => 'nullable|numeric|min:0|max:100000000',
            'meta.*.status' => 'nullable|in:verified,needs_check',
            'meta.*.note' => 'nullable|string|max:1000',
            // Document-level note — the verification screen now reviews the
            // agreement as one document rather than per fee line-item.
            'review_note' => 'nullable|string|max:2000',
        ]);

        $review = is_array($lead->consultancy_review) ? $lead->consultancy_review : [];
        $meta = is_array($review['items'] ?? null) ? $review['items'] : [];
        $keys = array_keys($meta);

        if ($request->has('review_note')) {
            $review['note'] = ($validated['review_note'] ?? '') === '' ? null : $validated['review_note'];
        }

        foreach ((array) ($validated['meta'] ?? []) as $key => $patch) {
            $key = (string) $key;
            if (! in_array($key, $keys, true)) {
                continue; // only items already on this agreement
            }
            $current = is_array($meta[$key] ?? null) ? $meta[$key] : [];

            if (array_key_exists('amount', $patch)) {
                $current['amount'] = ($patch['amount'] === '' || $patch['amount'] === null) ? null : (int) $patch['amount'];
                $current['edited'] = true;
            }
            foreach (['status', 'note'] as $k) {
                if (array_key_exists($k, $patch)) {
                    $current[$k] = $patch[$k] === '' ? null : $patch[$k];
                }
            }
            $meta[$key] = $current;
        }

        $review['items'] = $meta;
        $this->saveReview($lead, $review);

        return back()->with('success', 'Updated.');
    }

    /** Bounce the consultancy agreement back to the submitter with a note. */
    public function requestChanges(Request $request, Lead $lead)
    {
        $this->guardUnderReview($lead);

        $validated = $request->validate([
            'message' => 'nullable|string|max:2000',
            'item_keys' => 'nullable|array',
            'item_keys.*' => 'string',
        ]);

        $review = is_array($lead->consultancy_review) ? $lead->consultancy_review : [];
        $meta = is_array($review['items'] ?? null) ? $review['items'] : [];
        $flagged = array_values(array_intersect($validated['item_keys'] ?? [], array_keys($meta)));

        $review['status'] = 'pending';
        $review['changes_requested'] = [
            'message' => trim((string) ($validated['message'] ?? '')) ?: 'Please revise this consultancy agreement.',
            'item_keys' => $flagged,
            'by' => $request->user()->id,
            'at' => now()->toIso8601String(),
        ];

        // Drop a "change requested" note on each flagged item's thread.
        if (! empty($flagged)) {
            $u = $request->user();
            foreach ($flagged as $key) {
                $entry = is_array($meta[$key] ?? null) ? $meta[$key] : [];
                $notes = is_array($entry['notes'] ?? null) ? $entry['notes'] : [];
                $notes[] = $this->noteEntry($u, $review['changes_requested']['message'], 'change_requested');
                $entry['notes'] = $notes;
                $meta[$key] = $entry;
            }
        }

        $review['items'] = $meta;
        $this->saveReview($lead, $review);

        return back()->with('success', 'Changes requested — the submitter has been flagged.');
    }

    /** Step 1 — mark a pending agreement as verified. */
    public function verify(Request $request, Lead $lead)
    {
        $review = is_array($lead->consultancy_review) ? $lead->consultancy_review : [];
        abort_unless(($review['status'] ?? null) === 'pending', 422, 'This agreement is not awaiting verification.');

        $review['status'] = 'verified';
        $review['verified_at'] = now()->toIso8601String();
        $review['verified_by'] = $request->user()->id;
        unset($review['changes_requested']);
        $this->saveReview($lead, $review);

        return back()->with('success', 'Agreement verified — approve it to send to the client.');
    }

    /**
     * Move the agreement back to DRAFT — keeps the reviewer's edits/notes but
     * clears the verified stamp and any "changes requested" flag, so it sits in
     * the Draft column again (nothing is sent). No-op if already approved.
     */
    public function draft(Request $request, Lead $lead)
    {
        $review = is_array($lead->consultancy_review) ? $lead->consultancy_review : [];
        abort_unless(in_array($review['status'] ?? null, ['pending', 'verified'], true), 422, 'This agreement is not under review.');

        $review['status'] = 'pending';
        unset($review['verified_at'], $review['verified_by'], $review['changes_requested']);
        $this->saveReview($lead, $review);

        return back()->with('success', 'Saved as draft.');
    }

    private function canDeleteDraft(array $review): bool
    {
        return ($review['status'] ?? null) === 'pending' && empty($review['approved_at'])
            && empty($review['approved_by']) && empty($review['emailed']);
    }

    public function deleteDraft(Request $request, Lead $lead)
    {
        $data = $request->validate(['delete_token' => 'required|string|size:64']);
        DB::transaction(function () use ($lead, $data) {
            $lead = Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();
            $review = is_array($lead->consultancy_review) ? $lead->consultancy_review : [];
            if (! $this->canDeleteDraft($review)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['draft' => 'Only an unapproved draft can be deleted.']);
            }
            if (! hash_equals(hash('sha256', json_encode($review, JSON_THROW_ON_ERROR)), $data['delete_token'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(['draft' => 'This draft changed. Refresh it before deleting.']);
            }
            \App\Models\ActivityLog::record('consultancy.draft.deleted', [
                'description' => 'Deleted draft agreement review', 'entity_type' => Lead::class,
                'entity_id' => $lead->id, 'properties' => ['review' => $review],
            ]);
            $lead->forceFill(['consultancy_review' => null])->saveOrFail();
            $lead->recordStaffActivity('Deleted draft agreement review');
        });

        return redirect()->route('consultancy-verification.index')->with('success', 'Draft deleted. Previously issued documents are retained.');
    }

    /**
     * Step 2 — approve: send the consultancy agreement to the client. `verify_all`
     * confirms every fee item first; `send_email=0` approves without emailing.
     */
    public function approve(Request $request, Lead $lead)
    {
        $sendEmail = $request->boolean('send_email', true);
        $verifyAll = $request->boolean('verify_all', false);
        $approver = $request->user();
        $issued = null;

        try {
            $lead = DB::transaction(function () use ($lead, $sendEmail, $verifyAll, $approver, &$issued) {
                $lead = Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();
                $review = is_array($lead->consultancy_review) ? $lead->consultancy_review : [];
                abort_unless(in_array($review['status'] ?? null, ['pending', 'verified'], true), 422, 'This agreement cannot be approved.');
                if (ConsultancyReviewService::isEducationConsultancy($review['scenario'] ?? null)) {
                    abort_unless($approver->is_active && $approver->isAdmin(), 403, 'An authorized admin must approve this agreement.');
                }

                if ($verifyAll) {
                    $meta = is_array($review['items'] ?? null) ? $review['items'] : [];
                    foreach ($meta as $key => $item) {
                        $meta[$key] = array_merge(is_array($item) ? $item : [], ['status' => 'verified']);
                    }
                    $review['items'] = $meta;
                    if ($review['status'] === 'pending') {
                        $review['verified_at'] = now()->toIso8601String();
                        $review['verified_by'] = $approver->id;
                    }
                }

                $issued = ConsultancyReviewService::generatePdf(
                    app(\App\Services\AgreementGenerator::class),
                    $lead,
                    $review['scenario'] ?? null,
                    ConsultancyReviewService::overridesForGeneration($review),
                );

                $disk = Storage::disk('local');
                if (! $disk->exists($issued->file_path) || $disk->size($issued->file_path) !== $issued->size) {
                    throw new \RuntimeException('The issued PDF did not save.');
                }

                $review['status'] = 'approved';
                $review['approved_at'] = now()->toIso8601String();
                $review['approved_by'] = $approver->id;
                $review['emailed'] = $sendEmail;
                unset($review['changes_requested']);
                $lead->consultancy_review = $review;
                if ($lead->status !== 'Consultancy Agreement Signed') {
                    $lead->status = 'Consultancy Agreement Sent';
                }
                $lead->saveOrFail();

                return $lead;
            });
        } catch (HttpExceptionInterface $error) {
            throw $error;
        } catch (\Throwable $error) {
            if ($issued?->file_path) {
                Storage::disk('local')->delete($issued->file_path);
            }
            Log::error('Agreement issuance failed', ['lead_id' => $lead->id, 'exception' => $error]);

            return back()->withErrors(['agreement' => 'Could not issue the agreement. Try again before sending it to the client.']);
        }

        $review = $lead->consultancy_review;
        if ($sendEmail) {
            try {
                // Fire the Education "Consultancy agreement approved" automation
                // (client / agent-CC / education-team, each off until configured).
                // Its return says whether a CLIENT message was sent, so the
                // built-in client email falls back only when none is configured.
                $firedClient = app(\App\Services\EmailAutomationService::class)->fire('education.consultancy.approved', $lead->fresh(), [
                    'agreement_type' => $review['scenario_label'] ?? 'Consultancy Agreement',
                    'total_amount' => ConsultancyReviewService::totalAmount($review),
                ]);

                app(\App\Http\Controllers\LeadDocumentController::class)->sendConsultancyAgreementEmail($lead->fresh(), ! $firedClient);
            } catch (\Throwable $e) {
                Log::warning('Consultancy approval email failed', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);
            }
        }

        return back()->with('success', $sendEmail
            ? 'Consultancy agreement approved — the client has been emailed.'
            : 'Consultancy agreement approved (no email sent).');
    }

    // ── Per-item notes (thread) ────────────────────────────────────────────────

    public function addNote(Request $request, Lead $lead, string $item)
    {
        $data = $request->validate(['body' => 'required|string|max:2000', 'tag' => 'nullable|in:note,change_requested']);
        $this->mutateItem($lead, $item, function (&$entry) use ($request, $data) {
            $entry['notes'][] = $this->noteEntry($request->user(), $data['body'], $data['tag'] ?? 'note');
        });

        return back()->with('success', 'Note added.');
    }

    public function replyNote(Request $request, Lead $lead, string $item, string $noteId)
    {
        $data = $request->validate(['body' => 'required|string|max:2000']);
        $this->mutateItem($lead, $item, function (&$entry) use ($request, $data, $noteId) {
            foreach ($entry['notes'] as &$n) {
                if (($n['id'] ?? null) === $noteId) {
                    $u = $request->user();
                    $n['replies'][] = [
                        'id' => (string) Str::uuid(),
                        'body' => $data['body'],
                        'author' => $u->name,
                        'author_id' => $u->id,
                        'role' => $u->role,
                        'created_at' => now()->toIso8601String(),
                    ];
                }
            }
        });

        return back()->with('success', 'Reply added.');
    }

    public function toggleActioned(Request $request, Lead $lead, string $item, string $noteId)
    {
        $this->mutateItem($lead, $item, function (&$entry) use ($request, $noteId) {
            foreach ($entry['notes'] as &$n) {
                if (($n['id'] ?? null) === $noteId) {
                    $done = ! empty($n['actioned_at']);
                    $n['actioned_at'] = $done ? null : now()->toIso8601String();
                    $n['actioned_by'] = $done ? null : $request->user()->id;
                }
            }
        });

        return back();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function saveReview(Lead $lead, array $review): void
    {
        DB::transaction(function () use ($lead, $review) {
            $current = Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();
            if ($current->consultancy_review !== $lead->getOriginal('consultancy_review')) {
                throw \Illuminate\Validation\ValidationException::withMessages(['review' => 'This review changed. Refresh before saving.']);
            }
            $current->forceFill(['consultancy_review' => $review])->saveOrFail();
        });
    }

    private function guardUnderReview(Lead $lead): void
    {
        $review = is_array($lead->consultancy_review) ? $lead->consultancy_review : [];
        abort_unless(in_array($review['status'] ?? null, ['pending', 'verified'], true), 422, 'This agreement is not under review.');
    }

    /** Load an item entry, mutate it via the callback, and persist. 404s if unknown. */
    private function mutateItem(Lead $lead, string $item, callable $fn): void
    {
        $review = is_array($lead->consultancy_review) ? $lead->consultancy_review : [];
        $meta = is_array($review['items'] ?? null) ? $review['items'] : [];
        abort_unless(array_key_exists($item, $meta), 404, 'Unknown fee item.');

        $entry = is_array($meta[$item]) ? $meta[$item] : [];
        $entry['notes'] = is_array($entry['notes'] ?? null) ? $entry['notes'] : [];
        $fn($entry);
        $meta[$item] = $entry;

        $review['items'] = $meta;
        $this->saveReview($lead, $review);
    }

    private function noteEntry(User $u, string $body, string $tag): array
    {
        return [
            'id' => (string) Str::uuid(),
            'tag' => $tag,
            'body' => $body,
            'author' => $u->name,
            'author_id' => $u->id,
            'role' => $u->role,
            'created_at' => now()->toIso8601String(),
            'actioned_at' => null,
            'actioned_by' => null,
            'replies' => [],
        ];
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $letters = collect($parts)->filter()->take(2)->map(fn ($w) => strtoupper($w[0] ?? ''));

        return $letters->implode('') ?: '—';
    }
}
