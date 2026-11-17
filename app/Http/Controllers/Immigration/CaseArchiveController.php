<?php

namespace App\Http\Controllers\Immigration;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Lead;
use App\Services\Immigration\ArchivedCaseFiles;
use App\Support\CaseRecordAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

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

    public function download(Request $request, int $id, ArchivedCaseFiles $files)
    {
        $this->authorizeAdmin($request);
        [$path, $reference] = DB::transaction(function () use ($id, $files) {
            $case = Lead::onlyTrashed()->immigrationCase()->lockForUpdate()->findOrFail($id);

            return [$files->zip($files->snapshot($case)), Str::slug($case->lead_id ?? '') ?: 'case-'.$id];
        });

        return response()->download($path, $reference.'-archive.zip', [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ])->setPrivate()->deleteFileAfterSend(true);
    }

    public function remove(Request $request, int $id, ArchivedCaseFiles $files)
    {
        $this->authorizeAdmin($request);
        if ($request->input('confirmation') !== 'DELETE ACCOUNT') {
            throw ValidationException::withMessages(['confirmation' => 'Type DELETE ACCOUNT to permanently delete this archived case.']);
        }
        $storedFiles = DB::transaction(function () use ($id, $files) {
            $case = Lead::onlyTrashed()->immigrationCase()->lockForUpdate()->findOrFail($id);
            $snapshot = $files->snapshot($case);
            $records = $snapshot['records'];
            $documents = $records['lead_documents']->pluck('id');
            $payments = $records['case_finance_payments']->pluck('id');

            // Keep standalone ledger entries while removing links to case evidence being erased.
            DB::table('finance_receivables')->whereIn('invoice_document_id', $documents)->update(['invoice_document_id' => null]);
            DB::table('payment_proof_reviews')->whereIn('document_id', $documents)
                ->orWhereIn('invoice_document_id', $documents)->orWhereIn('finance_payment_id', $payments)->delete();
            DB::table('case_finance_payments')->whereIn('invoice_document_id', $documents)->update(['invoice_document_id' => null]);

            $folders = $records['media_folders']->pluck('id');
            $media = $records['media_files']->pluck('id');
            $sharedMedia = DB::table('lead_documents')->whereIn('media_file_id', $media)->where('lead_id', '!=', $id)->exists()
                || DB::table('tenant_documents')->whereIn('media_file_id', $media)->exists()
                || DB::table('accommodation_purchase_invoices')->whereIn('folder_id', $folders)->orWhereIn('invoice_file_id', $media)->exists();
            if ($sharedMedia) {
                DB::table('media_folders')->where('lead_id', $id)->update(['lead_id' => null]);
                DB::table('media_shares')->whereIn('folder_id', $folders)->update(['revoked_at' => now()]);
            }
            // MySQL can cascade folder removal before it reaches the document rows.
            DB::table('lead_documents')->whereIn('id', $documents)->update(['media_file_id' => null]);

            $conversations = DB::table('ep_conversations')->where('lead_id', $id);
            DB::table('ep_messages')->whereIn('conversation_id', (clone $conversations)->select('id'))->delete();
            $conversations->update(['lead_id' => null, 'title' => 'Deleted case conversation', 'archived_at' => now()]);

            DB::table('case_dependents')->where('linked_lead_id', $id)
                ->whereNull('first_name')->whereNull('family_name')
                ->update(['first_name' => $case->first_name, 'family_name' => $case->last_name]);
            DB::table('pre_screenings')->where('lead_id', $id)->update(['lead_id' => null]);
            DB::table('pre_screenings')->where('converted_lead_id', $id)->update(['converted_lead_id' => null]);
            DB::table('visa_applications')->where('previous_lead_id', $id)
                ->update(['previous_lead_id' => $records['visa_applications']->first()['previous_lead_id'] ?? null]);
            DB::table('visa_applications')->where('lead_id', $id)->delete();
            $plans = $records['installment_plans']->pluck('id');
            DB::table('installments')->whereIn('installment_plan_id', $plans)->delete();
            DB::table('installment_plans')->whereIn('id', $plans)->delete();

            abort_unless($case->forceDelete(), 409, 'The archived case could not be deleted.');
            ActivityLog::record('immigration.case_permanently_deleted', [
                'description' => 'Permanently deleted an archived Immigration case.',
                'entity_type' => Lead::class,
                'entity_id' => $id,
                'properties' => ['case_id' => $id, 'case_reference' => $case->lead_id],
            ]);

            return $snapshot['files'];
        });

        try {
            $files->deleteUnshared($storedFiles);
        } catch (Throwable $error) {
            report($error);

            return back()->withErrors(['confirmation' => 'The case was deleted, but file cleanup could not finish. Contact IT for storage cleanup.']);
        }

        return back()->with('success', 'Archived case permanently deleted.');
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless(CaseRecordAccess::canManage($request), 403);
    }
}
