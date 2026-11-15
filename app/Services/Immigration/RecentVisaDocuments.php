<?php

namespace App\Services\Immigration;

use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\LeadDocument;
use Illuminate\Support\Facades\DB;

/** Explicit staff classification; never infer purpose from filenames. */
class RecentVisaDocuments
{
    public function move(Lead $case, int $documentId): void
    {
        DB::transaction(function () use ($case, $documentId) {
            $lead = Lead::whereKey($case->id)->lockForUpdate()->firstOrFail();
            abort_unless($lead->is_immigration_case, 404);
            $document = $lead->documents()->whereKey($documentId)->lockForUpdate()->firstOrFail();
            abort_if($document->dependent_id || $document->request_id || $document->checklist_key === 'rfi'
                || $document->source === LeadDocument::SOURCE_GENERATED
                || (filled($document->source_variant) && ! str_starts_with($document->source_variant, 'outcome:')), 422, 'This document belongs to a separate workflow.');

            $resolver = app(CaseChecklistService::class);
            $items = $resolver->forCase($lead);
            $known = collect($items)->flatMap(fn ($item) => $resolver->itemKeys($item))->all();
            abort_if($document->checklist_key !== 'recent_visa'
                && in_array($document->checklist_key, $known, true), 422, 'This document already has a checklist category.');

            $oldKey = $document->checklist_key;
            $added = ! in_array('recent_visa', $known, true);
            if ($added) {
                // Use the existing per-case override; do not modify global templates.
                $items[] = ['key' => 'recent_visa', 'label' => 'Recent visa / eVisa',
                    'hint' => 'Recent visa grant or eVisa issued for this client.',
                    'required' => false, 'category' => 'Visa applications & decisions'];
                $lead->document_checklist = $items;
                // A classification must not automatically publish a new tracker row.
                $lead->hidden_track_documents = array_values(array_unique(array_merge(
                    $lead->hidden_track_documents ?? [], ['recent_visa']
                )));
                $lead->save();
            }

            if ($oldKey !== 'recent_visa') {
                $document->checklist_key = 'recent_visa';
                $document->save();
            }
            if ($added || $oldKey !== 'recent_visa') {
                ActivityLog::record('case.document.categorized', [
                    'description' => 'Moved an unmatched document to Recent visa / eVisa.',
                    'properties' => ['subject_type' => 'Lead', 'subject_id' => $lead->id,
                        'document_id' => $document->id, 'old_checklist_key' => $oldKey,
                        'new_checklist_key' => 'recent_visa'],
                ]);
            }
        });
    }
}
