<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadDocument;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Renders agreement templates (Blade -> PDF via dompdf) and stores them
 * against a lead as a LeadDocument row with source='generated'. The UI
 * then renders that row in the relevant checklist card alongside any
 * manually-uploaded files.
 */
class AgreementGenerator
{
    private const DISK = 'local'; // private; matches LeadDocumentController's DISK

    /**
     * Consultancy Agreement — Single (PhP 100,000) or Partner (PhP 150,000).
     * Stored against checklist_key='agree.consultancy'.
     */
    /**
     * English Engagement Agreement — PTE preparation services. No variant
     * (just one template). Stored against checklist_key='agree.engagement_english'.
     */
    /**
     * Shared payload for the English Engagement (Philippines) agreement — used
     * by both the PDF generator and the live verification preview so they stay
     * pixel-identical.
     */
    public function buildEnglishEngagementPayload(Lead $lead, string $currency = 'php', array $overrides = []): array
    {
        $clientName = trim("{$lead->first_name} {$lead->last_name}");
        $clientReference = Str::slug($clientName ?: 'ClientName', '');
        $today = now();
        $signer = Auth::user();
        $currency = $currency === 'nzd' ? 'nzd' : 'php';

        return [
            'client_name' => $clientName,
            'client_reference' => $clientReference ?: 'ClientName',
            'signer_name' => $signer?->name ?: 'Neil Bryan Escaner',
            'signer_mobile' => $signer?->phone ?: '+63945 107 6871',
            'signer_signature' => method_exists($signer, 'signatureDataUriTrimmed') ? $signer->signatureDataUriTrimmed() : null,
            'generated_at' => $today,
            'generated_at_formatted' => $today->format('jS').' day of '.$today->format('F Y'),
            'currency' => $currency,
            'currency_symbol' => $currency === 'nzd' ? 'NZ$' : 'Php',
            // Staff-editable prices: the English Review package (defaults 14,500)
            // and the separate PTE Examination fee in USD (defaults 240).
            'english_fee' => (int) ($overrides['english_fee'] ?? 14500),
            'pte_fee' => (int) ($overrides['pte_fee'] ?? 240),
        ] + $this->englishBankVars($overrides);
    }

    public function englishEngagement(Lead $lead, string $currency = 'php', array $overrides = []): LeadDocument
    {
        $payload = $this->buildEnglishEngagementPayload($lead, $currency, $overrides);
        $clientName = $payload['client_name'];

        $pdf = Pdf::loadView('agreements.engagement-english', $payload)->setPaper('a4');
        $binary = $pdf->output();

        $safeName = $this->safeBaseName($clientName ?: 'Client');
        $filename = "Eng-{$safeName}.pdf";
        $path = "lead-documents/{$lead->id}/".Str::random(12)."-{$filename}";

        Storage::disk(self::DISK)->put($path, $binary);

        return LeadDocument::create([
            'lead_id' => $lead->id,
            'request_id' => null,
            'checklist_key' => 'agree.engagement_english',
            'original_name' => $filename,
            'file_path' => $path,
            'mime' => 'application/pdf',
            'size' => strlen($binary),
            'status' => LeadDocument::STATUS_SUBMITTED,
            'source' => LeadDocument::SOURCE_GENERATED,
            'source_variant' => 'engagement-english',
            'uploaded_by' => Auth::id(),
        ]);
    }

    /**
     * Editable bank block for the English agreements — the staff-entered
     * overrides (a preset or fully custom) or the BPI default. Blank reference
     * falls back to the "#PTE<name>" convention in the template.
     */
    public function englishBankVars(array $overrides): array
    {
        $val = fn ($k, $default) => filled($overrides[$k] ?? null) ? $overrides[$k] : $default;

        return [
            'bank_heading' => $val('bank_heading', 'PAYMENT DETAILS'),
            'bank_name' => $val('bank_name', 'BPI'),
            'bank_account_name' => $val('bank_account_name', 'Dinah Suarin'),
            'bank_account_number' => $val('bank_account_number', '9269224808'),
            'bank_reference' => filled($overrides['bank_reference'] ?? null) ? $overrides['bank_reference'] : null,
        ];
    }

    /**
     * English Proficiency Test (IELTS/PTE) Review Agreement — OFFSHORE variant
     * (NZD, single editable package fee). Same document bucket as the English
     * engagement, different template.
     */
    /** Shared payload for the English Offshore (NZD) agreement — PDF + preview. */
    public function buildEnglishOffshorePayload(Lead $lead, string $currency = 'nzd', array $overrides = []): array
    {
        $clientName = trim("{$lead->first_name} {$lead->last_name}");
        $clientReference = Str::slug($clientName ?: 'ClientName', '');
        $today = now();
        $signer = Auth::user();
        $currency = $currency === 'php' ? 'php' : 'nzd';
        // "package_1000" variant — a fuller package (unlimited mock + PTE exam
        // fee) at a $1,000 default, sharing this same offshore layout.
        $is1000 = ($overrides['offshore_variant'] ?? null) === 'package_1000';

        return [
            'client_name' => $clientName,
            'client_reference' => $clientReference ?: 'ClientName',
            'signer_name' => $signer?->name ?: 'Neil Bryan Escaner',
            'signer_mobile' => $signer?->phone ?: '+63945 107 6871',
            'signer_signature' => method_exists($signer, 'signatureDataUriTrimmed') ? $signer->signatureDataUriTrimmed() : null,
            'generated_at' => $today,
            'generated_at_formatted' => $today->format('jS').' day of '.$today->format('F Y'),
            'currency' => $currency,
            'currency_symbol' => $currency === 'nzd' ? 'NZ$' : 'Php',
            'english_fee' => (int) ($overrides['english_fee'] ?? ($is1000 ? 1000 : 550)),
            // Bullet variations for the fee package.
            'mock_line' => $is1000 ? 'UNLIMITED mock test with assessment and feedback' : '1 mock test with assessment and feedback',
            'show_pte_bullet' => $is1000,
        ] + $this->englishBankVars($overrides);
    }

    public function englishOffshore(Lead $lead, string $currency = 'nzd', array $overrides = []): LeadDocument
    {
        $payload = $this->buildEnglishOffshorePayload($lead, $currency, $overrides);
        $clientName = $payload['client_name'];
        $is1000 = ($overrides['offshore_variant'] ?? null) === 'package_1000';

        $pdf = Pdf::loadView('agreements.engagement-english-offshore', $payload)->setPaper('a4');
        $binary = $pdf->output();

        $safeName = $this->safeBaseName($clientName ?: 'Client');
        $filename = ($is1000 ? "EngOffshore1000-{$safeName}.pdf" : "EngOffshore-{$safeName}.pdf");
        $path = "lead-documents/{$lead->id}/".Str::random(12)."-{$filename}";
        Storage::disk(self::DISK)->put($path, $binary);

        return LeadDocument::create([
            'lead_id' => $lead->id,
            'request_id' => null,
            'checklist_key' => 'agree.engagement_english',
            'original_name' => $filename,
            'file_path' => $path,
            'mime' => 'application/pdf',
            'size' => strlen($binary),
            'status' => LeadDocument::STATUS_SUBMITTED,
            'source' => LeadDocument::SOURCE_GENERATED,
            'source_variant' => $is1000 ? 'engagement-english-offshore-1000' : 'engagement-english-offshore',
            'uploaded_by' => Auth::id(),
        ]);
    }

    /**
     * Consultancy Agreement — 4 scenarios. Staff pick which one applies
     * for a lead and can override the two editable fee amounts (School
     * Enrolment + English Proficiency) from the New modal.
     *
     * $scenario keys — see self::consultancyScenarios() for the full table.
     * $overrides    — ['school_enrolment_fee' => 100000, 'english_proficiency_fee' => 14500]
     */
    public function consultancy(Lead $lead, string $scenario, array $overrides = []): LeadDocument
    {
        [$payload, $scenarioMeta] = $this->buildConsultancyPayload($lead, $scenario, $overrides);

        // isPhpEnabled powers the page_text() footer at the end of the view
        // ("Page 3 of 9"). Scoped to this render — the template is ours and
        // every interpolated value is Blade-escaped, so no caller-supplied
        // markup can reach dompdf's script handler.
        $pdf = Pdf::loadView('agreements.consultancy', $payload)
            ->setPaper('a4')
            ->setOption('isPhpEnabled', true);
        $binary = $pdf->output();

        $safeName = $this->safeBaseName($payload['client_name'] ?: 'Client');
        $filename = "CA-{$safeName}-{$scenarioMeta['file_suffix']}.pdf";
        $path = "lead-documents/{$lead->id}/".Str::random(12)."-{$filename}";

        Storage::disk(self::DISK)->put($path, $binary);

        return LeadDocument::create([
            'lead_id' => $lead->id,
            'request_id' => null,
            'checklist_key' => 'agree.consultancy',
            'original_name' => $filename,
            'file_path' => $path,
            'mime' => 'application/pdf',
            'size' => strlen($binary),
            'status' => LeadDocument::STATUS_SUBMITTED,
            'source' => LeadDocument::SOURCE_GENERATED,
            // Encode applicant mode so the tracker / documents table can
            // surface "Single" vs "Couple" without re-opening the PDF.
            // Old rows without the suffix default to 'single' at read time.
            'source_variant' => "consultancy:{$scenarioMeta['key']}:{$payload['applicant_mode']}",
            'uploaded_by' => Auth::id(),
        ]);
    }

    /**
     * Public payload builder — shared by the PDF generator and by the
     * live-preview endpoint on LeadDocumentController so the preview
     * renders exactly what the PDF will contain.
     *
     * Returns [payload, scenarioMeta] — meta has the raw scenario
     * metadata so callers can log / suffix a filename with it.
     */
    public function buildConsultancyPayload(Lead $lead, string $scenario, array $overrides = []): array
    {
        $scenarios = self::consultancyScenarios();
        $s = $scenarios[$scenario] ?? $scenarios['std_100'];

        $clientName = trim("{$lead->first_name} {$lead->last_name}");
        $clientReference = Str::slug($clientName ?: 'ClientName', '') ?: 'ClientName';
        $today = now();

        // Only these two are editable from the modal. Everything else in
        // the cost-breakdown table stays static — those are canonical
        // reference amounts from the ePathways template.
        $schoolFee = (int) ($overrides['school_enrolment_fee'] ?? $s['default_school_fee']);
        $englishFee = (int) ($overrides['english_proficiency_fee'] ?? 14500);
        $customDescription = is_string($overrides['application_description'] ?? null)
            ? trim($overrides['application_description']) : '';

        // Currency for the staff-entered fees (php | nzd). Only the symbol
        // changes — amounts are never converted. The static reference
        // breakdown stays in PHP (canonical template estimates).
        $currency = ($overrides['currency'] ?? 'php') === 'nzd' ? 'nzd' : 'php';
        $currencySymbol = $currency === 'nzd' ? 'NZ$' : 'Php';

        // Bank details — staff pick a preset (ANZ / RCBC) or "Other" and may
        // edit every field. Defaults reproduce the original RCBC block so
        // documents generated without overrides are unchanged. The reference
        // falls back to the client's slug (e.g. #neilescaner).
        $bankHeading = trim((string) ($overrides['bank_heading'] ?? '')) ?: 'Payment for School Enrollment and Documentation Fee';
        $bankName = trim((string) ($overrides['bank_name'] ?? '')) ?: 'RCBC';
        $bankAccountName = trim((string) ($overrides['bank_account_name'] ?? '')) ?: 'Dinah Suarin';
        $bankAccountNumber = trim((string) ($overrides['bank_account_number'] ?? '')) ?: '9045440503';
        $bankReference = trim((string) ($overrides['bank_reference'] ?? '')) ?: $clientReference;

        // Applicant mode — Single or Couple. Only meaningful for scenarios
        // that support both (Std 150, Voucher 150). Single-only scenarios
        // ignore the override and pin to 'single'. Drives both the
        // "MAIN APPLICANT (…)" line on page 1 and which cost breakdown
        // table renders on pages 3-4.
        $applicantMode = ($overrides['applicant_mode'] ?? 'single') === 'couple' ? 'couple' : 'single';
        if (! $s['supports_both']) {
            $applicantMode = 'single';
        }
        $applicantLabel = $applicantMode === 'couple'
            ? 'MAIN APPLICANT (Couple)'
            : 'MAIN APPLICANT (Single)';

        // Company signatory — defaults to the authenticated staff member
        // so the generated PDF is pre-signed on the Company side by
        // whoever hit Generate. Callers can override via
        // $overrides['signer_id'] (immigration Engagement flow does this
        // when adviser ≠ generator). Legacy fallback keeps unsigned
        // historic renders working.
        $signer = null;
        if (! empty($overrides['signer_id'])) {
            $signer = \App\Models\User::find($overrides['signer_id']);
        }
        if (! $signer) {
            $signer = Auth::user();
        }

        $signerName = $signer?->name ?: 'Neil Bryan Escaner';
        $signerMobile = $signer?->phone ?: '+63945 107 6871';
        $signerSignature = $signer && method_exists($signer, 'signatureDataUri')
            ? $signer->signatureDataUri()
            : null;

        $payload = [
            // Full catalogue so the Application Type table can list every
            // scenario with only the chosen one ticked, matching the Word
            // original. The view keys off `scenario` to know which is live.
            'scenarios' => self::consultancyScenarios(),
            'scenario' => $s['key'],
            'scenario_number' => $s['number'],
            'scenario_title' => $s['title'],
            'scenario_applicant' => $applicantLabel,
            'scenario_description' => $customDescription !== '' ? $customDescription : $s['description'],
            'scenario_description_custom' => $customDescription !== '',
            'scenario_has_voucher' => $s['has_voucher'],
            'applicant_mode' => $applicantMode,
            'supports_both' => $s['supports_both'],
            'is_couple_scenario' => $applicantMode === 'couple',
            'school_enrolment_fee' => $schoolFee,
            'english_proficiency_fee' => $englishFee,
            'inz_voucher_fee' => 30600,
            'currency' => $currency,
            'currency_symbol' => $currencySymbol,
            'bank_heading' => $bankHeading,
            'bank_name' => $bankName,
            'bank_account_name' => $bankAccountName,
            'bank_account_number' => $bankAccountNumber,
            'bank_reference' => $bankReference,
            'client_name' => $clientName,
            'client_reference' => $clientReference,
            'signer_name' => $signerName,
            'signer_mobile' => $signerMobile,
            'signer_signature' => $signerSignature,
            'generated_at' => $today,
            'generated_at_formatted' => $today->format('jS').' day of '.$today->format('F Y'),
        ];

        return [$payload, $s];
    }

    /**
     * Consultancy Agreement — OFFSHORE variant. A single package fee
     * (Documentation, School Enrolment & Visa Application) with no cost
     * breakdown; defaults to NZ$ + ANZ (a NZ-based agreement). Shares the
     * editable currency + bank overrides with the standard consultancy flow.
     */
    public function buildOffshorePayload(Lead $lead, array $overrides = []): array
    {
        $clientName = trim("{$lead->first_name} {$lead->last_name}");
        $clientReference = Str::slug($clientName ?: 'ClientName', '') ?: 'ClientName';
        $today = now();

        // Fee is flexible. Standard offshore defaults to 3,500; the "Zero fees"
        // variant defaults to 0 but staff can still type any amount. The package
        // scope is fixed per variant: the "Zero fees" variant always covers
        // Documentation + School Enrolment (no Visa Application Fee) whatever the
        // amount; standard offshore covers all three.
        $zero = ! empty($overrides['zero_fees']);
        $packageFee = (int) ($overrides['school_enrolment_fee'] ?? ($zero ? 0 : 3500));
        $packageScope = $zero
            ? 'Documentation and School Enrolment'
            : 'Documentation, School Enrolment, and Visa Application Fee';

        // Offshore is a NZ-based agreement — default NZ$ + ANZ. Staff can
        // still switch either in the modal.
        $currency = ($overrides['currency'] ?? 'nzd') === 'php' ? 'php' : 'nzd';
        $currencySymbol = $currency === 'php' ? 'Php' : 'NZ$';

        $bankHeading = trim((string) ($overrides['bank_heading'] ?? '')) ?: 'Payment for Documentation, School Enrolment, and Visa Application Fee';
        $bankName = trim((string) ($overrides['bank_name'] ?? '')) ?: 'ANZ';
        $bankAccountName = trim((string) ($overrides['bank_account_name'] ?? '')) ?: 'EMPLOYMENT PATHWAYS LTD';
        $bankAccountNumber = trim((string) ($overrides['bank_account_number'] ?? '')) ?: '06-0185-0987269-01';
        $bankReference = trim((string) ($overrides['bank_reference'] ?? '')) ?: $clientReference;

        $signer = null;
        if (! empty($overrides['signer_id'])) {
            $signer = \App\Models\User::find($overrides['signer_id']);
        }
        if (! $signer) {
            $signer = Auth::user();
        }

        return [
            'package_fee' => $packageFee,
            'package_scope' => $packageScope,
            'currency' => $currency,
            'currency_symbol' => $currencySymbol,
            'bank_heading' => $bankHeading,
            'bank_name' => $bankName,
            'bank_account_name' => $bankAccountName,
            'bank_account_number' => $bankAccountNumber,
            'bank_reference' => $bankReference,
            'client_name' => $clientName,
            'client_reference' => $clientReference,
            'signer_name' => $signer?->name ?: 'Neil Bryan Escaner',
            'signer_mobile' => $signer?->phone ?: '+63945 107 6871',
            'signer_signature' => $signer && method_exists($signer, 'signatureDataUri') ? $signer->signatureDataUri() : null,
            'generated_at' => $today,
            'generated_at_formatted' => $today->format('jS').' day of '.$today->format('F Y'),
        ];
    }

    public function consultancyOffshore(Lead $lead, array $overrides = []): LeadDocument
    {
        $zero = ! empty($overrides['zero_fees']);
        $payload = $this->buildOffshorePayload($lead, $overrides);

        $pdf = Pdf::loadView('agreements.consultancy-offshore', $payload)
            ->setPaper('a4')
            ->setOption('isPhpEnabled', true);
        $binary = $pdf->output();

        $safeName = $this->safeBaseName($payload['client_name'] ?: 'Client');
        $filename = $zero ? "CA-{$safeName}-offshore-zero.pdf" : "CA-{$safeName}-offshore.pdf";
        $path = "lead-documents/{$lead->id}/".Str::random(12)."-{$filename}";

        Storage::disk(self::DISK)->put($path, $binary);

        return LeadDocument::create([
            'lead_id' => $lead->id,
            'request_id' => null,
            'checklist_key' => 'agree.consultancy',
            'original_name' => $filename,
            'file_path' => $path,
            'mime' => 'application/pdf',
            'size' => strlen($binary),
            'status' => LeadDocument::STATUS_SUBMITTED,
            'source' => LeadDocument::SOURCE_GENERATED,
            'source_variant' => $zero ? 'consultancy:offshore_zero:single' : 'consultancy:offshore:single',
            'uploaded_by' => Auth::id(),
        ]);
    }

    /**
     * Onshore Engagement Agreement — for applicants already in New Zealand.
     * An EDUCATION engagement that is FREE OF CHARGE (no consultancy fees);
     * eP acts as education agent and refers the client to a Licensed
     * Immigration Adviser for visa matters. No fee/bank fields.
     */
    public function buildOnshoreEngagementPayload(Lead $lead, array $overrides = []): array
    {
        $clientName = trim("{$lead->first_name} {$lead->last_name}");
        $clientReference = Str::slug($clientName ?: 'ClientName', '') ?: 'ClientName';
        $today = now();

        $signer = null;
        if (! empty($overrides['signer_id'])) {
            $signer = \App\Models\User::find($overrides['signer_id']);
        }
        if (! $signer) {
            $signer = Auth::user();
        }

        return [
            'client_name' => $clientName,
            'client_reference' => $clientReference,
            'business_number' => '9429050526901',
            'agreement_date' => trim((string) ($overrides['agreement_date'] ?? '')) ?: $today->format('j F Y'),
            'signer_name' => $signer?->name ?: 'Dinah Suarin',
            'signer_signature' => $signer && method_exists($signer, 'signatureDataUri') ? $signer->signatureDataUri() : null,
            'generated_at' => $today,
        ];
    }

    public function onshoreEngagement(Lead $lead, array $overrides = []): LeadDocument
    {
        $payload = $this->buildOnshoreEngagementPayload($lead, $overrides);

        $pdf = Pdf::loadView('agreements.onshore-engagement', $payload)
            ->setPaper('a4')
            ->setOption('isPhpEnabled', true);
        $binary = $pdf->output();

        $safeName = $this->safeBaseName($payload['client_name'] ?: 'Client');
        $filename = "Onshore-Engagement-{$safeName}.pdf";
        $path = "lead-documents/{$lead->id}/".Str::random(12)."-{$filename}";

        Storage::disk(self::DISK)->put($path, $binary);

        return LeadDocument::create([
            'lead_id' => $lead->id,
            'request_id' => null,
            'checklist_key' => 'agree.consultancy',
            'original_name' => $filename,
            'file_path' => $path,
            'mime' => 'application/pdf',
            'size' => strlen($binary),
            'status' => LeadDocument::STATUS_SUBMITTED,
            'source' => LeadDocument::SOURCE_GENERATED,
            'source_variant' => 'consultancy:onshore:single',
            'uploaded_by' => Auth::id(),
        ]);
    }

    /**
     * The 4-scenario catalogue — matches the Word template's Application
     * Type table. `is_couple` toggles whether the couple cost breakdown
     * renders alongside the single one on pages 3-4 of the PDF.
     */
    public static function consultancyScenarios(): array
    {
        return [
            'std_150' => [
                'key' => 'std_150',
                'number' => 1,
                'title' => 'STANDARD 150,000',
                'applicant' => 'MAIN APPLICANT (Single and Couple)',
                'description' => 'School Enrollment and Documentation Fee, Immigration New Zealand (INZ) visa application fee',
                'default_school_fee' => 150000,
                'has_voucher' => false,
                'is_couple' => true,
                'supports_both' => true,
                'file_suffix' => 'std150',
            ],
            'voucher_150' => [
                'key' => 'voucher_150',
                'number' => 2,
                'title' => 'WITH VOUCHER 150,000',
                'applicant' => 'MAIN APPLICANT (Single and Couple)',
                'description' => 'School Enrollment and Documentation Fee, <strong>inclusive</strong> of the Immigration New Zealand (INZ) visa application fee',
                'default_school_fee' => 150000,
                'has_voucher' => true,
                'is_couple' => true,
                'supports_both' => true,
                'file_suffix' => 'voucher150',
            ],
            'std_100' => [
                'key' => 'std_100',
                'number' => 3,
                'title' => 'STANDARD',
                'applicant' => 'MAIN APPLICANT (Single)',
                'description' => 'School Enrollment and Documentation Fee',
                'default_school_fee' => 100000,
                'has_voucher' => false,
                'is_couple' => false,
                'supports_both' => false,
                'file_suffix' => 'std100',
            ],
            'english_100' => [
                'key' => 'english_100',
                'number' => 3,
                'title' => 'WITH ENGLISH',
                'applicant' => 'MAIN APPLICANT (Single)',
                'description' => 'School Enrollment and Documentation Fee',
                'default_school_fee' => 100000,
                'has_voucher' => false,
                'is_couple' => false,
                'supports_both' => false,
                'file_suffix' => 'english100',
            ],
        ];
    }

    /**
     * Study Proposal — placeholder template rendered per-lead. Stored
     * against checklist_key='agree.proposal' so it surfaces in the
     * Documents tab's Agreements section alongside the two existing
     * agreement types.
     */
    public function proposal(Lead $lead): LeadDocument
    {
        $clientName = trim("{$lead->first_name} {$lead->last_name}");
        $clientReference = Str::slug($clientName ?: 'ClientName', '');
        $today = now();
        $dateLine = $today->format('jS').' day of '.$today->format('F Y');

        $payload = [
            'client_name' => $clientName,
            'client_reference' => $clientReference ?: 'ClientName',
            'preferred_course' => $lead->preferred_course,
            'preferred_intake' => $lead->preferred_intake,
            'preferred_city_of_study' => $lead->preferred_city_of_study,
            'target_institution' => $lead->target_institution,
            'generated_at' => $today,
            'generated_at_formatted' => $dateLine,
        ];

        $pdf = Pdf::loadView('agreements.proposal', $payload)->setPaper('a4');
        $binary = $pdf->output();

        $safeName = $this->safeBaseName($clientName ?: 'Client');
        $filename = "Proposal-{$safeName}.pdf";
        $path = "lead-documents/{$lead->id}/".Str::random(12)."-{$filename}";

        Storage::disk(self::DISK)->put($path, $binary);

        return LeadDocument::create([
            'lead_id' => $lead->id,
            'request_id' => null,
            'checklist_key' => 'agree.proposal',
            'original_name' => $filename,
            'file_path' => $path,
            'mime' => 'application/pdf',
            'size' => strlen($binary),
            'status' => LeadDocument::STATUS_SUBMITTED,
            'source' => LeadDocument::SOURCE_GENERATED,
            'source_variant' => 'proposal',
            'uploaded_by' => Auth::id(),
        ]);
    }

    private function safeBaseName(string $name): string
    {
        // Strip spaces + non-alphanum so the filename is portable.
        return preg_replace('/[^A-Za-z0-9]/', '', $name) ?: 'Client';
    }
}
