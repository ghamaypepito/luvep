<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\User;
use App\Support\LeadAccess;
use Illuminate\Support\Facades\DB;

/** Consistent semantics when staff explicitly select an Education handoff stage. */
class EducationImmigrationHandoff
{
    public static function isHandoffStage(?string $stage): bool
    {
        return in_array($stage, Lead::EDUCATION_STAGES_IMMIGRATION, true);
    }

    public function authorizeRecord(User $user, Lead $lead, ?string $stage, bool $educationIntake = false): void
    {
        if (self::isHandoffStage($stage)) {
            abort_unless($user->is_active && (LeadAccess::canView($user, $lead)
                || ($educationIntake && $user->role === 'education')), 403);
        }
    }

    public function prepare(Lead $lead, ?string $requestedStage): bool
    {
        if (! self::isHandoffStage($requestedStage)
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
            DB::afterCommit(fn () => app(EmailAutomationService::class)->fire('immigration.education.handoff', $lead, [
                'visa_type' => $lead->inz_visa_type ?? '',
            ]));
        }
    }
}
