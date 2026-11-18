<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\HrDocument;
use App\Models\HrDocumentVersion;
use App\Models\HrEmployee;
use App\Policies\HrDocumentPolicy;
use App\Services\Hr\DocumentService;
use App\Services\Hr\HrAccess;
use App\Services\Hr\HrContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DocumentController extends Controller
{
    public function index(Request $request)
    {
        $actor = $request->user();
        $context = app(HrContext::class);
        $preview = $context->isPreview($request);
        $employee = $preview ? $context->subject($request) : app(HrAccess::class)->employee($actor);
        $mine = $request->query('scope') === 'mine';
        $policy = app(HrDocumentPolicy::class);
        $classes = array_values(array_filter(HrDocumentPolicy::CLASSES, fn ($c) => $policy->manageClass($actor, $c)));
        $ownerDocuments = function ($query) use ($employee) {
            $query->where('hr_employee_id', $employee->id)->whereNull('archived_at')->whereHas('versions', function ($versions) use ($employee) {
                $versions->whereNotNull('released_at')->orWhere(fn ($q) => $q->where('created_by', $employee->user_id)
                    ->whereHas('document', fn ($d) => $d->where('classification', 'employee_shared')->where('submitted_by', $employee->user_id)));
            });
        };
        $query = HrDocument::with('employee.user');
        if ($mine && ! $employee) {
            $query->whereRaw('1 = 0');
        } elseif ($preview || $mine) {
            $query->where($ownerDocuments);
            if ($preview) {
                $query->whereIn('classification', $classes);
            }
        } else {
            $query->where(function ($q) use ($classes, $employee, $ownerDocuments) {
                $q->whereIn('classification', $classes);
                if ($employee) {
                    $q->orWhere($ownerDocuments);
                }
            });
        }
        $documents = $query->orderByDesc('id')->limit(200)->get()->map(function ($doc) use ($actor, $preview, $mine, $policy) {
            $manage = ! $preview && ! $mine && $policy->manage($actor, $doc);
            $versions = $doc->versions()->when(! $manage, fn ($q) => $q->where(fn ($q) => $q->whereNotNull('released_at')
                ->when($doc->classification === 'employee_shared' && $doc->submitted_by === $doc->employee->user_id,
                    fn ($q) => $q->orWhere('created_by', $doc->employee->user_id))))
                ->orderByDesc('version')->get()->map(fn ($v) => [...$v->only(['id', 'version', 'original_filename', 'mime', 'size', 'created_at', 'released_at']), 'owner_uploaded' => $doc->submitted_by !== null && $doc->submitted_by === $v->created_by]);

            return ['id' => $doc->id, 'employee_id' => $doc->hr_employee_id, 'employee_name' => $doc->employee->user?->name, 'title' => $doc->title, 'classification' => $doc->classification, 'submitted_by' => $doc->submitted_by, 'archived' => (bool) $doc->archived_at, 'can_manage' => $manage,
                'can_upload' => ! $preview && ($manage || ($policy->selfUpload($actor, $doc->employee) && $doc->submitted_by === $actor->id && $doc->classification === 'employee_shared' && ! $doc->archived_at)), 'versions' => $versions];
        });
        $employees = ! $preview && ! $mine && $classes ? HrEmployee::with('user')->where('status', '!=', 'archived')->get()->map(fn ($e) => ['id' => $e->id, 'name' => $e->user?->name]) : [];

        return Inertia::render('hr/Documents', ['scope' => $mine ? 'mine' : 'manage', 'documents' => $documents, 'employees' => $employees, 'classifications' => $preview || $mine ? [] : $classes,
            'selfUpload' => ! $preview && $employee !== null, 'selfEmployeeId' => ! $preview ? $employee?->id : null]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['employee_id' => 'required|integer|exists:hr_employees,id', 'file' => 'required|file']);
        $version = app(DocumentService::class)->upload($request->user(), HrEmployee::findOrFail($data['employee_id']), $request->file('file'), $request->all());

        return $request->expectsJson() ? response()->json(['version' => $version->only(['id', 'version'])], 201) : back()->with('success', 'Private document version uploaded.');
    }

    public function release(Request $request, HrDocumentVersion $version)
    {
        $data = $request->validate(['released' => 'required|boolean']);
        app(DocumentService::class)->release($request->user(), $version, (bool) $data['released']);

        return back()->with('success', 'Document access updated.');
    }

    public function download(Request $request, HrDocumentVersion $version)
    {
        $context = app(HrContext::class);
        $preview = $context->isPreview($request);
        $subject = $preview ? $context->subject($request) : $version->document->employee;

        return app(DocumentService::class)->download($request->user(), $subject, $version, $preview);
    }

    public function archive(Request $request, HrDocument $document)
    {
        app(DocumentService::class)->archive($request->user(), $document);

        return back()->with('success', 'Document archived.');
    }
}
