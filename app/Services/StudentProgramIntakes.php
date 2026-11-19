<?php

namespace App\Services;

use App\Models\LeadStudyPlan;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class StudentProgramIntakes
{
    private static function titles(?string $text): array
    {
        return array_values(array_filter(array_map('trim', explode(' · ', $text ?? '')), fn ($title) => $title !== ''));
    }

    /** Older forms and public registrations still write the shared course and intake columns. */
    public static function syncLegacyChanges(LeadStudyPlan $plan, bool $intakeProvided = false): void
    {
        $old = $plan->getOriginal('program_intakes');
        $usePrimary = $intakeProvided || $plan->isDirty('preferred_intake');
        if (! is_array($old) || $plan->isDirty('program_intakes') || (! $usePrimary && ! $plan->isDirty('preferred_course'))) {
            return;
        }

        $titles = self::titles($plan->preferred_course);
        $dates = array_column($old, 'intake', 'program');
        if ($usePrimary && $titles !== []) {
            $dates[$titles[0]] = $plan->preferred_intake;
        }
        $plan->program_intakes = array_map(fn ($title) => ['program' => $title, 'intake' => $dates[$title] ?? null], $titles);
        if (! $usePrimary) {
            $plan->preferred_intake = $plan->program_intakes[0]['intake'] ?? null;
        }
    }

    public static function apply(LeadStudyPlan $plan, array $data, bool $preserveBlankDates = false): void
    {
        if (! array_key_exists('program_intakes', $data)) {
            if (array_key_exists('intake', $data) && (! $preserveBlankDates || filled($data['intake']))) {
                self::syncLegacyChanges($plan, intakeProvided: true);
            }

            return;
        }
        $titles = self::titles($plan->preferred_course);
        $old = $plan->getOriginal('program_intakes');
        $rows = $data['program_intakes'];
        $names = array_column($rows, 'program');
        if (! array_is_list($rows) || count($rows) !== count($titles)
            || count(array_unique($names)) !== count($names) || count(array_unique($titles)) !== count($titles)
            || array_diff($names, $titles) !== [] || array_diff($titles, $names) !== []) {
            throw ValidationException::withMessages(['program_intakes' => 'Provide one intake entry for each selected program, without duplicates or unrelated programs.']);
        }

        // An existing non-date label remains valid only for the same original
        // program. Never guess a day or copy its date into a new program.
        $legacy = is_array($old) ? array_column($old, 'intake', 'program') : [];
        $oldTitles = self::titles($plan->getOriginal('preferred_course'));
        if ($old === null && $oldTitles !== []) {
            $legacy[$oldTitles[0]] = $plan->getOriginal('preferred_intake');
        }
        foreach ($rows as $index => $row) {
            $value = $row['intake'] ?? null;
            if ($value !== null && $value !== '' && ($legacy[$row['program']] ?? null) !== $value
                && Validator::make(['date' => $value], ['date' => 'date_format:Y-m-d'])->fails()) {
                throw ValidationException::withMessages(["program_intakes.{$index}.intake" => 'Choose a valid intake date for this program.']);
            }
        }
        $dates = array_column($rows, 'intake', 'program');
        $plan->program_intakes = array_map(function ($title) use ($dates, $legacy, $preserveBlankDates) {
            $date = $dates[$title] === '' ? null : $dates[$title];
            if ($preserveBlankDates && $date === null) {
                $date = $legacy[$title] ?? null;
            }

            return ['program' => $title, 'intake' => $date];
        }, $titles);
        if ($titles !== []) {
            $plan->preferred_intake = $plan->program_intakes[0]['intake'];
        } elseif ($oldTitles !== [] && ! array_key_exists('intake', $data)) {
            $plan->preferred_intake = null;
        }
    }
}
