<?php

namespace App\Services\Immigration;

use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\VisaType;
use Illuminate\Support\Collection;

/**
 * Build 11.D Phase 4 — Visa-type checklist resolver.
 *
 * Reads from the existing checklist mechanism (no migration, no new
 * tables) — documented in Phase 1's audit:
 *
 *   VisaType.checklist_items is JSON shaped [{key, label, hint, required, category?}].
 *   LeadDocument.checklist_key matches VisaType.checklist_items[].key for the join.
 *   Documents have a `note` column (NOT reviewer_notes — the Phase 4 spec used
 *   the wrong name; the actual schema is `note` per LeadDocument.php:24 and
 *   the create-table migration).
 *
 * Three resolution levels for forCase():
 *   1. per-lead override (Lead.document_checklist JSON, when populated)
 *   2. visa-type catalog (VisaType.checklist_items by inz_visa_type string match)
 *   3. empty fallback
 *
 * Unknown / unmatched visa types degrade gracefully — return [] so the
 * UI falls back to its "no checklist, show uploaded files" mode without
 * crashing.
 */
class CaseChecklistService
{
    /**
     * Raw checklist items for the case. Resolution order above. Items are
     * normalised so the consumer can rely on `key` + `label` being present.
     *
     * @return array<int, array<string, mixed>>
     */
    /**
     * Documents that apply to EVERY case regardless of visa type, pinned to
     * the front of the checklist. Staff hide/show them per-case exactly like
     * any other item; the public tracker (LeadTrackingController) mirrors the
     * same list — KEEP IN SYNC.
     */
    private const UNIVERSAL_ITEMS = [
        ['key' => 'svf', 'label' => 'Visa Information Form', 'hint' => 'Visa information form prepared with your adviser.', 'required' => true, 'category' => 'Information Form'],
    ];

    public function forCase(Lead $lead): array
    {
        $base = $this->consolidateAliases($lead, $this->baseChecklist($lead));

        // Prepend the universal items (deduping any the visa list already has)
        // so they show — and can be hidden/shown — on every case.
        $universalKeys = array_column(self::UNIVERSAL_ITEMS, 'key');
        $base = array_values(array_filter(
            $base,
            fn ($i) => ! in_array($i['key'] ?? null, $universalKeys, true)
        ));

        return array_merge($this->normaliseItems($this->universalItemsFor($lead)), $base);
    }

    /** Historical PRSWV sponsor visa keys describe one requirement; retain both upload keys. */
    public function consolidateAliases(Lead $lead, array $items): array
    {
        $keys = array_column($items, 'key');
        if ($this->resolveVisaType($lead)?->code !== 'PRSWV'
            || ! in_array('sponsor_nz_visa', $keys, true)) {
            return $items;
        }

        return collect($items)->reject(fn ($item) => ($item['key'] ?? null) === 'nz_visa')
            ->map(function ($item) {
                if (($item['key'] ?? null) === 'sponsor_nz_visa') {
                    $item['aliases'] = ['nz_visa'];
                }
                return $item;
            })->values()->all();
    }

    public function itemKeys(array $item): array
    {
        return array_merge([$item['key']], $item['aliases'] ?? []);
    }

    /** Hiding either historical key must also hide its equivalent uploads. */
    public function hiddenKeysFor(Lead $lead): array
    {
        $hidden = is_array($lead->hidden_track_documents) ? $lead->hidden_track_documents : [];
        foreach ($this->forCase($lead) as $item) {
            $keys = $this->itemKeys($item);
            if (array_intersect($keys, $hidden)) {
                $hidden = array_merge($hidden, $keys);
            }
        }
        return array_values(array_unique($hidden));
    }

    /**
     * Universal items with per-lead label tweaks: the Information Form reads
     * "Student Visa Information Form" for student visas, plain "Visa
     * Information Form" everywhere else.
     */
    private function universalItemsFor(Lead $lead): array
    {
        $isStudent = str_contains(strtolower((string) $lead->inz_visa_type), 'student');

        return array_map(function ($item) use ($isStudent) {
            if (($item['key'] ?? null) === 'svf' && $isStudent) {
                $item['label'] = 'Student Visa Information Form';
            }

            return $item;
        }, self::UNIVERSAL_ITEMS);
    }

    /**
     * Resolve the catalogue row for a lead's visa. THE single source of
     * truth — the staff dashboard and the public tracker must land on the
     * same VisaType or their checklists silently disagree.
     *
     * An exact `name` match always wins; `code` is only a fallback for
     * leads stamped with a visa code. Matching on "name OR code" in one
     * query is unsafe: once any visa's code equals another visa's name the
     * database is free to return either row.
     */
    public function resolveVisaType(Lead $lead): ?VisaType
    {
        if (empty($lead->inz_visa_type)) {
            return null;
        }

        return VisaType::query()->where('name', $lead->inz_visa_type)->first()
            ?? VisaType::query()->where('code', $lead->inz_visa_type)->first();
    }

    /** The visa-type / per-lead / config checklist before universal items. */
    private function baseChecklist(Lead $lead): array
    {
        // 1. Per-lead override. The Lead model has `document_checklist`
        //    (JSON cast) for cases where the standard visa-type list
        //    has been customised for this applicant.
        if (! empty($lead->document_checklist) && is_array($lead->document_checklist)) {
            return $this->normaliseItems($lead->document_checklist);
        }

        // 2. Visa-type catalog. inz_visa_type is a free-form string column
        //    (no FK), so an unknown value just resolves to null and we
        //    fall through to the empty fallback.
        if (! empty($lead->inz_visa_type)) {
            $visaType = $this->resolveVisaType($lead);
            if ($visaType && ! empty($visaType->checklist_items) && is_array($visaType->checklist_items)) {
                return $this->normaliseItems($visaType->checklist_items);
            }
        }

        // 3. Empty fallback — UI shows the "no structured checklist" panel.
        return [];
    }

    /**
     * Resolution source descriptor — lets the UI display "Source: visa_type · Student Visa".
     *
     * @return array{source: string, visa: ?string}
     */
    public function sourceFor(Lead $lead): array
    {
        if (! empty($lead->document_checklist) && is_array($lead->document_checklist)) {
            return ['source' => 'lead_override', 'visa' => $lead->inz_visa_type];
        }
        if (! empty($lead->inz_visa_type)) {
            $hit = $this->resolveVisaType($lead);
            if ($hit && ! empty($hit->checklist_items)) {
                return ['source' => 'visa_type', 'visa' => $hit->name];
            }
        }

        return ['source' => 'none', 'visa' => $lead->inz_visa_type];
    }

    /**
     * Checklist items with status from the case's LeadDocuments attached.
     * Most-recent doc wins per checklist_key. Items without an upload get
     * `status = 'not_submitted'` and a null document_id.
     *
     * @return array<int, array<string, mixed>>
     */
    public function withStatuses(Lead $lead): array
    {
        $items = $this->forCase($lead);
        if (empty($items)) {
            return [];
        }

        $docsByKey = $this->latestDocumentsByKey($lead);
        $hidden = $this->hiddenKeysFor($lead);

        return collect($items)->map(function (array $item) use ($docsByKey, $hidden) {
            $doc = collect($this->itemKeys($item))->map(fn ($key) => $docsByKey->get($key))->filter()->sortByDesc('created_at')->first();

            return [
                'key' => $item['key'],
                'aliases' => $item['aliases'] ?? [],
                'label' => $item['label'],
                'hint' => $item['hint'] ?? null,
                'required' => (bool) ($item['required'] ?? false),
                // Default category — Phase 1's documented JSON shape doesn't
                // include `category`. Seed labels embed it as "Category · Name"
                // so the frontend can parse it for grouping; backend just
                // surfaces whatever the JSON says.
                'category' => $item['category'] ?? null,
                'status' => $doc?->status ?? 'not_submitted',
                'uploaded_at' => $doc?->created_at?->toIso8601String(),
                'note' => $doc?->note,
                'document_id' => $doc?->id,
                // True when staff have hidden this item from the applicant's
                // public tracking link.
                'hidden' => in_array($item['key'], $hidden, true),
            ];
        })->toArray();
    }

    /**
     * withStatuses() result grouped by category. When no `category` field is
     * present on items, everything lands under "Required Documents" (single
     * group) per the Phase 4 spec — we don't invent categories from key
     * prefixes because it's brittle.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function groupedByCategory(Lead $lead): array
    {
        $items = $this->withStatuses($lead);
        if (empty($items)) {
            return [];
        }

        return collect($items)
            ->groupBy(fn (array $item) => $item['category'] ?: 'Required Documents')
            ->map(fn (Collection $group) => $group->values()->toArray())
            ->toArray();
    }

    /**
     * Documents that don't tie back to any checklist item — either
     * uploaded with a null checklist_key, or uploaded against a key that
     * the current visa-type checklist no longer recognises (e.g. visa
     * type changed mid-case). The UI surfaces these under an "Other"
     * group so nothing is hidden from staff.
     *
     * @return array<int, array<string, mixed>>
     */
    public function unstructuredDocuments(Lead $lead): array
    {
        $knownKeys = collect($this->forCase($lead))->flatMap(fn ($item) => $this->itemKeys($item))->filter()->all();
        $known = array_flip($knownKeys);

        return $lead->documents()
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (LeadDocument $d) => empty($d->checklist_key) || ! isset($known[$d->checklist_key]))
            ->map(fn (LeadDocument $d) => [
                'document_id' => $d->id,
                'original_name' => $d->original_name,
                'status' => $d->status,
                'uploaded_at' => $d->created_at?->toIso8601String(),
                'checklist_key' => $d->checklist_key, // null = truly unstructured
                'note' => $d->note,
            ])
            ->values()
            ->toArray();
    }

    /**
     * Compact progress summary for the tab header. Only REQUIRED items
     * count toward the denominator — optional items don't move the needle
     * on "case ready for submission".
     *
     * @return array{required_total: int, required_approved: int, total: int, approved: int}
     */
    public function progress(Lead $lead): array
    {
        $items = $this->withStatuses($lead);
        $required = array_values(array_filter($items, fn ($i) => ! empty($i['required'])));

        return [
            'required_total' => count($required),
            'required_approved' => count(array_filter($required, fn ($i) => $i['status'] === LeadDocument::STATUS_APPROVED)),
            'total' => count($items),
            'approved' => count(array_filter($items, fn ($i) => $i['status'] === LeadDocument::STATUS_APPROVED)),
        ];
    }

    /**
     * Build the tidy upload filename for a checklist item, or null when the
     * item carries no `file_code` (in which case callers keep the original
     * filename). Format: "NN - CODE - FirstnameLASTNAME<suffix>.<ext>" where
     * NN is the item's 1-based position in the resolved checklist.
     */
    public function uploadFileNameFor(Lead $lead, string $key, string $originalName): ?string
    {
        $items = $this->forCase($lead);
        if (empty($items)) {
            return null;
        }

        $index = null;
        $match = null;
        foreach (array_values($items) as $i => $item) {
            if (($item['key'] ?? null) === $key) {
                $index = $i;
                $match = $item;
                break;
            }
        }

        if ($match === null) {
            return null;
        }

        $base = null;

        // Filename format precedence:
        //   1. the item's own override (filename), else
        //   2. the visa's standard pattern (filename_pattern), else
        //   3. the legacy file_code/file_suffix scheme, else keep original.
        // Placeholders are substituted case-insensitively; surrounding text is
        // kept verbatim so staff have full control over the format.
        $template = trim((string) ($match['filename'] ?? ''));
        if ($template === '') {
            $visa = $lead->inz_visa_type
                ? \App\Models\VisaType::where('name', $lead->inz_visa_type)->first()
                : null;
            $template = trim((string) ($visa?->filename_pattern ?? ''));
        }

        if ($template !== '') {
            $replacements = [
                '{name}' => trim("{$lead->first_name} {$lead->last_name}"),
                '{date}' => now()->format('j M Y'),
                '{visa}' => (string) ($lead->inz_visa_type ?? ''),
                '{code}' => (string) ($lead->lead_id ?? ''),
                '{label}' => (string) ($match['label'] ?? ''),
            ];
            $base = str_ireplace(array_keys($replacements), array_values($replacements), $template);
        } elseif (! empty($match['file_code'])) {
            // Legacy scheme: "NN - CODE - FirstnameLASTNAME<suffix>".
            $position = str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
            $code = $match['file_code'];
            $suffix = $match['file_suffix'] ?? '';
            $name = $this->applicantFileName($lead);
            $base = "{$position} - {$code} - {$name}{$suffix}";
        }

        if ($base === null) {
            return null;
        }

        // Strip characters that are illegal in filenames (e.g. a "/" in a
        // suffix like "_Name of Family Member/Friend") so storeAs() can't be
        // tricked into a nested path, and collapse whitespace.
        $base = trim(preg_replace('#\s+#', ' ', preg_replace('#[\\\\/:*?"<>|]+#', '-', $base)));

        if ($base === '') {
            return null;
        }

        $ext = pathinfo($originalName, PATHINFO_EXTENSION);

        return $ext !== '' ? "{$base}.{$ext}" : $base;
    }

    /**
     * Applicant name formatted as FirstnameLASTNAME (first name as stored,
     * last name upper-cased, spaces stripped). Falls back to "Applicant".
     */
    private function applicantFileName(Lead $lead): string
    {
        $first = preg_replace('/\s+/', '', (string) $lead->first_name);
        $last = strtoupper(preg_replace('/\s+/', '', (string) $lead->last_name));
        $name = trim($first.$last);

        return $name !== '' ? $name : 'Applicant';
    }

    /**
     * Newest LeadDocument per checklist_key — used by withStatuses() to
     * decorate each row. Documents with no checklist_key are excluded
     * (they show up in unstructuredDocuments() instead).
     */
    private function latestDocumentsByKey(Lead $lead): Collection
    {
        return $lead->documents()
            ->whereNotNull('checklist_key')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('checklist_key')
            ->map(fn (Collection $docs) => $docs->first());
    }

    /**
     * Defensive: drop malformed checklist items missing `key` or `label`
     * so a typo in the JSON doesn't crash the controller. Re-indexes.
     */
    private function normaliseItems(array $items): array
    {
        return collect($items)
            ->filter(fn ($item) => is_array($item) && ! empty($item['key']) && isset($item['label']))
            ->values()
            ->toArray();
    }
}
