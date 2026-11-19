<?php

namespace App\Services\Immigration;

use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\MediaFile;
use App\Models\PaymentProofReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/** A separate capability; never broaden referral, case-profile or MediaDrive scopes. */
class AssignedCaseAccess
{
    public const MODULE = 'assigned_immigration_cases';

    /** Closed primary-evidence classes from the maintained visa checklists. */
    private const PRIMARY_EVIDENCE_KEYS = [
        'passport', 'passport_pdf', 'passport_bio_page', 'face_image', 'face_image_jpg', 'birth_certificate',
        'cv', 'degree', 'graduate_certificate', 'transcript', 'attendance_report', 'offer_of_place',
        'pte', 'pte_ielts_result', 'english_proof', 'nzqa_assessment', 'nz_registration',
        'medical_certificate', 'nbi_police_clearance', 'police_certificate', 'police_clearance', 'police_nbi_clearance',
        'applicant_nz_visa', 'current_aewv_copy', 'current_nz_visa', 'current_visa', 'current_work_visa',
        'nz_visa', 'resident_visa_copy', 'student_visa_copy', 'travel_plan', 'travel_records', 'statement_of_purpose',
    ];

    private ?array $primaryCatalog = null;

    public function eligible(User $user): bool
    {
        return $user->is_active && $user->role === 'agent' && $user->canSeeModule(self::MODULE);
    }

    public function actor(User $user, bool $lock = false): User
    {
        $query = User::whereKey($user->id);
        $fresh = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
        abort_unless($this->eligible($fresh), 403);

        return $fresh;
    }

    public function cases(User $actor): Builder
    {
        return Lead::immigrationCase()->where('immigration_helper_id', $actor->id);
    }

    public function find(User $actor, int $id, bool $lock = false): Lead
    {
        $query = $this->cases($actor)->whereKey($id);
        $case = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
        $this->actor($actor, $lock);

        return $case;
    }

    public function summary(Lead $case): array
    {
        return ['id' => $case->id, 'reference' => $case->lead_id,
            'name' => trim($case->first_name.' '.$case->last_name), 'visa' => $case->inz_visa_type,
            'stage' => $case->immigration_stage, 'updated_at' => $case->updated_at?->toIso8601String()];
    }

    /** Trusted purpose/person metadata only. A filename never establishes access. */
    private function safePurpose(array $metadata): bool
    {
        if (isset($metadata['aliases']) && ! is_array($metadata['aliases'])) {
            return false;
        }
        $keys = array_merge([(string) ($metadata['key'] ?? '')], $metadata['aliases'] ?? []);
        foreach ($keys as $key) {
            if (! is_string($key) || in_array($key, ['svf', 'rfi'], true) || str_starts_with($key, 'agree.')) {
                return false;
            }
        }
        // Preserve the existing other-person key conventions documented by
        // PassportPrefillService, also checking labels/categories and aliases.
        $fields = array_intersect_key($metadata, array_flip(['key', 'aliases', 'label', 'description', 'category',
            'file_code', 'file_suffix', 'person', 'person_type', 'applicant_type', 'subject', 'purpose', 'document_type']));
        foreach (\Illuminate\Support\Arr::flatten($fields) as $value) {
            if ($value === null) {
                continue;
            }
            if (! is_string($value)) {
                return false;
            }
            $words = strtolower(preg_replace('/[^\pL\pN]+/u', ' ', preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $value)));
            if (preg_match('/\b(?:financ\w*|invoice\w*|payment\w*|receipt\w*|bank\w*|fund\w*|payslip\w*|payroll|salary|salaries|earnings|income|tax\w*|ird|investment\w*|asset\w*|property|tuition|fees?|expenses?|bills?|savings|loans?|mortgage\w*|credit|debit|insurance|remittance\w*|bnks|pyslps|sof|eop|fin)\b/u', $words)
                || preg_match('/\b(?:signature\w*|sign|signed|signing|specimen|agreement\w*|engagement\w*|declaration\w*|consent|authority|authorisation|authorization)\b/u', $words)
                || preg_match('/\b(?:sponsor\w*|partner\w*|spouse\w*|depend[ae]nt\w*|child\w*|spfin|part|parents?|family|friends?|couple|joint|relatives?|supporters?|supporting|marriage|relationship|coapplicant|husband|wife|guardians?)\b/u', $words)) {
                return false;
            }
        }
        foreach (['person', 'person_type', 'applicant_type', 'subject'] as $field) {
            if (array_key_exists($field, $metadata) && ! in_array($metadata[$field], ['primary', 'applicant', 'primary_applicant', 'primary applicant'], true)) {
                return false;
            }
        }

        return true;
    }

    private function safeChecklistPurpose(Lead $case, ?string $key, array $classification = []): bool
    {
        if (! $this->safePurpose(['key' => $key ?? ''])) {
            return false;
        }
        $resolver = app(CaseChecklistService::class);
        $matched = false;
        foreach ($resolver->forCase($case) as $item) {
            if (in_array($key, $resolver->itemKeys($item), true)) {
                $matched = true;
                if (! $this->primaryItem($item) || ! $this->primaryItem(array_replace($item, $classification))) {
                    return false;
                }
            }
        }

        return $matched;
    }

    private function primaryItem(array $item): bool
    {
        $key = $item['key'] ?? null;
        if (! in_array($key, self::PRIMARY_EVIDENCE_KEYS, true) || ! is_string($item['label'] ?? null)
            || trim($item['label']) === '' || ! $this->safePurpose($item)) {
            return false;
        }
        if ($this->primaryCatalog === null) {
            $this->primaryCatalog = [];
            // The same maintained templates power the staff checklist picker.
            // New keys/descriptors do not qualify merely because free text looks safe.
            foreach ((new \Database\Seeders\VisaChecklistSeeder)->templates() as $template) {
                foreach ($template['items'] as $known) {
                    if (in_array($known['key'], self::PRIMARY_EVIDENCE_KEYS, true) && $this->safePurpose($known)) {
                        $this->primaryCatalog[$known['key']][] = $known;
                    }
                }
            }
        }
        $known = $this->primaryCatalog[$key] ?? [];
        if (! $known) {
            return false;
        }
        $labels = array_merge(array_column($known, 'label'), [$key, 'Applicant '.$key, 'Primary applicant '.$key]);
        foreach (['label', 'category', 'hint', 'file_code', 'file_suffix', 'description', 'purpose', 'document_type'] as $field) {
            if (! isset($item[$field]) || $item[$field] === '') {
                continue;
            }
            $values = match ($field) {
                'label', 'description', 'purpose', 'document_type' => $labels,
                default => array_column($known, $field),
            };
            if (! is_string($item[$field]) || ! in_array($this->descriptor($item[$field]), array_map($this->descriptor(...), $values), true)) {
                return false;
            }
        }
        foreach ($item['aliases'] ?? [] as $alias) {
            if (! in_array($alias, [$key, 'legacy_'.$key], true)) {
                return false;
            }
        }

        return true;
    }

    private function descriptor(string $value): string
    {
        return trim(mb_strtolower(preg_replace('/[^\pL\pN]+/u', ' ', $value)));
    }

    public function visibleItems(Lead $case): array
    {
        $resolver = app(CaseChecklistService::class);
        $hidden = $resolver->hiddenKeysFor($case);
        $items = $resolver->forCase($case);
        $deniedKeys = collect($items)->reject(fn ($item) => $this->primaryItem($item))
            ->flatMap(fn ($item) => $resolver->itemKeys($item))->all();

        return array_values(array_filter($items, fn ($item) => ! array_intersect($resolver->itemKeys($item), array_merge($hidden, $deniedKeys))
            && $this->primaryItem($item)));
    }

    public function slots(Lead $case): array
    {
        return array_values(array_map(fn ($item) => ['key' => $item['key'], 'label' => $item['label']],
            array_filter($this->visibleItems($case), fn ($item) => ! empty($item['required']))));
    }

    /** Request labels/origins contain no structured person/purpose binding. */
    private function documentPurposeAllowed(Lead $case, LeadDocument $doc): bool
    {
        if ((int) $doc->lead_id !== (int) $case->id || $doc->dependent_id || $doc->request_id || $doc->source !== LeadDocument::SOURCE_UPLOAD
            || $doc->invoice_number !== null || $doc->invoice_total !== null || $doc->client_signed_at || $doc->client_signature_path) {
            return false;
        }
        foreach (['invoice_finance', 'payment_proof', 'signature_upload', 'staff_signature_upload', 'engagement_payload', 'engagement_pack_id'] as $metadata) {
            if (isset($doc->notes[$metadata])) {
                return false;
            }
        }
        if ($doc->receivable()->exists() || $doc->invoicePayments()->exists()
            || PaymentProofReview::where(fn ($query) => $query->where('document_id', $doc->id)->orWhere('invoice_document_id', $doc->id))->exists()) {
            return false;
        }
        $variant = (string) $doc->source_variant;
        if ($variant !== '' && ! in_array($variant, ['rfi', 'medical', 'decline'], true) && ! str_starts_with($variant, 'outcome:')) {
            return false;
        }
        $resolver = app(CaseChecklistService::class);
        if ($doc->checklist_key && in_array($doc->checklist_key, $resolver->hiddenKeysFor($case), true)) {
            return false;
        }
        $classification = array_intersect_key($doc->notes ?? [], array_flip(['category', 'person', 'person_type', 'applicant_type', 'subject', 'purpose', 'document_type']));
        if (! $this->safeChecklistPurpose($case, $doc->checklist_key, $classification)) {
            return false;
        }

        return true;
    }

    public function canRead(Lead $case, LeadDocument $doc): bool
    {
        if (! $this->documentPurposeAllowed($case, $doc)) {
            return false;
        }
        if ($doc->media_file_id) {
            $file = MediaFile::with('folder.root')->find($doc->media_file_id);
            $folder = $file?->folder;
            $root = $folder?->root_id ? $folder->root : $folder;
            if (! $root || (int) $root->lead_id !== (int) $case->id || $root->client_key !== 'lead:'.$case->id
                || $root->tenant_id || $file->file_path !== $doc->file_path) {
                return false;
            }
        }

        return filled($doc->file_path) && Storage::disk('local')->exists($doc->file_path);
    }
}
