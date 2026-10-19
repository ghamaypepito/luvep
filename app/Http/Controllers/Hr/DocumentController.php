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
        $classes = array_values(array_filter(HrDocumentPolicy::CLASSES, fn ($c) => app(HrDocumentPolicy::class)->manageClass($actor, $c)));
        $query = HrDocument::with('employee.user');
        if ($preview) {
            $query->where('hr_employee_id', $employee->id)->whereNull('archived_at')->whereIn('classification', $classes)->whereHas('versions', fn ($q) => $q->whereNotNull('released_at'));
        } else {
            $query->where(function ($q) use ($classes, $employee) {
                $q->whereIn('classification', $classes);
                if ($employee) {
                    $q->orWhere(fn ($q) => $q->where('hr_employee_id', $employee->id)->whereNull('archived_at')->whereHas('versions', fn ($q) => $q->whereNotNull('released_at')));
                }
            });
        }
        $documents = $query->orderByDesc('id')->limit(200)->get()->map(function ($doc) use ($actor, $preview) {
            $manage = ! $preview && app(HrDocumentPolicy::class)->manage($actor, $doc);
            $versions = $doc->versions()->when(! $manage, fn ($q) => $q->whereNotNull('released_at'))->orderByDesc('version')->get()->map(fn ($v) => $v->only(['id', 'version', 'original_filename', 'mime', 'size', 'created_at', 'released_at']));

            return ['id' => $doc->id, 'employee_id' => $doc->hr_employee_id, 'employee_name' => $doc->employee->user?->name, 'title' => $doc->title, 'classification' => $doc->classification, 'archived' => (bool) $doc->archived_at, 'can_manage' => $manage, 'versions' => $versions];
        });
        $employees = ! $preview && $classes ? HrEmployee::with('user')->where('status', '!=', 'archived')->get()->map(fn ($e) => ['id' => $e->id, 'name' => $e->user?->name]) : [];

        return Inertia::render('hr/Documents', ['documents' => $documents, 'employees' => $employees, 'classifications' => $preview ? [] : $classes]);
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
        app(DocumentService::class)->archive($request->user(),$document);

        return back()->with('success','Document archived.');
    }
}
