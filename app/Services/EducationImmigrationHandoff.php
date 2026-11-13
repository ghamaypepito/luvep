<?php

namespace App\Services;

use App\Models\Lead;

/** Consistent semantics when staff explicitly select an Education handoff stage. */
class EducationImmigrationHandoff
{
    public function prepare(Lead $lead, ?string $requestedStage): bool
    {
        if (! in_array($requestedStage, Lead::EDUCATION_STAGES_IMMIGRATION, true)
            || $lead->education_stage !== $requestedStage) {
            return false;
        }

        $converted = ! $lead->is_immigration_case;
        if ($converted) {
            $lead->is_immigration_case = true;
            $lead->immigration_converted_at = now();
            $lead->immigration_converted_by = auth()->id();
        }
        if (! filled($lead->inz_visa_type)) {
            $lead->inz_visa_type = 'Student Visa';
        }

        // Keep student membership, existing visa choices and parallel stages.
        return $converted;
    }

    public function notify(Lead $lead, bool $converted): void
    {
        if ($converted) {
            app(EmailAutomationService::class)->fire('immigration.education.handoff', $lead, [
                'visa_type' => $lead->inz_visa_type ?? '',
            ]);
        }
    }
}
