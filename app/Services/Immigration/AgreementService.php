<?php

namespace App\Services\Immigration;

use App\Exceptions\MissingAgreementVariablesException;
use App\Models\Agreement;
use App\Models\AgreementTemplate;
use App\Models\Lead;
use App\Services\PdfGenerator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/**
 * Build 11.D Phase 2 — Managed agreement workflow.
 *
 * Renders DB-stored templates with {{variable}} placeholders into a PDF
 * and tracks the lifecycle (draft → sent → signed). Distinct from
 * App\Services\AgreementGenerator, which renders fixed Blade templates
 * as LeadDocument attachments — the two systems coexist.
 *
 * Variable resolution order in resolveVariables():
 *   1. Auto-resolved from the Lead row (always wins for these keys)
 *   2. Caller-supplied extras (consultancy fee, payment terms, etc.)
 *
 * Required variables are declared on AgreementTemplate.required_variables.
 * Generation throws MissingAgreementVariablesException if any are unfilled
 * so the caller can prompt the consultant.
 *
 * Phase 3 (signing) adds send() → sets tracker_signing_token + sent_at +
 * status='sent'. Sign itself happens in StubSignatureProvider, not here.
 */
class AgreementService
{
    public function __construct(protected PdfGenerator $pdf) {}

    /**
     * Generate a draft agreement: render template, persist PDF, save row.
     *
     * Two modes:
     *  - Template: pass an AgreementTemplate; its body ({{vars}}) is rendered
     *    and its required_variables enforced (the original behaviour).
     *  - Free body: pass $overrideBody (staff-typed HTML, e.g. the blank
     *    "initial agreement") with $template = null. The body is still run
     *    through renderTemplate() so any {{first_name}} placeholders fill, but
     *    no required-variable check applies and no template row is referenced.
     */
    public function generate(Lead $lead, ?AgreementTemplate $template = null, array $extraVariables = [], ?string $overrideBody = null, ?string $overrideTitle = null): Agreement
    {
        if ($overrideBody === null && ! $template) {
            throw new \InvalidArgumentException('generate() needs either a template or an override body.');
        }

        $variables = $this->resolveVariables($lead, $extraVariables);

        if ($template && $overrideBody === null) {
            $missing = $this->findMissingRequired($template, $variables);
            if (! empty($missing)) {
                throw new MissingAgreementVariablesException($missing);
            }
        }

        $title = $overrideTitle ?? $template?->name ?? 'Engagement Agreement';
        $rendered = $this->renderTemplate($overrideBody ?? $template->body, $variables);

        $pdfPath = $this->pdf->renderToFile(
            $this->wrapInHtmlShell($rendered, $title),
            "agreements/{$lead->id}/".now()->format('Y-m-d_His').'-'.Str::random(6).'.pdf'
        );

        return Agreement::create([
            'lead_id' => $lead->id,
            'agreement_template_id' => $template?->id,
            'generated_by_user_id' => Auth::id(),
            'title' => $title,
            'content_rendered' => $rendered,
            'pdf_path' => $pdfPath,
            'status' => Agreement::STATUS_DRAFT,
            'variables_used' => $variables,
        ]);
    }

    /**
     * Move a draft into 'sent'. Generates the tracker signing token that
     * Phase 3's TrackerAgreementController resolves against — the client
     * receives a URL containing this token (no internal IDs exposed).
     *
     * The actual email send is delegated to Phase 3's integration with the
     * notification / messaging surface — this method ships the state
     * transition + token so consumers can compose the URL.
     */
    public function send(Agreement $agreement): Agreement
    {
        if ($agreement->status !== Agreement::STATUS_DRAFT) {
            throw new \DomainException("Only draft agreements can be sent (current status: {$agreement->status}).");
        }

        // Idempotency: only mint a token if one isn't already present.
        // In practice the status guard above already protects against the
        // common double-click case (status flips to 'sent' on first call,
        // so the second call's DRAFT check throws). Belt-and-braces in
        // case a future caller relaxes the status check.
        if (empty($agreement->tracker_signing_token)) {
            $agreement->tracker_signing_token = Str::random(48);
        }
        $agreement->status = Agreement::STATUS_SENT;
        $agreement->sent_at = now();
        $agreement->save();

        return $agreement;
    }

    /**
     * Mark an agreement voided. The PDF stays on disk for the audit trail;
     * the row just changes status. Signed agreements cannot be voided.
     */
    public function void(Agreement $agreement, ?string $reason = null): Agreement
    {
        if ($agreement->status === Agreement::STATUS_SIGNED) {
            throw new \DomainException('Signed agreements cannot be voided.');
        }

        $agreement->status = Agreement::STATUS_VOIDED;
        $agreement->save();

        return $agreement;
    }

    /**
     * Combine auto-resolved lead fields with caller-supplied extras.
     * Extras can override auto-resolved keys when present and non-empty.
     */
    public function resolveVariables(Lead $lead, array $extras = []): array
    {
        $auto = [
            'client_name' => trim(($lead->first_name ?? '').' '.($lead->last_name ?? '')) ?: 'Client',
            'client_email' => $lead->email ?? '',
            'client_phone' => $lead->phone ?? '',
            'visa_type' => $lead->inz_visa_type ?? 'Visa application',
            'agreement_date' => now()->format('d F Y'),
            'consultant_name' => Auth::user()->name ?? 'Consultant',
            'consultancy_name' => config('app.name', 'ePathways Limited'),
        ];

        $filtered = array_filter($extras, fn ($v) => $v !== null && $v !== '');

        return array_merge($auto, $filtered);
    }

    /**
     * Substitute {{key}} placeholders. Unknown keys render as
     * "[key not provided]" so the issue is visible in the rendered PDF
     * rather than silently dropping into an empty string.
     */
    public function renderTemplate(string $body, array $variables): string
    {
        return preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', function ($match) use ($variables) {
            $key = $match[1];

            return array_key_exists($key, $variables) && $variables[$key] !== ''
                ? (string) $variables[$key]
                : "[{$key} not provided]";
        }, $body);
    }

    /**
     * Returns required-variable keys whose values are missing or empty.
     */
    public function findMissingRequired(AgreementTemplate $template, array $variables): array
    {
        $required = $template->required_variables ?? [];

        return array_values(array_filter(
            $required,
            fn ($key) => ! isset($variables[$key]) || $variables[$key] === '' || $variables[$key] === null
        ));
    }

    /**
     * Wrap the rendered content in the print-friendly Blade shell that
     * sets page styling, fonts, etc.
     */
    protected function wrapInHtmlShell(string $content, string $title): string
    {
        return View::make('agreements.pdf-shell', [
            'title' => $title,
            'content' => $content,
        ])->render();
    }
}
