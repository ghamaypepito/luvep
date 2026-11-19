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

    /** Apply only program intake metadata; preserve legacy callers and other plan fields. */
    public static function apply(LeadStudyPlan $plan, array $data): void
    {
        $titles = self::titles($plan->preferred_course);
        $old = $plan->getOriginal('program_intakes');
        $old = is_array($old) ? $old : null;
        if (! array_key_exists('program_intakes', $data)) {
            if ($old !== null && $titles !== []) {
                $rows = $old;
                $first = $titles[0];
                if (array_key_exists('intake', $data)) {
                    $rows = array_values(array_filter($rows, fn ($row) => ($row['program'] ?? null) !== $first));
                    array_unshift($rows, ['program' => $first, 'intake' => $data['intake'] ?: null]);
                    $plan->program_intakes = $rows;
                    $plan->preferred_intake = $data['intake'] ?: null;
                } elseif (array_key_exists('program_text', $data)) {
                    $plan->preferred_intake = collect($rows)->firstWhere('program', $first)['intake'] ?? null;
                }
            }

            return;
        }

        $rows = $data['program_intakes'];
        $names = array_column($rows, 'program');
        if (! array_is_list($rows) || count($rows) !== count($titles)
            || count(array_unique($names)) !== count($names) || count(array_unique($titles)) !== count($titles)
            || array_diff($names, $titles) !== [] || array_diff($titles, $names) !== []) {
            throw ValidationException::withMessages(['program_intakes' => 'Provide one intake entry for each selected program, without duplicates or unrelated programs.']);
        }

        // An existing non-date label remains valid only for the same original
        // program. Never guess a day or copy its date into a new program.
        $legacy = collect($old ?? [])->mapWithKeys(fn ($row) => [$row['program'] => $row['intake'] ?? null])->all();
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
        $plan->program_intakes = array_map(function ($title) use ($rows) {
            $row = collect($rows)->firstWhere('program', $title);

            return ['program' => $title, 'intake' => ($row['intake'] ?? null) ?: null];
        }, $titles);
        if ($titles !== []) {
            $plan->preferred_intake = $plan->program_intakes[0]['intake'];
        }
    }
}
