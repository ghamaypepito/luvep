<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\MessageLog;
use App\Models\Program;
use App\Models\School;
use App\Models\SchoolWorkspaceRecord;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SchoolWorkspaceData
{
    // Called only after the ordinary active Admin/Education-session boundary.
    public static function forSchool(Request $request, School $school): array
    {
        $id = $school->id;
        $records = SchoolWorkspaceRecord::where('school_id', $id)->with('files')->orderByDesc('id')->get();
        $programs = Program::where('school_id', $id)->orderBy('title')->get([
            'id', 'title', 'slug', 'level', 'duration_months', 'tuition_fee', 'tuition_fee_notes', 'tuition_fees',
            'insurance_fee', 'visa_processing_fee', 'price_text', 'english_requirements', 'entry_requirements', 'location', 'status', 'intake_months',
        ]);
        $studentFields = ['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'lead_id', 'school_id'];
        $students = Lead::inStudentsRegister()->orderBy('first_name')
            ->with('studyPlans:id,lead_id,preferred_course,program_schools,preferred_intake,program_intakes')
            ->get([...$studentFields, 'student_school', 'education_stage', 'education_notes']);
        $messages = MessageLog::where('source', MessageLog::SOURCE_SCHOOL_CORRESPONDENCE)
            ->where('recipient_type', 'school')->where('recipient_id', $id)->get(['id', 'status', 'sent_at'])->keyBy('id');

        return [
            'school' => $school->only(['id', 'name']),
            'records' => $records->map(fn ($record) => $record->only(['id', 'kind', 'program_id', 'lead_id', 'data', 'version', 'updated_at']) + [
                'files' => $record->files->map(fn ($file) => ['id' => $file->id, 'name' => $file->name])->all(),
                'delivery' => $messages->get($record->data['message_log_id'] ?? null)?->only(['status', 'sent_at']),
            ]),
            'programs' => $programs,
            'studentAssociations' => self::studentAssociations($school, $students, $programs, $records),
            'students' => $students->map(fn (Lead $student) => $student->only($studentFields)),
            'emailContacts' => self::emailContacts($school),
            'staff' => User::where('is_active', true)->whereIn('role', ['admin', 'super_admin', 'education'])->orderBy('name')->get(['id', 'name']),
            'statuses' => SchoolWorkspaceRecord::STATUSES,
            'today' => now('Pacific/Auckland')->toDateString(),
            'initialSection' => in_array($request->query('tab'), ['enrolment', 'communication', 'course', 'intake'], true) ? $request->query('tab') : 'enrolment',
        ];
    }

    public static function emailContacts(School $school): array
    {
        return collect($school->contacts ?? [])->filter(fn ($contact) => is_array($contact)
            && filter_var(trim((string) ($contact['email'] ?? '')), FILTER_VALIDATE_EMAIL))
            ->map(fn ($contact) => ['name' => $contact['name'] ?? null, 'email' => strtolower(trim($contact['email']))])
            ->unique('email')->values()->all();
    }

    private static function studentAssociations(School $school, $students, $programs, $records): array
    {
        $catalogue = $programs->groupBy(fn (Program $program) => self::normalize($program->title));
        $catalogueOwners = Program::whereIn(DB::raw('LOWER(TRIM(title))'), $catalogue->keys()->all())
            ->get(['title', 'school_id'])->groupBy(fn (Program $program) => self::normalize($program->title));
        $enrolments = $records->where('kind', 'enrolment')->groupBy('lead_id');
        $schoolName = self::normalize($school->name);
        $rows = [];

        foreach ($students as $student) {
            $plan = $student->studyPlans->first();
            $titles = self::labels($plan?->preferred_course);
            $dates = array_column($plan?->program_intakes ?? [], 'intake', 'program');
            $schools = array_map(self::normalize(...), self::labels($student->student_school));
            $explicitSchool = $student->school_id === $school->id || in_array($schoolName, $schools, true);
            $confirmed = $enrolments->get($student->id, collect());
            $found = false;

            foreach ($titles as $index => $title) {
                $assigned = self::normalize($plan->program_schools[$index] ?? '');
                $titleKey = self::normalize($title);
                $matches = $catalogue->get($titleKey, collect());
                $program = $matches->count() === 1 ? $matches->first() : null;
                $unambiguousCatalogue = $program && $catalogueOwners->get($titleKey, collect())->count() === 1;
                $singleSchool = $explicitSchool && count($schools) <= 1;
                $belongsToSchool = $assigned !== '' ? $assigned === $schoolName
                    : (($singleSchool && count($titles) === 1)
                        || ($unambiguousCatalogue && ($explicitSchool || ($schools === [] && ! $student->school_id))));
                if (! $belongsToSchool) {
                    continue;
                }
                $found = true;
                $intake = $plan->program_intakes === null ? ($index === 0 ? $plan->preferred_intake : null) : ($dates[$title] ?? null);
                if ($program && $confirmed->contains(fn ($record) => $record->program_id === $program->id
                    && (blank($intake) || self::normalize($record->data['intake'] ?? '') === self::normalize($intake) || ($record->data['starts_on'] ?? null) === $intake))) {
                    continue;
                }
                // Progress is stored for the student, so it can only verify an unambiguous programme.
                $status = count($titles) === 1 && count($schools) <= 1 ? match ($student->education_stage) {
                    'Started Course', 'Course Ongoing' => 'Currently Studying',
                    'Completed' => 'Completed',
                    default => null,
                } : null;
                $rows[] = ['key' => "{$student->id}-{$index}", 'lead_id' => $student->id, 'program_id' => $program?->id,
                    'programme' => $title, 'intake' => $intake, 'status' => $status,
                    'starts_on' => $status ? ($student->education_notes['actual_course_started_on'] ?? null) : null];
            }
            if (! $found && $explicitSchool && $confirmed->isEmpty()) {
                $rows[] = ['key' => "{$student->id}-school", 'lead_id' => $student->id, 'program_id' => null,
                    'programme' => null, 'intake' => null, 'status' => null, 'starts_on' => null];
            }
        }

        return $rows;
    }

    private static function labels(?string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(' · ', $value ?? '')), fn ($label) => $label !== ''));
    }

    private static function normalize(string $label): string
    {
        return mb_strtolower(trim($label));
    }
}
