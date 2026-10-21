<?php

namespace App\Http\Controllers\Portal\Accommodation;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class TenantDocumentController extends Controller
{
    public function store(Request $request, Tenant $tenant)
    {
        TenantDocument::authorizeStaff($request->user());
        $data = $request->validate([
            'category' => ['required', Rule::in(TenantDocument::CATEGORIES)],
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240',
        ]);
        $file = $request->file('file');
        $path = $file->store("tenants/{$tenant->id}", 'tenant_private');
        try {
            $tenant->documents()->create([
                'category' => $data['category'],
                'name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                'path' => $path,
                'size' => $file->getSize(),
                'uploaded_by' => $request->user()->id,
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('tenant_private')->delete($path);
            throw $exception;
        }

        return redirect()->route('portal.accommodation.tenants.show', $tenant)
            ->with('success', 'Document uploaded.');
    }

    public function download(Request $request, Tenant $tenant, string $document)
    {
        TenantDocument::authorizeStaff($request->user());
        $document = $tenant->documents()->findOrFail($document);
        abort_unless(Storage::disk('tenant_private')->exists($document->path), 404);

        return Storage::disk('tenant_private')->download($document->path, $document->name, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
