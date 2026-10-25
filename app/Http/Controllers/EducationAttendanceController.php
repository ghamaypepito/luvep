<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\EducationRsvpFolder;
use App\Models\EducationRsvpSavedList;
use App\Services\EducationAttendance;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EducationAttendanceController extends Controller
{
    public function folder(Request $request, EducationAttendance $attendance)
    {
        $request->merge(['name' => is_string($request->input('name')) ? trim($request->input('name')) : $request->input('name')]);
        $d = $request->validate(['name' => 'required|string|max:80|unique:education_rsvp_folders,name', 'filters' => 'sometimes|array', 'list_id' => 'nullable|integer|exists:education_rsvp_saved_lists,id']);
        $folderFilters = $attendance->filters($d['filters'] ?? []);
        try {
            $folder = EducationRsvpFolder::create(['name' => $d['name'], 'created_by' => $request->user()->id]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages(['name' => 'A folder with this name already exists.']);
        }
        ActivityLog::record('education.rsvp.folder_created', ['portal' => 'education', 'properties' => ['folder_id' => $folder->id]]);

        return $request->expectsJson() ? response()->json(['id' => $folder->id], 201) : redirect(route('education.rsvps.manage', $folderFilters + ['_filtered' => 1] + (isset($d['list_id']) ? ['list_id' => $d['list_id']] : [])).'#attendance')->with('status', 'Folder created.')->with('attendance_library', true);
    }

    public function save(Request $request, EducationAttendance $attendance)
    {
        $request->merge(['name' => is_string($request->input('name')) ? trim($request->input('name')) : $request->input('name')]);
        $d = $request->validate(['save_mode' => ['required', Rule::in(['new', 'update'])], 'list_id' => ['required_if:save_mode,update', 'nullable', 'integer', 'exists:education_rsvp_saved_lists,id'], 'folder_id' => 'required|integer|exists:education_rsvp_folders,id', 'name' => ['required', 'string', 'max:100'], 'filters' => 'present|array']);
        $request->validate(['name' => [Rule::unique('education_rsvp_saved_lists')->where('folder_id', $d['folder_id'])->ignore($d['save_mode'] === 'update' ? $d['list_id'] : null)]]);
        $filters = $attendance->filters($d['filters']);
        $list = $d['save_mode'] === 'update' ? EducationRsvpSavedList::findOrFail($d['list_id']) : new EducationRsvpSavedList(['created_by' => $request->user()->id]);
        try {
            $list->fill(['folder_id' => $d['folder_id'], 'name' => $d['name'], 'filters' => $filters])->save();
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages(['name' => 'A list with this name already exists in the selected folder.']);
        }
        ActivityLog::record('education.rsvp.list_saved', ['portal' => 'education', 'properties' => ['list_id' => $list->id, 'folder_id' => $list->folder_id, 'filter_keys' => array_keys($filters)]]);

        return $request->expectsJson() ? response()->json(['id' => $list->id], $d['save_mode'] === 'new' ? 201 : 200) : redirect(route('education.rsvps.manage', ['list_id' => $list->id]).'#attendance')->with('status', 'Saved list updated. New matching RSVPs will appear automatically.')->with('attendance_library', true);
    }

    public function export(Request $request, EducationAttendance $attendance)
    {
        $d = $request->validate(['format' => ['required', Rule::in(['csv', 'pdf'])]]);
        $context = $attendance->context($request);
        $filters = $context['attendanceFilters'];
        $query = $attendance->query($filters);
        $count = (clone $query)->count();
        if ($d['format'] === 'pdf' && $count > 500) {
            throw \Illuminate\Validation\ValidationException::withMessages(['export' => 'PDF supports up to 500 attendees. Narrow your filters or export CSV for the complete list.']);
        }
        $generated = now()->timezone('Asia/Manila');
        $filename = 'ep-education-attendance-'.$generated->format('Ymd-His').'.'.$d['format'];
        ActivityLog::record('education.rsvp.attendance_exported', ['portal' => 'education', 'properties' => ['format' => $d['format'], 'row_count' => $count, 'filter_keys' => array_keys($filters)]]);
        $headers = ['Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff'];
        if ($d['format'] === 'pdf') {
            $response = Pdf::loadView('education.attendance-pdf', ['rows' => $query->latest('id')->get(), 'filters' => $filters, 'generated' => $generated])->setPaper('a4', 'landscape')->setOption('isRemoteEnabled', false)->download($filename);
            foreach ($headers as $key => $value) {
                $response->headers->set($key, $value);
            }

            return $response;
        }

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Name', 'Email', 'Contact number', 'School', 'Event', 'Event schedule', 'Time zone', 'Attendance', 'Email verified', 'RSVP received (Asia/Manila)', 'Applicant matched', 'Event reminders', 'Future invites'], ',', '"', '');
            foreach ($query->lazyByIdDesc(500) as $row) {
                $values = [$row->full_name, $row->email, $row->phone, $row->visit->school, $row->visit->activity, $row->visit->summary(), $row->visit->timezone, $row->attendance_status, $row->verified_at ? 'Yes' : 'No', $row->created_at->timezone('Asia/Manila')->format('Y-m-d h:i A'), $row->lead_id ? 'Yes' : 'No', $row->email_reminders ? 'Yes' : 'No', $row->future_invites ? 'Yes' : 'No'];
                fputcsv($out, array_map([EducationAttendance::class, 'csvCell'], $values), ',', '"', '');
            }fclose($out);
        }, $filename, $headers + ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
