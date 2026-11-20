<?php

namespace App\Services\Immigration;

use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\LeadDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Explicit staff classification; never infer purpose from filenames. */
class RecentVisaDocuments
{
    /** Add an empty client upload target, without classifying any existing file. */
    public function addCategory(Lead $case): bool
    {
        return DB::transaction(function () use ($case) {
            $lead = Lead::whereKey($case->id)->lockForUpdate()->firstOrFail();
            abort_unless($lead->is_immigration_case, 404);
            $resolver = app(CaseChecklistService::class);
            $items = $resolver->forCase($lead);
            $known = collect($items)->flatMap(fn ($item) => $resolver->itemKeys($item))->all();
            if (in_array('recent_visa', $known, true)) {
                return false; // Never change an existing row's visibility on retry.
            }
            if (in_array('recent_visa', $resolver->hiddenKeysFor($lead), true)
                || $lead->documents()->where('checklist_key', 'recent_visa')->exists()) {
                throw ValidationException::withMessages(['category' => 'Review the existing Recent visa / eVisa files or visibility setting before adding this client upload category.']);
            }

            $this->createCategory($lead, $items, false);
            ActivityLog::record('case.document.category_added', [
                'description' => 'Added an optional Recent visa / eVisa client upload category.',
                'properties' => ['subject_type' => 'Lead', 'subject_id' => $lead->id,
                    'checklist_key' => 'recent_visa', 'client_visible' => true],
            ]);

            return true;
        });
    }

    public function move(Lead $case, int $documentId): void
    {
        DB::transaction(function () use ($case, $documentId) {
            $lead = Lead::whereKey($case->id)->lockForUpdate()->firstOrFail();
            abort_unless($lead->is_immigration_case, 404);
            $document = $lead->documents()->whereKey($documentId)->lockForUpdate()->firstOrFail();
            $oldKey = $document->checklist_key;
            if ($document->dependent_id || $document->request_id || $oldKey === 'rfi'
                || $document->source !== LeadDocument::SOURCE_UPLOAD
                || (filled($document->source_variant) && ! str_starts_with($document->source_variant, 'outcome:'))) {
                throw ValidationException::withMessages(['document' => 'This document belongs to a separate workflow.']);
            }

            $resolver = app(CaseChecklistService::class);
            $items = $resolver->forCase($lead);
            $known = collect($items)->flatMap(fn ($item) => $resolver->itemKeys($item))->all();
            if ($oldKey !== 'recent_visa' && in_array($oldKey, $known, true)) {
                throw ValidationException::withMessages(['document' => 'This document already has a checklist category.']);
            }

            $added = ! in_array('recent_visa', $known, true);
            $hiddenKeys = $resolver->hiddenKeysFor($lead);
            if (! $added && in_array($oldKey, $hiddenKeys, true) && ! in_array('recent_visa', $hiddenKeys, true)) {
                throw ValidationException::withMessages(['document' => 'Hide the Recent visa / eVisa row before moving this hidden file.']);
            }
            if ($added) {
                // A classification must not automatically publish a new tracker row.
                $this->createCategory($lead, $items, true);
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

    private function createCategory(Lead $lead, array $resolvedItems, bool $hidden): void
    {
        // Preserve unstructured planning notes stored beside checklist items.
        $items = $lead->document_checklist ?: $resolvedItems;
        $items[] = ['key' => 'recent_visa', 'label' => 'Recent visa / eVisa',
            'hint' => 'Recent visa grant or eVisa issued for this client.',
            'required' => false, 'category' => 'Visa applications & decisions'];
        $lead->document_checklist = $items;
        if ($hidden) {
            $lead->hidden_track_documents = array_values(array_unique([...((array) $lead->hidden_track_documents), 'recent_visa']));
        }
        $lead->save();
    }
}
