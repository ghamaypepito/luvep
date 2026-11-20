<?php

namespace App\Services\Immigration;

use App\Models\AssessmentAiReview;
use App\Models\User;
use App\Services\OpenRouterService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * AI completeness & consistency review of a visa-assessment intake (internal,
 * indicative). It reads the submitted form and surfaces, for the licensed
 * adviser: missing/blank fields, internal inconsistencies, and things to verify.
 *
 * Hard boundaries (immigration AI guardrails):
 *  - §1/§2: NOT eligibility advice or a decision. Observations only, unsigned,
 *    staff-internal. The adviser still does the assessment.
 *  - §4: the model must not emit fees/amounts.
 *  - §10: grounded strictly in the provided data; a blank field is "not
 *    provided", never a guessed value.
 *  - §11: every run is logged with the reviewer + model, and stored.
 */
class AssessmentReviewService
{
    public function __construct(private OpenRouterService $ai) {}

    /** Field names that are plumbing, not part of the applicant's answers. */
    private const SKIP = [
        'id', 'created_at', 'updated_at', 'deleted_at', 'edit_token', 'token',
        'booking_id', 'intakeable_type', 'intakeable_id',
        'payment_status', 'payment_session_id', 'payment_amount_cents',
        'payment_currency', 'paid_at',
        'english_evidence_exception',
    ];

    /**
     * Run a review for an intake and persist it. Returns the stored review.
     *
     * @param  Model  $intake  a Resident/Work/Student/Visitor intake
     * @param  string  $visaType  resident|work|student|visitor
     */
    public function review(Model $intake, string $visaType, User $reviewer): AssessmentAiReview
    {
        $fields = $this->serialize($intake);

        $reply = $this->ai->chat([
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => "Visa type (client's stated interest): {$visaType}\n\nSubmitted intake:\n{$fields}"],
        ], ['temperature' => 0.1, 'max_tokens' => 2200]);

        $pack = $this->parse($reply);

        $review = AssessmentAiReview::create([
            'intakeable_type' => $intake::class,
            'intakeable_id' => $intake->getKey(),
            'reviewed_by' => $reviewer->id,
            'provider' => 'openrouter',
            'model' => $this->ai->model(),
            'summary' => $pack['summary'],
            'observations' => $pack['observations'],
            'risks' => $pack['risks'],
            'checklist' => $pack['checklist'],
            'adviser_note' => $pack['adviser_note'],
            'client_email' => $pack['client_email'],
            'raw' => $reply,
        ]);

        // Auditability (§11): attributable record that a review ran.
        Log::info('Assessment AI review', [
            'intakeable_type' => $intake::class,
            'intakeable_id' => $intake->getKey(),
            'reviewed_by' => $reviewer->id,
            'model' => $this->ai->model(),
            'observation_count' => count($pack['observations']),
            'risk_count' => count($pack['risks']),
        ]);

        return $review;
    }

    /** Human-readable "Label: value" dump of the applicant's answers. */
    private function serialize(Model $intake): string
    {
        $lines = [];
        foreach ($intake->getAttributes() as $key => $value) {
            if (in_array($key, self::SKIP, true)) {
                continue;
            }
            $label = ucwords(str_replace('_', ' ', $key));
            if ($value === null || $value === '') {
                $lines[] = "{$label}: (not provided)";

                continue;
            }
            if (is_array($value)) {
                $value = json_encode($value);
            }
            $str = (string) $value;
            if (mb_strlen($str) > 300) {
                $str = mb_substr($str, 0, 300).'…';
            }
            $lines[] = "{$label}: {$str}";
        }

        return implode("\n", $lines);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are assisting a Licensed Immigration Adviser (LIA) at a New Zealand immigration
firm. You are preparing an INTERNAL, INDICATIVE work-up of a client's SUBMITTED
visa intake so the adviser can review a case in minutes. You are scaffolding for
the adviser — you never make the decision or speak to the client directly.

STRICT COMPLIANCE BOUNDARIES — not preferences:
1. Do NOT assess eligibility, rank or recommend visa pathways, assign a match/
   confidence score, or predict any visa outcome. If tempted to say someone is a
   "strong/weak candidate" or "eligible/ineligible", DON'T — that is the LIA's
   licensed decision.
2. Use ONLY the data provided. Never invent facts, names, dates, or numbers. A
   blank field is "(not provided)" — say so; never guess a value.
3. Do NOT output any monetary amounts, fees, or prices.
4. Risks are things for the adviser to VERIFY/INVESTIGATE, phrased neutrally
   ("verify continuity of employment", "qualification may need NZQA recognition —
   confirm") — never a conclusion about the outcome.
5. The client email is a DRAFT for the adviser to approve and send. It states
   STATUS and PROCESS only ("we received your assessment; an adviser will review;
   we may need X documents"). It must NOT state eligibility, a pathway, or advice.
6. The document checklist is the standard document set for the client's STATED
   visa interest — process, not a recommendation of which visa to pursue.

Return ONLY valid JSON in exactly this shape, nothing else:
{
  "summary": "<3-5 sentence neutral factual profile of the applicant from the data>",
  "observations": [ { "severity": "check|info", "field": "<field/area>", "note": "<missing or inconsistent thing>" } ],
  "risks": [ { "severity": "check|info", "area": "<area>", "note": "<thing for the adviser to verify/investigate>" } ],
  "checklist": [ { "document": "<document name>", "required": true, "note": "<optional short note>" } ],
  "adviser_note": "<a short draft internal file note the adviser will edit: factual profile + what to verify + suggested next steps to CHECK. No eligibility conclusion.>",
  "client_email": { "subject": "<subject>", "body": "<status/process-only acknowledgement, no advice>" }
}
severity is "check" (needs adviser attention) or "info" (minor/FYI). Use empty
arrays where nothing applies and say so in the summary.
PROMPT;
    }

    /**
     * Decode the model's JSON reply into the structured adviser pack. Defensive:
     * unknown severities collapse to "info"; malformed replies yield empty
     * sections rather than throwing.
     *
     * @return array{summary: string, observations: array, risks: array, checklist: array, adviser_note: string, client_email: array}
     */
    private function parse(string $reply): array
    {
        $empty = [
            'summary' => 'Could not parse the review — see raw output.',
            'observations' => [], 'risks' => [], 'checklist' => [],
            'adviser_note' => '', 'client_email' => [],
        ];

        $decoded = json_decode($this->ai->extractJson($reply), true);
        if (! is_array($decoded)) {
            return $empty;
        }

        $issues = function ($list, string $labelKey) {
            $out = [];
            foreach ((is_array($list) ? $list : []) as $o) {
                if (! is_array($o) || empty($o['note'])) {
                    continue;
                }
                $out[] = [
                    'severity' => in_array(($o['severity'] ?? 'info'), ['check', 'info'], true) ? $o['severity'] : 'info',
                    $labelKey => (string) ($o[$labelKey] ?? ''),
                    'note' => (string) $o['note'],
                ];
            }

            return $out;
        };

        $checklist = [];
        foreach ((is_array($decoded['checklist'] ?? null) ? $decoded['checklist'] : []) as $c) {
            if (! is_array($c) || empty($c['document'])) {
                continue;
            }
            $checklist[] = [
                'document' => (string) $c['document'],
                'required' => (bool) ($c['required'] ?? false),
                'note' => (string) ($c['note'] ?? ''),
            ];
        }

        $email = is_array($decoded['client_email'] ?? null) ? $decoded['client_email'] : [];

        return [
            'summary' => is_string($decoded['summary'] ?? null) ? $decoded['summary'] : '',
            'observations' => $issues($decoded['observations'] ?? [], 'field'),
            'risks' => $issues($decoded['risks'] ?? [], 'area'),
            'checklist' => $checklist,
            'adviser_note' => is_string($decoded['adviser_note'] ?? null) ? $decoded['adviser_note'] : '',
            'client_email' => [
                'subject' => (string) ($email['subject'] ?? ''),
                'body' => (string) ($email['body'] ?? ''),
            ],
        ];
    }
}
