<?php

namespace App\Services\Immigration;

use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\LeadDocumentRequest;
use App\Models\MediaFile;
use App\Models\PaymentProofReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/** A separate capability; never broaden referral, case-profile or MediaDrive scopes. */
class AssignedCaseAccess
{
    public const MODULE = 'assigned_immigration_cases';

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
                || preg_match('/\b(?:signature\w*|sign|signed|signing|specimen|agreement\w*|engagement\w*|declaration\w*|forms?|consent|authority|authorisation|authorization)\b/u', $words)
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

    private function safeChecklistPurpose(Lead $case, ?string $key): bool
    {
        if (! $this->safePurpose(['key' => $key ?? ''])) {
            return false;
        }
        $resolver = app(CaseChecklistService::class);
        foreach ($resolver->forCase($case) as $item) {
            if (in_array($key, $resolver->itemKeys($item), true) && ! $this->safePurpose($item)) {
                return false; // A generic request cannot override a disallowed item or alias.
            }
        }

        return true;
    }

    public function visibleItems(Lead $case): array
    {
        $resolver = app(CaseChecklistService::class);
        $hidden = $resolver->hiddenKeysFor($case);
        $items = $resolver->forCase($case);
        $deniedKeys = collect($items)->reject(fn ($item) => $this->safePurpose($item))
            ->flatMap(fn ($item) => $resolver->itemKeys($item))->all();

        return array_values(array_filter($items, fn ($item) => ! array_intersect($resolver->itemKeys($item), array_merge($hidden, $deniedKeys))
            && $this->safePurpose($item)));
    }

    public function slots(Lead $case): array
    {
        return array_values(array_map(fn ($item) => ['key' => $item['key'], 'label' => $item['label']],
            array_filter($this->visibleItems($case), fn ($item) => ! empty($item['required']))));
    }

    public function requests(Lead $case): Builder
    {
        return LeadDocumentRequest::where('lead_id', $case->id)
            ->whereDoesntHave('documents', fn ($query) => $query->where('status', LeadDocument::STATUS_APPROVED));
    }

    public function allowedRequest(LeadDocumentRequest $request, ?Lead $case = null): bool
    {
        // Only known client-evidence workflows establish a safe request purpose.
        // Unknown origins (including differently cased/namespaced finance) fail closed.
        if (! filled($request->label) || ! in_array($request->origin, [null, 'manual', 'rfi'], true)
            || ! $this->safePurpose(['label' => $request->label, 'description' => $request->description ?? ''])) {
            return false;
        }
        $case ??= $request->lead;

        return $case && (int) $request->lead_id === (int) $case->id
            && ! $request->documents()->get()->contains(fn ($doc) => ! $this->documentPurposeAllowed($case, $doc));
    }

    /** Also prevents a generic request from relabelling restricted linked evidence. */
    private function documentPurposeAllowed(Lead $case, LeadDocument $doc): bool
    {
        if ((int) $doc->lead_id !== (int) $case->id || $doc->dependent_id || $doc->source !== LeadDocument::SOURCE_UPLOAD
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
        if (! $this->safeChecklistPurpose($case, $doc->checklist_key)
            || ! $this->safePurpose(array_intersect_key($doc->notes ?? [], array_flip(['category', 'person', 'person_type', 'applicant_type', 'subject', 'purpose', 'document_type'])))) {
            return false;
        }

        return true;
    }

    public function canRead(Lead $case, LeadDocument $doc): bool
    {
        if (! $this->documentPurposeAllowed($case, $doc)) {
            return false;
        }
        $resolver = app(CaseChecklistService::class);
        $request = $doc->request_id ? LeadDocumentRequest::where('lead_id', $case->id)->find($doc->request_id) : null;
        if ($doc->request_id && (! $request || ! $this->allowedRequest($request, $case))) {
            return false;
        }
        $known = collect($this->visibleItems($case))->flatMap(fn ($item) => $resolver->itemKeys($item))->all();
        if (! $request && ! in_array($doc->checklist_key, $known, true)) {
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
