<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\Program;
use App\Models\School;
use App\Models\SchoolWorkspaceFile;
use App\Models\SchoolWorkspaceRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SchoolWorkspaceController extends Controller
{
    public const STATUSES = SchoolWorkspaceRecord::STATUSES;

    private function authorizeStaff(Request $request): void
    {
        $user = $request->user();
        abort_unless($user?->is_active && ($user->isAdmin() || $user->role === 'education')
            && ! $request->attributes->has('user_view_actor'), 403);
        abort_unless(SchoolWorkspaceRecord::available(), 503, 'School management is being installed. Existing school information and Programs remain available. Please retry shortly.');
    }

    public function show(Request $request, $id)
    {
        $this->authorizeStaff($request);
        $school = School::findOrFail($id);

        return inertia('portal/education/SchoolWorkspace', \App\Services\SchoolWorkspaceData::forSchool($request, $school));
    }

    public function save(Request $request, $id, $kind, $recordId = null)
    {
        $this->authorizeStaff($request);
        School::findOrFail($id);
        abort_unless(in_array($kind, ['enrolment', 'communication', 'intake', 'course'], true), 404);
        if ($recordId) {
            SchoolWorkspaceRecord::where('school_id', $id)->where('kind', $kind)->findOrFail($recordId);
        }
        $rules = [
            'version' => [$recordId ? 'required' : 'nullable', 'integer', 'min:1'],
            'program_id' => [$kind === 'communication' ? 'prohibited' : 'required', 'nullable', 'integer', Rule::exists('programs', 'id')->where('school_id', $id)],
            'lead_id' => [$kind === 'enrolment' ? 'required' : 'prohibited', 'nullable', 'integer', Rule::exists('leads', 'id')->where(fn ($q) => $q->whereIn('id', Lead::inStudentsRegister()->select('id')))],
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|mimes:pdf,doc,docx,xls,xlsx,csv,txt,jpg,jpeg,png,eml,msg|max:10240',
        ];
        $fields = match ($kind) {
            'enrolment' => ['status' => ['required', Rule::in(self::STATUSES[$kind])], 'intake' => 'required|string|max:120',
                'starts_on' => 'nullable|date_format:Y-m-d', 'ends_on' => 'nullable|date_format:Y-m-d'],
            'communication' => ['status' => ['required', Rule::in(self::STATUSES[$kind])], 'communicated_on' => 'required|date_format:Y-m-d',
                'subject' => 'required|string|max:255', 'type' => 'required|in:Email,Meeting,Phone Call,Other', 'contact' => 'nullable|string|max:191',
                'staff_id' => ['required', 'integer', Rule::exists('users', 'id')->where('is_active', true)->whereIn('role', ['admin', 'super_admin', 'education'])],
                'summary' => 'required|string|max:10000', 'follow_up' => 'nullable|string|max:2000', 'follow_up_on' => 'nullable|date_format:Y-m-d'],
            'intake' => ['status' => ['required', Rule::in(self::STATUSES[$kind])], 'name' => 'required|string|max:191',
                'opens_on' => 'nullable|date_format:Y-m-d', 'closes_on' => 'nullable|date_format:Y-m-d',
                'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'nullable|date_format:Y-m-d', 'campus' => 'nullable|string|max:191'],
            'course' => ['application_fee' => 'nullable|numeric|min:0|max:99999999.99|decimal:0,2',
                'other_fees' => 'nullable|string|max:3000', 'currency' => 'required|string|regex:/^[A-Z]{3}$/',
                'status' => 'required|in:Active,Inactive,Archived'],
        };
        $validated = $request->validate($rules + $fields);
        foreach ([['starts_on', 'ends_on'], ['opens_on', 'closes_on']] as [$start, $end]) {
            if (filled($validated[$start] ?? null) && filled($validated[$end] ?? null) && $validated[$end] < $validated[$start]) {
                throw \Illuminate\Validation\ValidationException::withMessages([$end => 'The end date must be on or after the start date.']);
            }
        }
        $paths = [];
        try {
            foreach ($request->file('attachments', []) as $file) {
                $path = $file->store('school-workspace/'.$id, 'local');
                if (! $path) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['attachments' => 'The attachment could not be stored. Please try again.']);
                }
                $paths[] = ['name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 200),
                    'path' => $path];
            }
            DB::transaction(function () use ($request, $id, $kind, $recordId, $validated, $fields, $paths) {
                School::whereKey($id)->lockForUpdate()->firstOrFail();
                if (isset($validated['program_id'])) {
                    Program::where('school_id', $id)->whereKey($validated['program_id'])->lockForUpdate()->firstOrFail();
                }
                $record = $recordId ? SchoolWorkspaceRecord::where('school_id', $id)->where('kind', $kind)->lockForUpdate()->findOrFail($recordId)
                    : new SchoolWorkspaceRecord(['school_id' => $id, 'kind' => $kind, 'created_by' => $request->user()->id, 'version' => 0]);
                if ($recordId && $record->version !== (int) $validated['version']) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['record' => 'This record changed. Refresh before editing again.']);
                }
                // One school-course supplement; existing Programs remains authoritative for catalogue prices/details.
                if ($kind === 'course') {
                    if (SchoolWorkspaceRecord::where('school_id', $id)->where('kind', 'course')->where('program_id', $validated['program_id'])
                        ->when($recordId, fn ($q) => $q->where('id', '!=', $recordId))->exists()) {
                        throw \Illuminate\Validation\ValidationException::withMessages(['record' => 'This course already has school details. Edit that record.']);
                    }
                }
                if ($kind === 'enrolment' && SchoolWorkspaceRecord::where('school_id', $id)->where('kind', 'enrolment')
                    ->where('program_id', $validated['program_id'])->where('lead_id', $validated['lead_id'])->where('data->intake', $validated['intake'])
                    ->when($recordId, fn ($q) => $q->where('id', '!=', $recordId))->exists()) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['record' => 'This student already has an enrolment for this programme and intake. Edit the existing record.']);
                }
                $record->fill(['program_id' => $validated['program_id'] ?? null, 'lead_id' => $validated['lead_id'] ?? null,
                    'data' => array_intersect_key($validated, $fields), 'updated_by' => $request->user()->id, 'version' => $record->version + 1])->save();
                foreach ($paths as $path) {
                    $record->files()->create($path);
                }
                ActivityLog::record('school.workspace_saved', ['properties' => ['school_id' => (int) $id, 'record_id' => $record->id, 'kind' => $kind, 'version' => $record->version]]);
            });
        } catch (\Throwable $e) {
            foreach ($paths as $path) {
                Storage::disk('local')->delete($path['path']);
            }
            throw $e;
        }

        return back()->with('success', 'School record saved.');
    }

    public function download(Request $request, $id, $recordId, $fileId)
    {
        $this->authorizeStaff($request);
        School::findOrFail($id);
        $record = SchoolWorkspaceRecord::where('school_id', $id)->findOrFail($recordId);
        $file = SchoolWorkspaceFile::where('school_workspace_record_id', $record->id)->findOrFail($fileId);
        abort_unless(Storage::disk('local')->exists($file->path), 404);

        return Storage::disk('local')->download($file->path, $file->name, ['X-Content-Type-Options' => 'nosniff']);
    }
}
