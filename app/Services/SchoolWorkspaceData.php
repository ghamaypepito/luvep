<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Program;
use App\Models\SchoolWorkspaceRecord;
use App\Models\User;
use Illuminate\Http\Request;

class SchoolWorkspaceData
{
    // Called only after the ordinary active Admin/Education-session boundary.
    public static function forSchool(Request $request, $school): array
    {
        $id = $school->id;
        $records = SchoolWorkspaceRecord::where('school_id', $id)->with('files')->orderByDesc('id')->get();

        return [
            'school' => $school->only(['id', 'name']),
            'records' => $records->map(fn ($record) => $record->only(['id', 'kind', 'program_id', 'lead_id', 'data', 'version', 'updated_at']) + [
                'files' => $record->files->map(fn ($file) => ['id' => $file->id, 'name' => $file->name])->all(),
            ]),
            'programs' => Program::where('school_id', $id)->orderBy('title')->get([
                'id', 'title', 'slug', 'level', 'duration_months', 'tuition_fee', 'tuition_fee_notes', 'tuition_fees',
                'insurance_fee', 'visa_processing_fee', 'price_text', 'english_requirements', 'entry_requirements', 'location', 'status', 'intake_months',
            ]),
            // Student links are selected explicitly; planned choices are not enrolment evidence.
            'students' => Lead::inStudentsRegister()
                ->orderBy('first_name')->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'lead_id', 'school_id']),
            'staff' => User::where('is_active', true)->whereIn('role', ['admin', 'super_admin', 'education'])->orderBy('name')->get(['id', 'name']),
            'statuses' => SchoolWorkspaceRecord::STATUSES,
            'today' => now('Pacific/Auckland')->toDateString(),
            'initialSection' => in_array($request->query('tab'), ['enrolment', 'communication', 'course', 'intake'], true) ? $request->query('tab') : 'enrolment',
        ];
    }
}
