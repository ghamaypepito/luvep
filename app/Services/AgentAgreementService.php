<?php

namespace App\Services;

use App\Models\AgentAgreement;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Generates the Referral Agent Agreement PDF for an agent (User role=agent).
 * Only the fillable fields + Schedule A commission structure are editable;
 * the rest of the agreement is fixed. One current agreement per agent —
 * generating replaces the previous one.
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

    /**
     * Render the stored agreement to HTML for a live preview, merging optional
     * override field values (e.g. the agent's in-progress Schedule B edits) and
     * keeping whatever signatures already exist.
     */
    public function previewHtmlForAgreement(AgentAgreement $agreement, array $override = []): string
    {
        $fields = is_array($agreement->fields) ? $agreement->fields : [];
        foreach ($override as $key => $value) {
            if (in_array($key, self::fieldKeys(), true) && is_string($value)) {
                $fields[$key] = mb_substr(trim($value), 0, 500);
            }
        }

        $payload = $this->payload($agreement->agent, $fields, $this->signaturesFrom($agreement));
        $payload['preview'] = true;

        return view('agreements.agent-referral', $payload)->render();
    }

    /** Signatures captured so far on a stored agreement, for a re-render. */
    private function signaturesFrom(AgentAgreement $agreement): array
    {
        $sigs = [];
        if ($agreement->isSignedByAgent()) {
            $sigs['agent'] = [
                'data' => $agreement->agent_signature_data,
                'name' => $agreement->agent_signer_name,
                'date' => $agreement->agent_signed_at->format('j M Y'),
            ];
        }
        if ($agreement->isSignedByCompany()) {
            $sigs['company'] = [
                'data' => $agreement->company_signature_data,
                'name' => $agreement->company_signer_name,
                'date' => $agreement->company_signed_at->format('j M Y'),
            ];
        }

        return $sigs;
    }

    /**
     * Merge partial field updates onto the agreement (e.g. the Affiliate
     * Partner's Schedule B bank + contact details) and re-render the PDF,
     * preserving any signatures already captured.
     */
    public function updateFields(AgentAgreement $agreement, array $partial): AgentAgreement
    {
        $fields = is_array($agreement->fields) ? $agreement->fields : [];
        foreach ($partial as $key => $value) {
            if (in_array($key, self::fieldKeys(), true) && is_string($value)) {
                $fields[$key] = mb_substr(trim($value), 0, 500);
            }
        }
        $agreement->fields = $fields;
        $agreement->save();

        $this->rebuild($agreement);

        return $agreement;
    }

    /** Re-render the stored agreement PDF with whatever signatures exist. */
    private function rebuild(AgentAgreement $agreement): void
    {
        $payload = $this->payload($agreement->agent, (array) $agreement->fields, $this->signaturesFrom($agreement));
        $binary = Pdf::loadView('agreements.agent-referral', $payload)->setPaper('a4')->output();

        Storage::disk(self::DISK)->put($agreement->file_path, $binary);
        $agreement->size = strlen($binary);
        $agreement->save();
    }

    /** Render the agreement as HTML for the live preview (no dompdf). */
    public function renderHtml(User $agent, array $fields): string
    {
        $payload = $this->payload($agent, $this->normaliseFields($agent, $fields));
        $payload['preview'] = true;

        return view('agreements.agent-referral', $payload)->render();
    }

    /** Generate the PDF, store it, and replace any existing agreement. */
    public function generate(User $agent, array $fields): AgentAgreement
    {
        $normalised = $this->normaliseFields($agent, $fields);
        $payload = $this->payload($agent, $normalised);

        $pdf = Pdf::loadView('agreements.agent-referral', $payload)->setPaper('a4');
        $binary = $pdf->output();

        $safeName = Str::slug($agent->name ?: 'Agent') ?: ('agent-'.$agent->id);
        $filename = "Affiliate-Partner-Agreement-{$safeName}.pdf";
        $path = "agent-agreements/{$agent->id}/".Str::random(12)."-{$filename}";

        Storage::disk(self::DISK)->put($path, $binary);

        // One current agreement per agent — drop the previous file + row.
        $existing = AgentAgreement::where('agent_id', $agent->id)->get();
        foreach ($existing as $old) {
            \App\Support\DocumentFiles::delete($old->file_path, 'local');
            $old->delete();
        }

        return AgentAgreement::create([
            'agent_id' => $agent->id,
            'fields' => $normalised,
            'file_path' => $path,
            'original_name' => $filename,
            'mime' => 'application/pdf',
            'size' => strlen($binary),
            'generated_by' => Auth::id(),
        ]);
    }

    /**
     * Record the agent's e-signature and re-render the PDF with it embedded in
     * the "For the Agent" cell. Mirrors the tracker signing flow: typed legal
     * name + base64 signature (drawn or uploaded) + IP/UA audit.
     */
    public function recordAgentSignature(AgentAgreement $agreement, string $signerName, string $signatureData, string $ip, ?string $userAgent): AgentAgreement
    {
        $agreement->agent_signer_name = $signerName;
        $agreement->agent_signature_data = $signatureData;
        $agreement->agent_signed_at = now();
        $agreement->agent_signed_ip = $ip;
        $agreement->agent_signed_user_agent = $userAgent;
        $agreement->save();

        $this->rebuild($agreement);

        return $agreement;
    }

    /**
     * Record the ePathways (staff) e-signature on the "For ePathways" cell —
     * same draw/upload capture as the agent side.
     */
    public function recordCompanySignature(AgentAgreement $agreement, string $signerName, string $signatureData): AgentAgreement
    {
        $agreement->company_signer_name = $signerName;
        $agreement->company_signature_data = $signatureData;
        $agreement->company_signed_at = now();
        $agreement->company_signed_by = Auth::id();
        $agreement->save();

        $this->rebuild($agreement);

        return $agreement;
    }
}
