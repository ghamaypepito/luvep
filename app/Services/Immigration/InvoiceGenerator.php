<?php

namespace App\Services\Immigration;

use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\VisaType;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Generates the ePathways Migration tax invoice for an immigration case.
 *
 * Line items default from the case's visa on the Visas page — the
 * professional fee becomes the "Consulting and Service Fee" line and the
 * INZ application fee becomes the disbursement line — but staff can
 * override amounts, dates and the invoice number from the New modal.
 *
 * Stored as a LeadDocument (source='generated', source_variant='invoice')
 * so it surfaces on the case's Documents tab and the Invoice workspace.
 */
class InvoiceGenerator
{
    private const DISK = 'local';

    private const VIEW = 'invoices.tax-invoice';

    /** Invoice numbers start here when no invoice exists yet. */
    private const FIRST_NUMBER = 116;

    public const COMPANY = [
        'name' => 'ePathways Migration',
        'address_1' => '4/15L Mercari Way, Albany',
        'address_2' => 'Auckland 0632',
        'country' => 'NEW ZEALAND',
        'gst' => '144-821-777',
    ];

    public const BANK = [
        'account_name' => 'D IMMIGRATION CONSULTANCY LTD t/a ePathways Migration',
        'account_number' => '01-0186-0596059-00',
        'bank_name' => 'ANZ Bank',
        'bank_address' => '22 Stoddard Road, Wesley, Auckland 1041, New Zealand',
        'swift' => 'ANZBNZ22',
    ];

    /**
     * Next sequential invoice number, e.g. "INV-0117". Derived from the
     * highest number already issued so it never collides.
     */
    public function nextInvoiceNumber(): string
    {
        $highest = LeadDocument::whereNotNull('invoice_number')
            ->get(['invoice_number'])
            ->map(fn ($d) => (int) preg_replace('/\D/', '', (string) $d->invoice_number))
            ->max();

        $next = max((int) $highest + 1, self::FIRST_NUMBER);

        return 'INV-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Default settings for the New-invoice modal — visa-derived fees, the
     * next invoice number, and today / +7 days dates.
     */
    public function defaultsFor(Lead $lead, string $tier = 'normal', bool $includeGst = false, string $location = 'onshore'): array
    {
        $visa = $lead->inz_visa_type ? VisaType::where('name', $lead->inz_visa_type)->first() : null;
        $tier = $tier === 'discounted' ? 'discounted' : 'normal';
        $location = $location === 'offshore' ? 'offshore' : 'onshore';

        // Fees are stored exclusive of GST; staff choose per invoice whether
        // the service fee is quoted ex-GST or as the GST-inclusive RRP. The
        // INZ fee is a government charge and is never uplifted.
        $serviceFee = $visa?->professionalFeeFor($tier, $location);
        if ($includeGst && $serviceFee !== null) {
            $serviceFee = round($serviceFee * (1 + VisaType::GST_RATE), 2);
        }

        return [
            'invoice_number' => $this->nextInvoiceNumber(),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'visa_label' => $lead->inz_visa_type ?: 'Visa',
            // Service fee follows the chosen pricing tier — discounted (pay
            // now) or normal (payment plan) — at the applicant's location.
            'fee_tier' => $tier,
            'fee_location' => $location,
            'include_gst' => $includeGst,
            'service_fee' => $serviceFee,
            // The INZ disbursement differs by location too.
            'inz_fee' => $visa?->inzFeeFor($location),
            'include_disbursement' => true,
        ];
    }

    /**
     * The two standard line items derived from the case's visa fees. Used
     * as the starting point in the invoice settings, where staff can edit
     * them or add their own rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function defaultItems(string $visaLabel, float $serviceFee, float $inzFee): array
    {
        $items = [];

        if ($serviceFee > 0) {
            $items[] = [
                'description' => "Consulting and Service Fee - [{$visaLabel} Application] (assessing client's eligibility, "
                    .'documents review, providing advice and lodging the above visa application on behalf of client) - pay in advance',
                'quantity' => 1,
                'unit_price' => $serviceFee,
                'amount' => $serviceFee,
            ];
        }

        if ($inzFee > 0) {
            $items[] = [
                'description' => "Disbursement - INZ [{$visaLabel}] application fee - pay in advance",
                'quantity' => 1,
                'unit_price' => $inzFee,
                'amount' => $inzFee,
            ];
        }

        return $items;
    }

    /** Build the Blade payload for an invoice. */
    public function payload(Lead $lead, array $o = []): array
    {
        // Defaults follow the chosen tier + GST setting, so an unedited
        // service_fee picks the right price for the quote.
        $defaults = $this->defaultsFor(
            $lead,
            $o['fee_tier'] ?? 'normal',
            ! empty($o['include_gst']),
            $o['fee_location'] ?? 'onshore'
        );
        $visaLabel = $o['visa_label'] ?? $defaults['visa_label'];

        $serviceFee = (float) ($o['service_fee'] ?? $defaults['service_fee'] ?? 0);
        // Engagement path (no explicit service_fee): honour the principal's
        // per-applicant fee override on a single-applicant invoice.
        if (! array_key_exists('service_fee', $o)) {
            $eng = app(EngagementDocumentGenerator::class);
            $apps = $eng->familyApplicants($lead);
            if (count($apps) === 1) {
                $pf = $eng->applicantFee($apps[0], $o['fee_tier'] ?? 'normal', $o['fee_location'] ?? 'onshore');
                if ($pf !== null) {
                    $serviceFee = ! empty($o['include_gst']) ? round($pf * (1 + VisaType::GST_RATE), 2) : $pf;
                }
            }
        }
        $inzFee = (float) ($o['inz_fee'] ?? $defaults['inz_fee'] ?? 0);
        // Engagement path (no explicit inz_fee): honour the principal's
        // per-applicant disbursement override on a single-applicant invoice, so
        // the invoice total matches the agreement's.
        if (! array_key_exists('inz_fee', $o)) {
            $eng = app(EngagementDocumentGenerator::class);
            $apps = $eng->familyApplicants($lead);
            if (count($apps) === 1) {
                $d = $eng->applicantDisbursement($apps[0], $o['fee_location'] ?? 'onshore');
                if ($d !== null) {
                    $inzFee = (float) $d;
                }
            }
        }
        $includeDisbursement = array_key_exists('include_disbursement', $o)
            ? (bool) $o['include_disbursement']
            : $defaults['include_disbursement'];

        // Staff can supply a fully custom line-item list from the invoice
        // settings; otherwise fall back to the visa-derived default lines.
        if (! empty($o['items']) && is_array($o['items'])) {
            $items = [];
            foreach ($o['items'] as $it) {
                $desc = trim((string) ($it['description'] ?? ''));
                $qty = (float) ($it['quantity'] ?? 1);
                $unit = (float) ($it['unit_price'] ?? 0);
                if ($desc === '' && $unit <= 0) {
                    continue; // skip blank rows
                }
                $items[] = [
                    'description' => $desc,
                    'quantity' => $qty,
                    'unit_price' => $unit,
                    'amount' => round($qty * $unit, 2),
                ];
            }
        } else {
            $items = $this->defaultItems($visaLabel, $serviceFee, $includeDisbursement ? $inzFee : 0);
        }

        // Family invoice: when the case has costed dependants and staff haven't
        // supplied custom line items, render one section per applicant — the
        // principal first, then partner, then children — each with its own
        // consulting fee and INZ disbursement. A lone applicant keeps the single
        // table above.
        $groups = null;
        if (empty($o['items'])) {
            $tier = ($o['fee_tier'] ?? 'normal') === 'discounted' ? 'discounted' : 'normal';
            $location = ($o['fee_location'] ?? 'onshore') === 'offshore' ? 'offshore' : 'onshore';
            $incGst = ! empty($o['include_gst']);
            $applicants = app(EngagementDocumentGenerator::class)->familyApplicants($lead);

            if (count($applicants) > 1) {
                $groups = [];
                $eng = app(EngagementDocumentGenerator::class);
                foreach ($applicants as $a) {
                    $vm = $a['visa_model'];
                    // Per-applicant fee override (else the visa's fee) — matches
                    // the written agreement's per-applicant lines.
                    $sf = $eng->applicantFee($a, $tier, $location);
                    if ($incGst && $sf !== null) {
                        $sf = round($sf * (1 + VisaType::GST_RATE), 2);
                    }
                    // Per-applicant disbursement override (else the visa's INZ
                    // fee) — matches the written agreement's disbursement lines.
                    $inz = $includeDisbursement ? $eng->applicantDisbursement($a, $location) : null;
                    $gItems = $this->defaultItems($a['visa'] ?: 'Visa', (float) ($sf ?? 0), (float) ($inz ?? 0));
                    $groups[] = [
                        'name' => $a['name'],
                        'visa' => $a['visa'] ?: 'Visa',
                        'items' => $gItems,
                        'subtotal' => array_sum(array_column($gItems, 'amount')),
                    ];
                }
            }
        }

        $total = $groups
            ? array_sum(array_column($groups, 'subtotal'))
            : array_sum(array_column($items, 'amount'));

        return [
            'logo_data' => $this->logoData(),
            'company' => self::COMPANY,
            'bank' => self::BANK,
            'client' => [
                'name' => trim("{$lead->first_name} {$lead->last_name}") ?: 'Client',
                'email' => $lead->email,
            ],
            'invoice' => [
                'number' => $o['invoice_number'] ?? $defaults['invoice_number'],
                'date' => $this->fmtDate($o['invoice_date'] ?? $defaults['invoice_date']),
                'due_date' => $this->fmtDate($o['due_date'] ?? $defaults['due_date']),
                'pay_url' => $o['pay_url'] ?? null,
            ],
            'items' => $items,
            // One section per applicant for a family invoice; null for a single
            // applicant (the blade then renders the flat items table).
            'groups' => $groups,
            'total' => $total,
        ];
    }

    /** Render to HTML for the live preview iframe. */
    public function renderHtml(Lead $lead, array $o = []): string
    {
        $payload = $this->payload($lead, $o);
        $payload['preview'] = true;

        return view(self::VIEW, $payload)->render();
    }

    /** Render to raw PDF bytes (no persistence). */
    public function pdfBinary(Lead $lead, array $o = []): string
    {
        return Pdf::loadView(self::VIEW, $this->payload($lead, $o))->setPaper('a4')->output();
    }

    /** Render to a PDF and store it against the case. */
    public function generate(Lead $lead, array $o = []): LeadDocument
    {
        $payload = $this->payload($lead, $o);
        $binary = Pdf::loadView(self::VIEW, $payload)->setPaper('a4')->output();

        $number = $payload['invoice']['number'];
        $safeName = preg_replace('/[^A-Za-z0-9]/', '', trim("{$lead->first_name} {$lead->last_name}")) ?: 'Client';
        $filename = "Invoice-{$number}-{$safeName}.pdf";
        $path = "lead-documents/{$lead->id}/".Str::random(12)."-{$filename}";

        Storage::disk(self::DISK)->put($path, $binary);

        $doc = LeadDocument::create([
            'lead_id' => $lead->id,
            'request_id' => null,
            'checklist_key' => 'invoice',
            'original_name' => $filename,
            'file_path' => $path,
            'mime' => 'application/pdf',
            'size' => strlen($binary),
            'status' => LeadDocument::STATUS_SUBMITTED,
            'source' => LeadDocument::SOURCE_GENERATED,
            'source_variant' => 'invoice',
            'invoice_number' => $number,
            'invoice_total' => $payload['total'] ?? null,
            'uploaded_by' => Auth::id(),
        ]);

        // Email automation — invoice sent.
        app(\App\Services\EmailAutomationService::class)->fire('immigration.invoice.sent', $lead, [
            'invoice_number' => $number,
            'invoice_total' => $payload['total'] ?? null,
        ]);

        return $doc;
    }

    /** "30 Jun 2026" — matches the invoice format. */
    private function fmtDate($value): string
    {
        try {
            return Carbon::parse($value)->format('j M Y');
        } catch (\Throwable $e) {
            return (string) $value;
        }
    }

    private function logoData(): string
    {
        // PDF-optimised copy of the immigration logo — the source master is
        // 15,625px / 1.1MB, which OOMs dompdf when embedded; this is the same
        // logo downscaled for documents.
        $path = base_path('resources/assets/Immigration/dimmigration-logo-pdf.png');
        if (! is_file($path)) {
            $path = base_path('resources/assets/Immigration/migration_logo.png');
        }
        if (! is_file($path)) {
            $path = base_path('resources/assets/philipine_ep_logo.png');
        }

        return 'data:image/png;base64,'.base64_encode(file_get_contents($path));
    }
}
