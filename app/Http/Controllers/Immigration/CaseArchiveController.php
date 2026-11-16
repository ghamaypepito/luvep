<?php

namespace App\Http\Controllers\Immigration;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Lead;
use App\Support\CaseRecordAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CaseArchiveController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeAdmin($request);
        $search = trim($request->validate(['search' => 'nullable|string|max:120'])['search'] ?? '');

        $cases = Lead::onlyTrashed()->immigrationCase()
            ->when($search !== '', function ($query) use ($search) {
                foreach (preg_split('/\s+/', $search) as $part) {
                    $query->where(fn ($match) => $match
                        ->whereLike('first_name', '%'.$part.'%')
                        ->orWhereLike('middle_name', '%'.$part.'%')
                        ->orWhereLike('last_name', '%'.$part.'%')
                        ->orWhereLike('lead_id', '%'.$part.'%'));
                }
            })
            ->latest('deleted_at')->orderByDesc('id')
            ->paginate(25, ['id', 'lead_id', 'first_name', 'middle_name', 'last_name', 'suffix', 'immigration_stage', 'deleted_at'])
            ->withQueryString();

        return inertia('portal/immigration/ArchivedCases', ['cases' => $cases, 'search' => $search]);
    }

    public function archive(Request $request, int $id)
    {
        $this->authorizeAdmin($request);
        DB::transaction(function () use ($id) {
            $case = Lead::immigrationCase()->lockForUpdate()->findOrFail($id);
            if ($case->is_student || $case->is_english_student || $case->is_accommodation_client || $case->portalUser()->exists()) {
                throw ValidationException::withMessages(['archive' => 'Review other portal access and pathways before archiving this client.']);
            }
            if ($case->tiedAsDependent()->whereHas('lead')->exists()) {
                throw ValidationException::withMessages(['archive' => 'This case is linked to an active family record. Review that link before archiving.']);
            }
            $case->delete();
        });

        return back()->with('success', 'Case archived.');
    }

    public function restore(Request $request, int $id)
    {
        $this->authorizeAdmin($request);
        DB::transaction(function () use ($id) {
            Lead::onlyTrashed()->immigrationCase()->lockForUpdate()->findOrFail($id)->restore();
        });

        return back()->with('success', 'Case restored to the active list.');
    }

    public function correctName(Request $request, int $id)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'first_name' => 'required|string|max:120',
            'middle_name' => 'present|nullable|string|max:120',
            'last_name' => 'present|nullable|string|max:120',
            'suffix' => 'present|nullable|string|max:20',
        ]);
        DB::transaction(function () use ($id, $data) {
            Lead::onlyTrashed()->immigrationCase()->lockForUpdate()->findOrFail($id)->update($data);
        });

        return back()->with('success', 'Case name corrected.');
    }

    public function remove(Request $request, int $id)
    {
        $this->authorizeAdmin($request);
        if ($request->input('confirmation') !== 'DELETE ACCOUNT') {
            throw ValidationException::withMessages(['confirmation' => 'Type DELETE ACCOUNT to permanently delete this archived case.']);
        }
        DB::transaction(function () use ($id) {
            $case = Lead::onlyTrashed()->immigrationCase()->lockForUpdate()->findOrFail($id);
            if ($this->hasLinkedRecords($case)) {
                throw ValidationException::withMessages(['confirmation' => 'This case has linked records or case history and must stay archived.']);
            }
            $reference = $case->lead_id;
            abort_unless($case->forceDelete(), 409, 'The archived case could not be deleted.');
            ActivityLog::record('immigration.case_permanently_deleted', [
                'description' => 'Permanently deleted an archived Immigration case.',
                'properties' => ['case_id' => $id, 'case_reference' => $reference],
            ]);
        });

        return back()->with('success', 'Archived case permanently deleted.');
    }

    private function hasLinkedRecords(Lead $case): bool
    {
        if ($case->is_student || $case->is_english_student || $case->is_accommodation_client
            || $case->passport_path || $case->gdrive_folder_id || $case->student_gdrive_link
            || $case->stage_history || $case->assessment_id) {
            return true;
        }
        // Check every declared foreign key before deleting. Cascades and SET NULL
        // must never silently remove case history or break a related record.
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            foreach (Schema::getForeignKeys($table) as $key) {
                if ($key['foreign_table'] === $case->getTable()
                    && DB::table($table)->where(array_combine($key['columns'], array_map(
                        fn ($column) => $case->{$column},
                        $key['foreign_columns'],
                    )))->exists()) {
                    return true;
                }
            }
        }

        // These legacy references do not have foreign keys to leads.
        return DB::table('visa_applications')->where('lead_id', $case->id)
            ->orWhere('root_lead_id', $case->id)->orWhere('previous_lead_id', $case->id)->exists()
            || DB::table('pre_screenings')->where('lead_id', $case->id)->orWhere('converted_lead_id', $case->id)->exists()
            || DB::table('installment_plans')->where('lead_id', $case->id)->exists()
            || DB::table('message_logs')->where('recipient_type', 'lead')->where('recipient_id', $case->id)->exists();
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless(CaseRecordAccess::canManage($request), 403);
    }
}
