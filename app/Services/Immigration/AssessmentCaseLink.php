<?php

namespace App\Services\Immigration;

use App\Http\Controllers\Concerns\SyncsIntakeToCase;
use App\Models\Assessment;
use App\Models\Lead;
use App\Models\PreScreening;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Read-only resolution: a workflow label alone is never proof of a live case. */
class AssessmentCaseLink
{
    public const APPLICANT_CONFLICT = 'The linked applicant details conflict with this assessment. Support must verify the original record before proceeding.';

    private $cases;
    private $pre;
    private $byAssessment;
    private $byIdentity;

    public function __construct()
    {
        $this->cases = Lead::withTrashed()->immigrationCase()
            ->get(['id', 'assessment_id', 'first_name', 'last_name', 'email', 'dob', 'passport_number', 'deleted_at'])->keyBy('id');
        $this->byAssessment = $this->cases->whereNotNull('assessment_id')->groupBy('assessment_id');
        $this->byIdentity = $this->cases->groupBy(fn ($case) =>
            SyncsIntakeToCase::caseSyncIdentityKey($case->email, $case->dob) ?? '');
        $this->pre = PreScreening::with(['lead' => fn ($q) => $q->withTrashed()])
            ->where(fn ($q) => $q->whereNotNull('lead_id')->orWhereNotNull('converted_lead_id'))
            ->get()->keyBy(fn ($p) => $p->subject_type.':'.$p->subject_id);
    }

    public function resolve(Model $intake, ?Assessment $assessment): array
    {
        $pre = $this->pre->get($intake->getMorphClass().':'.$intake->getKey());
        $matches = $assessment ? $this->byAssessment->get($assessment->id, collect()) : collect();
        if ($matches->isEmpty() && $pre?->converted_lead_id && $this->cases->has($pre->converted_lead_id)) {
            $matches = collect([$this->cases->get($pre->converted_lead_id)]);
        }
        if ($matches->isEmpty() && $pre?->lead && $this->cases->has($pre->lead_id)) {
            $matches = collect([$this->cases->get($pre->lead_id)]);
        }
        if ($matches->isEmpty()) {
            $identity = SyncsIntakeToCase::caseSyncIdentityKey($intake->email, $intake->dob);
            if ($identity !== null) {
                $matches = $this->byIdentity->get($identity, collect());
            }
        }
        $active = $matches->filter(fn ($case) => ! $case->trashed());
        $case = $active->count() === 1 ? $active->first() : null;
        $conflictingLink = $case && $pre?->converted_lead_id && (int) $pre->converted_lead_id !== (int) $case->id;
        $applicantConflict = ($case && self::hasApplicantConflict($intake, $case))
            || ($pre?->lead && self::hasApplicantConflict($intake, $pre->lead));
        $blocked = $active->count() > 1 || $conflictingLink || $applicantConflict || (! $case && ($matches->isNotEmpty()
            || $pre?->converted_lead_id || ($pre?->lead_id && (! $pre->lead || $pre->lead->trashed()))));
        if ($blocked) {
            $case = null;
        }
        $claimed = $intake->status === 'Engaged' || $pre?->status === PreScreening::STATUS_CONVERTED;

        return [
            'case_id' => $case?->id,
            'case_url' => $case ? "/portal/immigration/cases/{$case->id}/profile?tab=documents" : null,
            'conversion_blocked' => $blocked,
            'conversion_issue' => $case ? null : ($blocked
                ? ($applicantConflict
                    ? self::APPLICANT_CONFLICT
                    : 'The linked case is deleted or ambiguous. Support must verify the original record before proceeding.')
                : ($claimed ? 'Marked converted, but no live case was found. Review the submission and retry conversion; do not create a duplicate client.' : null)),
        ];
    }

    public static function hasApplicantConflict(Model $intake, Lead $lead): bool
    {
        if ($intake->dob && $lead->dob && ! $intake->dob->isSameDay($lead->dob)) {
            return true;
        }

        $normalize = static fn ($name) => Str::lower(Str::squish((string) $name));
        $names = [
            [$normalize($intake->first_name), $normalize($lead->first_name)],
            [$normalize($intake->last_name ?? $intake->family_name), $normalize($lead->last_name)],
        ];
        foreach ($names as [$submitted, $stored]) {
            if ($submitted !== '' && $stored !== '' && $submitted !== $stored) {
                // Matching passport numbers and birth dates can support a recorded name change.
                $passport = Str::upper(trim((string) $intake->passport_number));

                return ! ($intake->dob && $lead->dob && $passport !== ''
                    && $passport === Str::upper(trim((string) $lead->passport_number)));
            }
        }

        return false;
    }
}
