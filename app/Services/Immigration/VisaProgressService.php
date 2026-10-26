<?php

namespace App\Services\Immigration;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Reads and writes the INZ-style visa sub-status tracker on an immigration case
 * (leads.visa_progress JSON). Merges the config catalogue (config/visa_progress)
 * with per-case stored statuses so new catalogue activities appear automatically
 * and each activity keeps an append-only status history (past + current).
 *
 * Procedural status tracking only — this is not immigration advice and is never
 * routed through AdviceBearingPolicy.
 */
class VisaProgressService
{
    /** Status vocabulary + legend text, keyed by status. */
    public function statuses(): array
    {
        return (array) config('visa_progress.statuses', []);
    }

    /** Ordered activity catalogue from config. */
    public function catalogue(): array
    {
        return (array) config('visa_progress.activities', []);
    }

    public function trackedStages(): array
    {
        return (array) config('visa_progress.tracked_stages', []);
    }

    public function defaultStatus(): string
    {
        return (string) config('visa_progress.default_status', 'not_started');
    }

    /** True when the case's current immigration stage shows the tracker. */
    public function isTracked(Lead $lead): bool
    {
        return in_array($lead->immigration_stage, $this->trackedStages(), true);
    }

    public function isValidStatus(?string $status): bool
    {
        return $status !== null && array_key_exists($status, $this->statuses());
    }

    public function isValidActivity(?string $key): bool
    {
        return $key !== null && collect($this->catalogue())->contains(fn ($a) => $a['key'] === $key);
    }

    /**
     * Full tracker payload for the frontend: merged activities (catalogue order)
     * carrying each one's current status + history, plus the lodge/outcome dates,
     * overall application status, the legend, and a compact summary.
     */
    public function get(Lead $lead): array
    {
        $stored = (array) ($lead->visa_progress ?? []);
        $storedActivities = (array) ($stored['activities'] ?? []);
        $default = $this->defaultStatus();

        $activities = array_map(function (array $def) use ($storedActivities, $default) {
            $row = (array) ($storedActivities[$def['key']] ?? []);

            return [
                'key' => $def['key'],
                'label' => $def['label'],
                'group' => $def['group'],
                'description' => $row['description'] ?? $def['description'],
                'status' => $row['status'] ?? $default,
                'note' => $row['note'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
                'history' => array_values((array) ($row['history'] ?? [])),
            ];
        }, $this->catalogue());

        return [
            'tracked' => $this->isTracked($lead),
            'stage' => $lead->immigration_stage,
            'lodged_at' => optional($lead->{config('visa_progress.lodged_at_column', 'inz_lodged_at')})?->toIso8601String(),
            'decision_at' => optional($lead->{config('visa_progress.decision_at_column', 'inz_decision_at')})?->toIso8601String(),
            'application_status' => $stored['application_status'] ?? null,
            'statuses' => $this->statuses(),
            'activities' => $activities,
            'summary' => $this->summary($lead),
        ];
    }

    /**
     * Set one activity's status, appending the previous → new transition to that
     * activity's history (so past and current both survive). Idempotent: setting
     * the same status again is a no-op that still records who confirmed it.
     */
    public function setActivityStatus(Lead $lead, string $key, string $status, ?string $note, ?User $user): array
    {
        if (! $this->isValidActivity($key) || ! $this->isValidStatus($status)) {
            throw new \InvalidArgumentException('Unknown visa-progress activity or status.');
        }

        $data = (array) ($lead->visa_progress ?? []);
        $data['activities'] ??= [];
        $row = (array) ($data['activities'][$key] ?? []);

        $row['status'] = $status;
        $row['note'] = $note ?: null;
        $row['updated_at'] = Carbon::now()->toIso8601String();
        $row['history'][] = [
            'status' => $status,
            'note' => $note ?: null,
            'at' => Carbon::now()->toIso8601String(),
            'by_id' => $user?->id,
            'by_name' => $user?->name,
        ];

        $data['activities'][$key] = $row;
        $lead->visa_progress = $data;
        $lead->save();

        return $this->get($lead);
    }

    /** Set the overall INZ application-status label (e.g. "Under Assessment"). */
    public function setApplicationStatus(Lead $lead, ?string $status): array
    {
        $data = (array) ($lead->visa_progress ?? []);
        $data['application_status'] = $status ? trim($status) : null;
        $lead->visa_progress = $data;
        $lead->save();

        return $this->get($lead);
    }

    /**
     * Compact badge for the cases table / collapsed row: current stage, the
     * activity currently in focus, and completed-of-total counts. Null when the
     * case is not in a tracked stage.
     */
    public function summary(Lead $lead): ?array
    {
        if (! $this->isTracked($lead)) {
            return null;
        }

        $stored = (array) ($lead->visa_progress['activities'] ?? []);
        $default = $this->defaultStatus();
        $catalogue = $this->catalogue();

        $statusFor = fn (string $key) => $stored[$key]['status'] ?? $default;

        $total = count($catalogue);
        $completed = collect($catalogue)->filter(fn ($a) => in_array($statusFor($a['key']), ['completed', 'not_applicable'], true))->count();

        // "Current" = the first activity actively being worked, else the last
        // completed, else the first not-started.
        $active = collect($catalogue)->first(fn ($a) => in_array($statusFor($a['key']), ['in_progress', 'info_requested'], true));
        $current = $active
            ?? collect($catalogue)->last(fn ($a) => $statusFor($a['key']) === 'completed')
            ?? $catalogue[0] ?? null;

        return [
            'stage' => $lead->immigration_stage,
            'application_status' => $lead->visa_progress['application_status'] ?? null,
            'current' => $current ? ['key' => $current['key'], 'label' => $current['label'], 'status' => $statusFor($current['key'])] : null,
            'completed' => $completed,
            'total' => $total,
        ];
    }
}
