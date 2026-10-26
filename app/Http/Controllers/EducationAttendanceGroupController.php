<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\EducationRsvp;
use App\Models\EducationRsvpFolder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EducationAttendanceGroupController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate(['folder_id' => 'required|integer|exists:education_rsvp_folders,id', 'rsvp_ids' => 'required|array|min:1|max:100', 'rsvp_ids.*' => 'required|integer|distinct|exists:education_rsvps,id']);
        [$folder,$added] = DB::transaction(function () use ($data, $request) {
            $folder = EducationRsvpFolder::whereKey($data['folder_id'])->lockForUpdate()->firstOrFail();
            $ids = EducationRsvp::whereIn('id', $data['rsvp_ids'])->orderBy('id')->lockForUpdate()->pluck('id');
            if ($ids->count() !== count($data['rsvp_ids'])) {
                throw ValidationException::withMessages(['rsvp_ids' => 'An attendee is no longer available. Refresh the list and try again.']);
            }
            $existing = DB::table('education_rsvp_folder_members')->where('folder_id', $folder->id)->whereIn('education_rsvp_id', $ids)->pluck('education_rsvp_id');
            $newIds = $ids->diff($existing);
            $now = now();
            $rows = $newIds->map(fn ($id) => ['folder_id' => $folder->id, 'education_rsvp_id' => $id, 'added_by' => $request->user()->id, 'created_at' => $now, 'updated_at' => $now])->values()->all();
            if ($rows) {
                DB::table('education_rsvp_folder_members')->insert($rows);
                ActivityLog::record('education.rsvp.group_members_added', ['portal' => 'education', 'properties' => ['folder_id' => $folder->id, 'rsvp_ids' => $newIds->values()->all(), 'added' => count($rows)]]);
            }

            return [$folder, count($rows)];
        });

        return $request->expectsJson() ? response()->json(['added' => $added]) : redirect(route('education.rsvps.manage', ['folder_id' => $folder->id, '_filtered' => 1]).'#attendance')->with('status', $added ? ($added.' '.($added === 1 ? 'attendee added' : 'attendees added').' to '.$folder->name.'.') : 'Selected attendees are already in this group.')->with('attendance_library', true);
    }

    public function destroy(Request $request, EducationRsvpFolder $folder, EducationRsvp $rsvp)
    {
        $removed = DB::transaction(function () use ($folder, $rsvp) {
            EducationRsvpFolder::whereKey($folder->id)->lockForUpdate()->firstOrFail();
            $removed = DB::table('education_rsvp_folder_members')->where('folder_id', $folder->id)->where('education_rsvp_id', $rsvp->id)->delete();
            if ($removed) {
                ActivityLog::record('education.rsvp.group_member_removed', ['portal' => 'education', 'properties' => ['folder_id' => $folder->id, 'rsvp_id' => $rsvp->id]]);
            }

            return (bool) $removed;
        });

        return $request->expectsJson() ? response()->json(['removed' => $removed]) : redirect(route('education.rsvps.manage', ['folder_id' => $folder->id, '_filtered' => 1]).'#attendance')->with('status', $removed ? 'Attendee removed from this group. Their attendance record is unchanged.' : 'This attendee is already outside the group.')->with('attendance_library', true);
    }
}
