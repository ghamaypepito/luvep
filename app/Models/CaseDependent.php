<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A dependant included in a principal applicant's immigration case (child /
 * partner / etc.). No login — managed by the principal in the lead portal and
 * by staff in the case profile. Documents attach via lead_documents.dependent_id.
 */
class CaseDependent extends Model
{
    public const RELATIONSHIPS = ['child', 'partner', 'parent', 'sibling', 'other'];

    protected $fillable = [
        'lead_id', 'linked_lead_id', 'visa_type_id', 'relationship', 'in_agreement', 'fee_override', 'disbursement_override', 'family_name', 'first_name', 'middle_name',
        'dob', 'gender', 'nationality', 'passport_number', 'passport_expiry',
        'source', 'notes', 'added_by',
    ];

    protected $casts = [
        'dob' => 'date',
        'passport_expiry' => 'date',
        'in_agreement' => 'boolean',
        'fee_override' => 'decimal:2',
        'disbursement_override' => 'decimal:2',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** The child's OWN case (Lead), when this dependant is tied to a real case. */
    public function linkedLead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'linked_lead_id');
    }

    /** The visa assigned to this dependant (by staff) — drives their checklist. */
    public function visaType(): BelongsTo
    {
        return $this->belongsTo(VisaType::class, 'visa_type_id');
    }

    /**
     * The checklist items this dependant must satisfy: the assigned visa's
     * `checklist_items` (same catalogue cases use). Empty when no visa is set —
     * the UI then prompts that the adviser will set the visa type.
     *
     * @return array<int, array{key:string,label:string,required:bool,hint?:string}>
     */
    public function checklistItems(): array
    {
        if ($this->visa_type_id && $this->visaType && is_array($this->visaType->checklist_items)) {
            return collect($this->visaType->checklist_items)
                ->filter(fn ($i) => is_array($i) && ! empty($i['key']) && isset($i['label']))
                ->values()->all();
        }

        return [];
    }

    /** Valid checklist_key values for uploads against this dependant. */
    public function checklistKeys(): array
    {
        return array_column($this->checklistItems(), 'key');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(LeadDocument::class, 'dependent_id');
    }

    /** "First Last" (or a placeholder) — never a guessed value. */
    public function fullName(): string
    {
        $name = trim("{$this->first_name} {$this->family_name}");
        if ($name === '' && $this->canReadLinkedCase()) {
            // The case-linked form permits an empty local identity snapshot.
            $name = trim("{$this->linkedLead->first_name} {$this->linkedLead->last_name}");
        }

        return $name ?: 'Unnamed dependant';
    }

    /** Apply the recruiting agent's row scope before following a case link. */
    public function canReadLinkedCase(): bool
    {
        return $this->linked_lead_id && $this->linkedLead?->is_immigration_case
            && (auth()->user()?->role !== 'agent' || $this->linkedLead->agent_id === auth()->id());
    }

    /**
     * Resolve this dependant's document checklist (by relationship) against
     * their uploaded documents: per-item status, any extras, and progress.
     * Shared by the case profile (staff) and the lead portal (principal).
     *
     * @return array{checklist: array<int, mixed>, other_documents: array<int, mixed>, progress: array<string, int>}
     */
    public function checklistData(): array
    {
        // Tied to the child's OWN case: read that case's live visa checklist +
        // the documents the child submitted there (read-through the link), so the
        // parent sees exactly what the child uploaded on their own case.
        if ($this->canReadLinkedCase()) {
            return $this->linkedCaseChecklist($this->linkedLead);
        }

        // Checklist is driven by the visa assigned to this dependant (by staff).
        $items = $this->checklistItems();
        $byKey = $this->documents->whereNotNull('checklist_key')->groupBy('checklist_key');

        $serialize = fn (LeadDocument $doc) => [
            'id' => $doc->id,
            'original_name' => $doc->original_name,
            'mime' => $doc->mime,
            'size' => $doc->size,
            'status' => $doc->status,
            'note' => $doc->note,
            'created_at' => optional($doc->created_at)->toIso8601String(),
        ];

        $requiredTotal = 0;
        $requiredDone = 0;
        $checklist = [];
        foreach ($items as $item) {
            $required = (bool) ($item['required'] ?? false);
            $doc = ($byKey[$item['key']] ?? collect())->first(); // newest first
            $satisfied = $doc && $doc->status !== 'Rejected';
            if ($required) {
                $requiredTotal++;
                $requiredDone += $satisfied ? 1 : 0;
            }
            $checklist[] = [
                'key' => $item['key'],
                'label' => $item['label'],
                'hint' => $item['hint'] ?? null,
                'required' => $required,
                'status' => $doc?->status ?? 'Missing',
                'document' => $doc ? $serialize($doc) : null,
            ];
        }

        $keys = array_column($items, 'key');
        $other = $this->documents
            ->filter(fn ($doc) => $doc->checklist_key === null || ! in_array($doc->checklist_key, $keys, true))
            ->map($serialize)->values();

        return [
            'checklist' => $checklist,
            'other_documents' => $other,
            'progress' => ['required_total' => $requiredTotal, 'required_done' => $requiredDone],
            // No visa assigned yet → the UI shows an "adviser will set your visa"
            // prompt instead of an empty checklist.
            'needs_visa' => empty($items) && ! $this->visa_type_id,
            'visa_name' => $this->visaType?->name,
        ];
    }

    /**
     * Read the child's OWN case (Lead): its live visa checklist + the documents
     * the child submitted there. Same shape as checklistData() so the Family UI
     * renders it identically — but flagged `linked` so the parent sees it
     * read-only (the child manages uploads on their own case/portal).
     *
     * @return array{checklist: array<int, mixed>, other_documents: array<int, mixed>, progress: array<string, int>, linked: bool, linked_lead_id: int}
     */
    protected function linkedCaseChecklist(Lead $child): array
    {
        $items = app(\App\Services\Immigration\CaseChecklistService::class)->withStatuses($child);

        $docIds = collect($items)->pluck('document_id')->filter()->values()->all();
        $docs = LeadDocument::whereIn('id', $docIds)->get()->keyBy('id');

        $serialize = fn (LeadDocument $doc) => [
            'id' => $doc->id,
            'original_name' => $doc->original_name,
            'mime' => $doc->mime,
            'size' => $doc->size,
            'status' => $doc->status,
            'note' => $doc->note,
            'created_at' => optional($doc->created_at)->toIso8601String(),
        ];

        $requiredTotal = 0;
        $requiredDone = 0;
        $checklist = [];
        foreach ($items as $item) {
            $doc = ($item['document_id'] ?? null) ? $docs->get($item['document_id']) : null;
            $satisfied = $doc && $doc->status !== 'Rejected';
            if (! empty($item['required'])) {
                $requiredTotal++;
                $requiredDone += $satisfied ? 1 : 0;
            }
            $checklist[] = [
                'key' => $item['key'],
                'label' => $item['label'],
                'hint' => $item['hint'] ?? null,
                'required' => (bool) ($item['required'] ?? false),
                'status' => $doc?->status ?? 'Missing',
                'document' => $doc ? $serialize($doc) : null,
            ];
        }

        // Any other documents the child UPLOADED that aren't on their case
        // checklist. Deliberately excludes staff-generated artifacts (agreements,
        // invoices, engagement packs) — only what the child submitted is shared.
        $knownIds = collect($items)->pluck('document_id')->filter()->all();
        $other = $child->documents()
            ->whereNotIn('id', $knownIds ?: [0])
            ->whereNull('dependent_id')
            ->whereNotIn('source', ['generated', 'engagement'])
            ->latest()->get()
            ->map($serialize)->values();

        return [
            'checklist' => $checklist,
            'other_documents' => $other,
            'progress' => ['required_total' => $requiredTotal, 'required_done' => $requiredDone],
            'linked' => true,
            'linked_lead_id' => $child->id,
        ];
    }
}
