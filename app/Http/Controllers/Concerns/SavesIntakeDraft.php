<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Lead;
use App\Services\LeadIntakeService;
use App\Support\VisaIntakeDraft;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Server-side drafts for the five public visa intakes (Resident, Work, Student,
 * Visitor, Family).
 *
 * Those forms only ever auto-saved to the applicant's own localStorage, so a
 * half-filled assessment was invisible to staff — the Visa Assessment dashboard
 * showed nothing until final submit, and an applicant who abandoned the form
 * simply vanished.
 *
 * Drafts land as a `Lead` rather than a partial row in the intake table on
 * purpose: `resident_intakes` alone has 23 NOT NULL columns with no default
 * (passport number, arrival date, hourly rate …), none of which a draft has
 * yet. Storing there would mean making them all nullable across five tables and
 * weakening the real submit's guarantees. A Lead already carries name/email/
 * phone as first-class columns and has a JSON bucket for the rest, and it is
 * the same shape the Free Assessment funnel has always used for its drafts —
 * so these appear in the very same list, under the right visa tab.
 *
 * Nothing here touches the intake tables, and a draft never becomes a
 * submission: the real `store()` path is untouched.
 */
trait SavesIntakeDraft
{
    /**
     * Persist (or update) the applicant's in-progress answers as a Draft lead.
     *
     * Deliberately lenient: a draft is a partial form, so only the two fields
     * needed to identify and de-duplicate the person are required. Below that
     * the browser keeps its local copy and nothing is written — a row we can't
     * attach a name or an email to is of no use to staff anyway.
     */
    protected function saveIntakeDraft(Request $request, string $visaType, LeadIntakeService $intake)
    {
        abort_unless(isset(VisaIntakeDraft::SOURCES[$visaType]), 404);
        $source = VisaIntakeDraft::SOURCES[$visaType];

        // Browsers send untouched optional fields as "" — null them so
        // `nullable|email` doesn't trip on a blank.
        $request->merge(collect($request->all())
            ->map(fn ($v) => is_string($v) && trim($v) === '' ? null : $v)
            ->all());

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'family_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:40',
        ], [
            'first_name.required' => 'Add your first name to save the draft online.',
            'email.required' => 'Add your email to save the draft online.',
            'email.email' => 'Enter a valid email to save the draft online.',
        ]);

        // Family/Student/Visitor/Work call the surname `family_name`; Resident
        // calls it `last_name`. Either is fine.
        $surname = $validated['last_name'] ?? $validated['family_name'] ?? '';

        try {
            $lead = $intake->ingest($source, [
                'lead_id' => strtoupper(substr($visaType, 0, 2)).'D-'.strtoupper(uniqid()),
                'first_name' => $validated['first_name'],
                'last_name' => $surname,
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'stage' => 'Evaluation',
            ], $request);

            DB::transaction(function () use ($lead, $validated, $surname, $source, $request) {
                $current = Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();
                // An applicant's auto-save must not demote a staff-managed record.
                $canEnterDraft = in_array($current->status, [null, 'New Leads', 'Draft'], true)
                    && ! $current->is_student && blank($current->education_stage)
                    && ! $current->is_immigration_case;

                $current->update(array_filter([
                    'first_name' => $validated['first_name'],
                    'last_name' => $surname ?: null,
                    'phone' => $validated['phone'] ?? null,
                    'source' => $source,
                    'status' => $canEnterDraft ? 'Draft' : null,
                    'ai_analysis' => $request->except(['_token']),
                    'ai_analysis_status' => 'completed',
                ], fn ($value) => $value !== null));
            });

            return response()->json([
                'draft_saved' => true,
                'draft_id' => $lead->lead_id,
                'saved_at' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Visa intake draft save failed', ['visa' => $visaType, 'error' => $e->getMessage()]);

            return response()->json(['error' => 'Could not save draft.'], 500);
        }
    }
}
