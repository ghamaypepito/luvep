<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Lead;
use App\Support\CaseRecordAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Unified entry point for every public form that creates/updates a Lead.
 *
 * Behaviour:
 *   • Looks up an existing Lead by email (case-insensitive).
 *   • If found → backfills empty fields, writes a `lead.resubmitted`
 *     activity entry so the History tab shows the new touchpoint.
 *   • If not found → creates a new Lead, captures UTM parameters as
 *     first-touch attribution.
 *
 * Used by:
 *   - QuickLeadController        (hero / dead-end soft captures)
 *   - LeadController              (free-assessment full funnel)
 *   - BookingController           (consultation booking form)
 *   - EventController             (event registration form)
 *
 * Every form posting through this service writes to the same `leads` table
 * and the same `activity_logs` audit trail — so sales sees one funnel.
 */
class LeadIntakeService
{
    /**
     * Idempotent intake — finds-or-creates a Lead from a form payload.
     *
     * @param  string  $formType  short slug for the source form, e.g.
     *                            'free-assessment', 'quick-lead:hero',
     *                            'booking', 'event:PH-TOUR-2026'.
     * @param  array  $payload  normalised lead fields: first_name,
     *                          last_name, email, phone, country, …
     * @param  Request|null  $request  optional HTTP request — used to
     *                                 capture UTM params from the URL.
     *
     * @throws \Throwable on DB / model failures (caller decides response)
     */
    public function ingest(string $formType, array $payload, ?Request $request = null): Lead
    {
        return DB::transaction(function () use ($formType, $payload, $request) {
            $email = isset($payload['email']) ? trim((string) $payload['email']) : null;

            // Case-insensitive lookup — many users vary capitalisation.
            $existing = $email
                ? Lead::whereRaw('LOWER(email) = ?', [strtolower($email)])->first()
                : null;

            return $existing
                ? $this->attachToExisting($existing, $formType, $payload)
                : $this->createNew($formType, $payload, $request);
        });
    }

    /** Append a touchpoint to an already-known Lead. Backfills missing fields. */
    private function attachToExisting(Lead $lead, string $formType, array $payload): Lead
    {
        try {
            // Only backfill fields that are currently empty — never overwrite
            // values the lead has already provided in an earlier (richer) form.
            $backfill = array_filter([
                'first_name' => empty($lead->first_name) ? ($payload['first_name'] ?? null) : null,
                'last_name' => empty($lead->last_name) ? ($payload['last_name'] ?? null) : null,
                'phone' => empty($lead->phone) ? ($payload['phone'] ?? null) : null,
                'residence_country' => empty($lead->residence_country) ? ($payload['country'] ?? null) : null,
            ], fn ($v) => ! is_null($v) && $v !== '');

            $backfill = CaseRecordAccess::preserveCaseIdentity($lead, $backfill);
            empty($backfill) ?: $lead->update($backfill);

            ActivityLog::record('lead.resubmitted', [
                'description' => "Re-submitted via {$formType}",
                'properties' => [
                    'subject_type' => 'Lead',
                    'subject_id' => $lead->id,
                    'subject_label' => trim("{$lead->first_name} {$lead->last_name}") ?: ($lead->email ?? 'Lead'),
                    'form_type' => $formType,
                    'backfilled' => array_keys($backfill),
                ],
            ]);
        } catch (\Throwable $e) {
            // Auditing must never break the actual intake flow.
            Log::error('LeadIntakeService::attachToExisting log failed', [
                'lead_id' => $lead->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $lead->fresh();
    }

    /** Create a fresh Lead with first-touch attribution. */
    private function createNew(string $formType, array $payload, ?Request $request): Lead
    {
        $lead = Lead::create(array_merge(
            [
                'lead_id' => $payload['lead_id'] ?? Lead::generateLeadId(),
                'first_name' => $payload['first_name'] ?? '',
                'last_name' => $payload['last_name'] ?? '',
                'email' => $payload['email'] ?? null,
                'phone' => $payload['phone'] ?? null,
                'residence_country' => $payload['country'] ?? null,
                // Defensive cap — long event slugs (source = event:{code}) must
                // never overflow the column and break registration.
                'source' => mb_substr((string) $formType, 0, 191),
                'status' => 'New Leads',
                'stage' => $payload['stage'] ?? null,
            ],
            $this->captureUtm($request)
        ));

        // The "Track your ePathways application" (tracker_welcome) email is NOT
        // auto-sent on lead creation — it goes out only when staff explicitly
        // send the tracking link (LeadController::sendTrackerLink), so public
        // form submitters get just that form's own confirmation.

        return $lead;
    }

    /**
     * Pull UTM params off the request URL — captured ONCE per lead (the
     * first form they fill). Returns an array of `utm_*` keys ready for
     * Lead::create.
     */
    private function captureUtm(?Request $request): array
    {
        return $request
            ? [
                'utm_source' => $request->query('utm_source'),
                'utm_medium' => $request->query('utm_medium'),
                'utm_campaign' => $request->query('utm_campaign'),
                'utm_content' => $request->query('utm_content'),
            ]
            : [];
    }
}
