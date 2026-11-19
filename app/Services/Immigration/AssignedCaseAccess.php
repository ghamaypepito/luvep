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

    private function safePurpose(string $key, string $label = ''): bool
    {
        return ! in_array($key, ['svf', 'rfi'], true)
            && ! str_starts_with($key, 'agree.')
            && ! preg_match('/(?:^|[._:-])(finance|invoice|payment|receipt|engagement|written_agreement)(?:$|[._:-])/i', $key)
            && ! preg_match('/\b(invoice|payment|receipt|engagement|written agreement)\b/i', $label);
    }

    public function visibleItems(Lead $case): array
    {
        $resolver = app(CaseChecklistService::class);
        $hidden = $resolver->hiddenKeysFor($case);

        return array_values(array_filter($resolver->forCase($case), fn ($item) => ! array_intersect($resolver->itemKeys($item), $hidden)
            && $this->safePurpose($item['key'], $item['label'] ?? '')));
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

    public function allowedRequest(LeadDocumentRequest $request): bool
    {
        return filled($request->label) && ! in_array($request->origin, ['finance', 'invoice', 'payment', 'engagement'], true)
            && $this->safePurpose('', $request->label);
    }

    public function canRead(Lead $case, LeadDocument $doc): bool
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
        if (! $this->safePurpose((string) $doc->checklist_key)) {
            return false;
        }
        $request = $doc->request_id ? LeadDocumentRequest::where('lead_id', $case->id)->find($doc->request_id) : null;
        if ($doc->request_id && (! $request || ! $this->allowedRequest($request))) {
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
