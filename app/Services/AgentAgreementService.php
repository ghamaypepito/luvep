<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\AgentAgreement;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Generates the Referral Agent Agreement PDF for an agent (User role=agent).
 * Only the fillable fields + Schedule A commission structure are editable;
 * the rest of the agreement is fixed. Every generated version is retained.
 */
class AgentAgreementService
{
    private const DISK = 'local';

    /**
     * Editable fields shown in the generate modal, grouped. Each field carries
     * its placeholder guide (rendered grey in the PDF when left blank).
     *
     * @return array<int, array{group:string, fields:array<int,array{key:string,label:string,placeholder:string,type?:string}>}>
     */
    public static function fieldGroups(): array
    {
        return [
            ['group' => 'Agreement', 'fields' => [
                ['key' => 'effective_date', 'label' => 'Effective date', 'placeholder' => 'e.g. 30th day of June, 2026'],
            ]],
            ['group' => 'Affiliate Partner details', 'fields' => [
                ['key' => 'agent_full_name', 'label' => 'Affiliate Partner full name', 'placeholder' => 'e.g. Lillian Novida Ejorango'],
                ['key' => 'agent_citizenship', 'label' => 'Citizenship', 'placeholder' => 'e.g. Canada'],
                ['key' => 'agent_passport', 'label' => 'Passport number', 'placeholder' => 'e.g. AK341265'],
                ['key' => 'agent_city', 'label' => 'City, country', 'placeholder' => 'e.g. Vancouver, Canada'],
            ]],
            ['group' => 'Schedule A — Commission rates', 'fields' => [
                // Each rate row is a pair: percentage | amount, side by side.
                ['type' => 'pair', 'label' => 'New Zealand — 1 to 5 students',
                    'left' => ['key' => 'nz_1_5_percent', 'placeholder' => 'e.g. 10%'],
                    'right' => ['key' => 'nz_1_5_amount', 'placeholder' => 'e.g. 20,000']],
                ['type' => 'pair', 'label' => 'New Zealand — 6 or more students',
                    'left' => ['key' => 'nz_6plus_percent', 'placeholder' => 'e.g. 15%'],
                    'right' => ['key' => 'nz_6plus_amount', 'placeholder' => 'e.g. 30,000']],
                ['type' => 'pair', 'label' => 'Australia — all students',
                    'left' => ['key' => 'au_percent', 'placeholder' => 'e.g. Negotiable'],
                    'right' => ['key' => 'au_amount', 'placeholder' => 'e.g. 25,000 or Negotiable']],
            ]],
            ['group' => 'Schedule A — Payment & other terms', 'fields' => [
                ['key' => 'commission_basis', 'label' => 'Commission basis', 'placeholder' => 'Per-student fee'],
                ['key' => 'payment_trigger', 'label' => 'Payment trigger', 'placeholder' => 'Enrolment and commencement of the course'],
                ['key' => 'payment_timing', 'label' => 'Payment timing', 'placeholder' => 'Within 15 days after the student commences the course'],
                ['key' => 'currency', 'label' => 'Currency', 'placeholder' => 'Philippine Peso (PhP)'],
                ['key' => 'australia_students', 'label' => 'Australia students', 'placeholder' => 'Commission is negotiable and agreed in writing before the referral is processed.', 'type' => 'textarea'],
                ['key' => 'refunds_withdrawals', 'label' => 'Refunds & withdrawals', 'placeholder' => 'No commission is payable where a student does not commence, withdraws, or whose fees are refunded.', 'type' => 'textarea'],
            ]],
            ['group' => 'Schedule A — Comment', 'fields' => [
                ['key' => 'schedule_a_comment', 'label' => 'Comment', 'placeholder' => 'Any comments, clarifications, or supplementary terms agreed between eP and the Affiliate Partner.', 'type' => 'textarea'],
            ]],
            ['group' => 'Schedule B — Affiliate Partner bank account', 'fields' => [
                ['key' => 'bank_name', 'label' => 'Bank name', 'placeholder' => 'Insert bank'],
                ['key' => 'account_holder', 'label' => 'Account holder name', 'placeholder' => 'As registered with the bank'],
                ['key' => 'account_number', 'label' => 'Account number', 'placeholder' => 'Insert account number'],
                ['key' => 'swift_bic', 'label' => 'SWIFT / BIC code', 'placeholder' => 'Insert SWIFT / BIC code'],
            ]],
            ['group' => 'Execution — eP', 'fields' => [
                ['key' => 'company_signatory', 'label' => 'eP signatory', 'placeholder' => 'Dinah Suarin'],
                ['key' => 'company_title', 'label' => 'eP title', 'placeholder' => 'Founder'],
                ['key' => 'company_date', 'label' => 'eP date', 'placeholder' => 'e.g. June 30th 2026'],
            ]],
            ['group' => 'Execution — Affiliate Partner', 'fields' => [
                ['key' => 'agent_title', 'label' => 'Affiliate Partner title', 'placeholder' => 'Affiliate Partner'],
                ['key' => 'agent_date', 'label' => 'Affiliate Partner date', 'placeholder' => 'Signed on…'],
                ['key' => 'affiliate_business_address', 'label' => 'Business address', 'placeholder' => 'Affiliate Partner business address', 'type' => 'textarea'],
                ['key' => 'affiliate_email', 'label' => 'Email', 'placeholder' => 'Affiliate Partner email'],
                ['key' => 'affiliate_contact', 'label' => 'Contact number', 'placeholder' => 'Affiliate Partner contact number'],
            ]],
        ];
    }

    /**
     * Fields the Affiliate Partner fills in themselves (Schedule B bank account
     * + their execution contact details). Surfaced on the agent's own signing
     * step so they provide their own banking + contact info.
     *
     * @return array<int, array{key:string,label:string,placeholder:string,type?:string}>
     */
    public static function affiliateFields(): array
    {
        return [
            ['key' => 'bank_name', 'label' => 'Bank name', 'placeholder' => 'Your bank'],
            ['key' => 'account_holder', 'label' => 'Account holder name', 'placeholder' => 'As registered with the bank'],
            ['key' => 'account_number', 'label' => 'Account number', 'placeholder' => 'Your account number'],
            ['key' => 'swift_bic', 'label' => 'SWIFT / BIC code', 'placeholder' => 'Your bank SWIFT / BIC'],
            ['key' => 'affiliate_business_address', 'label' => 'Business address', 'placeholder' => 'Your business address', 'type' => 'textarea'],
            ['key' => 'affiliate_email', 'label' => 'Email', 'placeholder' => 'you@example.com'],
            ['key' => 'affiliate_contact', 'label' => 'Contact number', 'placeholder' => 'Your contact number'],
        ];
    }

    /** Flat list of every editable field key (for validation). */
    public static function fieldKeys(): array
    {
        return collect(self::fieldGroups())
            ->flatMap(fn ($g) => collect($g['fields'])->flatMap(function ($f) {
                // A "pair" field carries two sub-keys (percentage | amount).
                if (($f['type'] ?? null) === 'pair') {
                    return [$f['left']['key'], $f['right']['key']];
                }

                return [$f['key']];
            }))
            ->all();
    }

    /**
     * Sensible starting values — the agent's name pre-filled, the standard
     * Schedule A rates/terms, and the company execution block. Blank fields
     * (passport, citizenship, dates…) fall back to the grey placeholder guide.
     */
    public function defaultFields(User $agent): array
    {
        return [
            'effective_date' => '',
            'agent_full_name' => $agent->name ?? '',
            'agent_citizenship' => '',
            'agent_passport' => '',
            'agent_city' => $agent->location ?? '',
            // Schedule A percent/amount — blank so the grey "Percentage" /
            // "Insert Amount" placeholder guides show until staff fill them.
            'nz_1_5_percent' => '',
            'nz_1_5_amount' => '',
            'nz_6plus_percent' => '',
            'nz_6plus_amount' => '',
            'au_percent' => '',
            'au_amount' => '',
            'commission_basis' => 'Per-student fee',
            'payment_trigger' => 'Enrolment and commencement of the course',
            'payment_timing' => 'Within 15 days after the student commences the course',
            'currency' => 'Philippine Peso (PhP)',
            'australia_students' => 'Commission is negotiable and agreed in writing before the referral is processed.',
            'refunds_withdrawals' => 'No commission is payable where a student does not commence, withdraws, or whose fees are refunded.',
            'schedule_a_comment' => '',
            // Schedule B — Affiliate Partner fills these in themselves.
            'bank_name' => '',
            'account_holder' => '',
            'account_number' => '',
            'swift_bic' => '',
            'company_signatory' => 'Dinah Suarin',
            'company_title' => 'Founder',
            'company_date' => '',
            'agent_title' => 'Affiliate Partner',
            'agent_date' => '',
            'affiliate_business_address' => '',
            'affiliate_email' => $agent->email ?? '',
            'affiliate_contact' => $agent->phone ?? '',
        ];
    }

    /** Merge caller-supplied field values onto defaults, keeping only known keys. */
    private function normaliseFields(User $agent, array $fields): array
    {
        $out = $this->defaultFields($agent);
        foreach (self::fieldKeys() as $key) {
            if (array_key_exists($key, $fields) && is_string($fields[$key])) {
                $out[$key] = mb_substr(trim($fields[$key]), 0, 500);
            }
        }

        return $out;
    }

    /**
     * Build the blade payload. $signatures carries each party's captured
     * signature: ['agent' => ['data','name','date'], 'company' => [...]]. Each
     * cell only shows a signature once that party has explicitly signed — no
     * Auth fallback, so a rebuild triggered by either side never leaks the
     * wrong signature into the other party's cell.
     */
    private function payload(User $agent, array $fields, array $signatures = []): array
    {
        return [
            'fields' => $fields,
            'agent_name' => $agent->name,
            // "For eP" cell — the company signature once staff sign.
            'signer_signature' => $signatures['company']['data'] ?? null,
            'company_signed_date' => $signatures['company']['date'] ?? null,
            // "For the Affiliate Partner" cell — the agent's e-signature.
            'agent_signature' => $signatures['agent']['data'] ?? null,
            'agent_signed_name' => $signatures['agent']['name'] ?? null,
            'agent_signed_date' => $signatures['agent']['date'] ?? null,
        ];
    }

    /** Preview changes only to the unsigned affiliate details in the frozen document. */
    public function previewHtmlForAgreement(AgentAgreement $agreement, array $override = []): string
    {
        abort_unless($agreement->rendered_html !== null, 422, 'Generate a new version before editing or signing this legacy agreement.');
        $html = $agreement->rendered_html;
        if ($agreement->canEditDetails()) {
            foreach (self::affiliateFields() as $field) {
                $key = $field['key'];
                if (isset($override[$key]) && is_string($override[$key])) {
                    $html = $this->replaceSlot($html, 'field:'.$key, e(mb_substr(trim($override[$key]), 0, 500)));
                }
            }
        }

        return $this->browserHtml($html);
    }

    private function browserHtml(string $html): string
    {
        $prefix = rtrim(public_path(), '/').'/';

        return str_replace($prefix, rtrim(url('/'), '/').'/', $html);
    }

    private function replaceSlot(string $html, string $slot, string $replacement): string
    {
        $pattern = '~(<!--affiliate-'.preg_quote($slot, '~').'-->).*?(<!--/affiliate-'.preg_quote($slot, '~').'-->)~s';
        $result = preg_replace_callback($pattern, fn ($match) => $match[1].$replacement.$match[2], $html, -1, $count);
        if ($count === 0) {
            throw new \RuntimeException('Agreement rendering slot is missing.');
        }

        return $result;
    }

    private function applyDetails(AgentAgreement $agreement, array $partial): void
    {
        $fields = $agreement->fields ?? [];
        foreach (self::affiliateFields() as $field) {
            $key = $field['key'];
            if (! array_key_exists($key, $partial)) {
                continue;
            }
            $value = mb_substr(trim((string) $partial[$key]), 0, 500);
            if ($value !== (string) ($fields[$key] ?? '')) {
                abort_unless($agreement->canEditDetails(), 422, 'Signed or previous agreement details are locked. Ask EP to prepare a replacement version.');
                $agreement->rendered_html = $this->replaceSlot($agreement->rendered_html, 'field:'.$key, e($value));
                $fields[$key] = $value;
            }
        }
        $agreement->fields = $fields;
    }

    public function updateFields(AgentAgreement $agreement, array $partial): AgentAgreement
    {
        return DB::transaction(function () use ($agreement, $partial) {
            User::whereKey($agreement->agent_id)->lockForUpdate()->firstOrFail();
            $locked = AgentAgreement::whereKey($agreement->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->canEditDetails(), 422, 'This agreement is locked. Ask EP to prepare a replacement version.');
            $this->applyDetails($locked, $partial);
            $this->writeDocument($locked);
            ActivityLog::record('affiliate.agreement.details_saved', ['properties' => ['agreement_id' => $locked->id, 'version' => $locked->version]]);

            return $locked;
        });
    }

    /** Use a new private file so a failed database write cannot corrupt the issued PDF. */
    private function writeDocument(AgentAgreement $agreement): void
    {
        $binary = Pdf::loadHTML($agreement->rendered_html)->setPaper('a4')->output();
        $path = 'agent-agreements/'.$agreement->agent_id.'/'.Str::random(24).'.pdf';
        if (! Storage::disk(self::DISK)->put($path, $binary)) {
            throw new \RuntimeException('Agreement PDF could not be stored.');
        }
        $oldPath = $agreement->file_path;
        try {
            $agreement->file_path = $path;
            $agreement->size = strlen($binary);
            $agreement->save();
        } catch (\Throwable $error) {
            Storage::disk(self::DISK)->delete($path);
            throw $error;
        }
        if ($oldPath && $oldPath !== $agreement->issued_file_path) {
            DB::afterCommit(fn () => Storage::disk(self::DISK)->delete($oldPath));
        }
    }

    /** Render the agreement as HTML for the live preview (no dompdf). */
    public function renderHtml(User $agent, array $fields): string
    {
        $payload = $this->payload($agent, $this->normaliseFields($agent, $fields));
        $payload['preview'] = true;

        return view('agreements.agent-referral', $payload)->render();
    }

    /** Save a new version; the partner account lock allocates versions across concurrent requests. */
    public function generate(User $agent, array $fields, bool $draft = false, ?int $draftId = null, ?string $expiresOn = null): AgentAgreement
    {
        return DB::transaction(function () use ($agent, $fields, $draft, $draftId, $expiresOn) {
            User::whereKey($agent->id)->lockForUpdate()->firstOrFail();
            $normalised = $this->normaliseFields($agent, $fields);
            $html = view('agreements.agent-referral', $this->payload($agent, $normalised))->render();
            $version = (int) AgentAgreement::where('agent_id', $agent->id)->max('version') + 1;
            $agreement = $draftId
                ? AgentAgreement::where('agent_id', $agent->id)->where('is_draft', true)->lockForUpdate()->findOrFail($draftId)
                : new AgentAgreement(['agent_id' => $agent->id, 'version' => $version]);
            if ($draftId && ! $draft && AgentAgreement::where('agent_id', $agent->id)->where('version', '>', $agreement->version)->exists()) {
                $agreement->version = $version;
            }
            $agreement->fill([
                'fields' => $normalised,
                'rendered_html' => $html,
                'is_draft' => $draft,
                'expires_on' => $expiresOn,
                'generated_at' => $draft ? null : now(),
                'original_name' => 'Affiliate-Partner-Agreement-'.Str::slug($agent->name ?: 'Partner').'-v'.$agreement->version.'.pdf',
                'mime' => 'application/pdf',
                'generated_by' => Auth::id(),
            ]);
            $this->writeDocument($agreement);
            if (! $draft) {
                $agreement->issued_file_path = $agreement->file_path;
                $agreement->save();
            }
            ActivityLog::record($draft ? 'affiliate.agreement.draft_saved' : 'affiliate.agreement.generated', [
                'properties' => ['agreement_id' => $agreement->id, 'partner_id' => $agent->id, 'version' => $agreement->version],
            ]);

            return $agreement;
        });
    }

    public function recordAgentSignature(AgentAgreement $agreement, string $signerName, string $signatureData, string $ip, ?string $userAgent, array $details = []): AgentAgreement
    {
        return $this->sign($agreement, 'agent', $signerName, $signatureData, $ip, $userAgent, $details);
    }

    public function recordCompanySignature(AgentAgreement $agreement, string $signerName, string $signatureData): AgentAgreement
    {
        return $this->sign($agreement, 'company', $signerName, $signatureData);
    }

    private function sign(AgentAgreement $agreement, string $side, string $signerName, string $signatureData, ?string $ip = null, ?string $userAgent = null, array $details = []): AgentAgreement
    {
        if (! preg_match('~^data:image/(?:png|jpeg);base64,([a-zA-Z0-9+/=\r\n]+)$~D', $signatureData, $match)
            || ! ($bytes = base64_decode($match[1], true)) || ! @getimagesizefromstring($bytes)) {
            throw ValidationException::withMessages(['signature_data' => 'Use a valid PNG or JPEG signature image.']);
        }

        return DB::transaction(function () use ($agreement, $side, $signerName, $signatureData, $ip, $userAgent, $details) {
            User::whereKey($agreement->agent_id)->lockForUpdate()->firstOrFail();
            $locked = AgentAgreement::whereKey($agreement->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->canSign(), 422, 'This version is read-only. Open the current generated agreement.');
            abort_if($locked->{$side.'_signed_at'} !== null, 422, 'This side of the agreement is already signed.');
            if ($side === 'agent') {
                $this->applyDetails($locked, $details);
            }
            $locked->{$side.'_signer_name'} = $signerName;
            $locked->{$side.'_signature_data'} = $signatureData;
            $locked->{$side.'_signed_at'} = now();
            if ($side === 'agent') {
                $locked->agent_signed_ip = $ip;
                $locked->agent_signed_user_agent = $userAgent;
            } else {
                $locked->company_signed_by = Auth::id();
            }
            $signatureHtml = '<img src="'.e($signatureData).'" alt="Signature" style="max-height:46px;max-width:150px;margin:0 auto 2px;display:block">';
            $locked->rendered_html = $this->replaceSlot($locked->rendered_html, 'signature:'.$side, $signatureHtml);
            $locked->rendered_html = $this->replaceSlot($locked->rendered_html, 'date:'.$side, e(now()->format('j M Y')));
            $this->writeDocument($locked);
            ActivityLog::record('affiliate.agreement.'.$side.'_signed', ['properties' => ['agreement_id' => $locked->id, 'version' => $locked->version]]);

            return $locked;
        });
    }
}
