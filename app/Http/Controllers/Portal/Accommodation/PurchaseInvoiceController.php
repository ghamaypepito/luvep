<?php

namespace App\Http\Controllers\Portal\Accommodation;

use App\Http\Controllers\Controller;
use App\Models\AccommodationPurchaseInvoice;
use App\Models\MediaFolder;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\TenantDocument;
use App\Services\MediaDrive\Library;
use App\Services\MediaDrive\Limits;
use App\Services\MediaDrive\Uploads;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseInvoiceController extends Controller
{
    public function index(Request $request)
    {
        TenantDocument::authorizeStaff($request->user());
        $filters = $request->validate(['tenant' => 'nullable|integer', 'property' => 'nullable|integer']);
        $tenant = isset($filters['tenant']) ? Tenant::withTrashed()->findOrFail($filters['tenant']) : null;
        $property = isset($filters['property']) ? Property::findOrFail($filters['property']) : null;

        return inertia('portal/accommodation/PurchaseInvoices', [
            'max_upload_mb' => min(10, Limits::maxUploadMb()),
            'tenant' => $tenant ? ['id' => $tenant->id, 'name' => $tenant->display_name, 'can_create' => ! $tenant->trashed()] : null,
            'property' => $property ? ['id' => $property->id, 'name' => $property->address ?: $property->name] : null,
            'invoices' => AccommodationPurchaseInvoice::query()
                ->when($tenant, fn ($query) => $query->where('tenant_id', $tenant->id))
                ->when($property, fn ($query) => $query->where('property_id', $property->id))
                ->with('folder.files:id,folder_id,original_name')->orderByDesc('issue_date')->orderByDesc('id')
                ->paginate(20)->withQueryString()->through(fn (AccommodationPurchaseInvoice $invoice) => [
                    'id' => $invoice->id,
                    'reference' => $invoice->reference,
                    'tenant_name' => $invoice->tenant_name,
                    'property_address' => $invoice->property_address,
                    'issue_date' => $invoice->issue_date->toDateString(),
                    'due_date' => $invoice->due_date?->toDateString(),
                    'total' => $invoice->total,
                    'folder_id' => $invoice->folder_id,
                    'files' => $invoice->folder->files->sortByDesc(fn ($file) => $file->id === $invoice->invoice_file_id)
                        ->values()->map(fn ($file) => ['id' => $file->id, 'name' => $file->original_name, 'is_invoice' => $file->id === $invoice->invoice_file_id]),
                ]),
        ]);
    }

    public function store(Request $request, Tenant $tenant, Library $library, Uploads $uploads)
    {
        $user = $request->user();
        TenantDocument::authorizeStaff($user);
        $amount = 'numeric|min:0|max:9999999.99|regex:/^\d+(?:\.\d{1,2})?$/';
        $data = $request->validate([
            'source' => 'required|in:upload,generate',
            'submission_key' => 'required|uuid',
            'reference' => 'required|string|max:64',
            'issue_date' => 'required|date_format:Y-m-d',
            'due_date' => 'nullable|date_format:Y-m-d|after_or_equal:issue_date',
            'total' => 'exclude_unless:source,upload|required|gt:0|'.$amount,
            'invoice' => 'exclude_unless:source,upload|required|file|mimes:pdf|max:10240',
            'lines' => 'exclude_unless:source,generate|required|array|list|min:1|max:30',
            'lines.*' => 'array:description,amount',
            'lines.*.description' => 'required|string|max:255',
            'lines.*.amount' => 'required|gt:0|'.$amount,
            'gst' => 'exclude_unless:source,generate|required|'.$amount,
            'gst_number' => 'exclude_unless:source,generate|nullable|string|max:32',
            'bill_to' => 'exclude_unless:source,generate|required|string|max:200',
            'payment_instructions' => 'exclude_unless:source,generate|required|string|max:1000',
            'receipts' => 'nullable|array|list|max:10',
            'receipts.*' => 'required|file|mimes:pdf,jpg,jpeg,png,webp|max:10240',
        ]);
        $receipts = $data['receipts'] ?? [];
        $uploadSize = ($data['invoice'] ?? null)?->getSize() ?? 0;
        $uploadSize += array_sum(array_map(fn (UploadedFile $file) => $file->getSize(), $receipts));
        $maxUploadMb = min(10, Limits::maxUploadMb());
        if ($uploadSize > $maxUploadMb * 1024 ** 2) {
            throw ValidationException::withMessages(['receipts' => "The invoice and receipts must be {$maxUploadMb} MB or less combined."]);
        }
        $reference = mb_strtoupper($data['reference']);
        if ($data['source'] === 'generate') {
            $toCents = static fn ($value) => (int) round((float) $value * 100);
            $total = array_sum(array_map(fn ($line) => $toCents($line['amount']), $data['lines']));
            if ($total > 999999999 || $toCents($data['gst']) > $total) {
                throw ValidationException::withMessages(['gst' => 'GST included must be within the invoice total, and the total must be below NZD 10 million.']);
            }
            if ($toCents($data['gst']) > 0 && empty($data['gst_number'])) {
                throw ValidationException::withMessages(['gst_number' => 'Enter Exalt’s GST number when the invoice includes GST charged by Exalt.']);
            }
            $data['total'] = number_format($total / 100, 2, '.', '');
        }
        $fingerprint = hash('sha256', json_encode([
            collect($data)->except(['invoice', 'receipts'])->all(),
            array_map(fn (UploadedFile $file) => [$file->getClientOriginalName(), hash_file('sha256', $file->getRealPath())], $receipts),
            isset($data['invoice']) ? [$data['invoice']->getClientOriginalName(), hash_file('sha256', $data['invoice']->getRealPath())] : null,
        ], JSON_THROW_ON_ERROR));
        $paths = [];
        $generatedFile = null;
        try {
            DB::transaction(function () use ($tenant, $user, $data, $reference, $fingerprint, $library, $uploads, &$paths, &$generatedFile) {
                $tenant = Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                $prior = AccommodationPurchaseInvoice::where('submission_key', $data['submission_key'])->first();
                if ($prior && $prior->tenant_id === $tenant->id && hash_equals($prior->submission_hash, $fingerprint)) {
                    return;
                }
                if ($prior) {
                    throw ValidationException::withMessages(['reference' => 'This submission has already saved an invoice. Reopen the form to add a different invoice.']);
                }
                if (AccommodationPurchaseInvoice::where('reference', $reference)->exists()) {
                    throw ValidationException::withMessages(['reference' => 'This invoice number is already recorded.']);
                }
                $property = $tenant->property()->firstOrFail();
                $propertyAddress = $property->address ?: $property->name;
                $pdf = $data['invoice'] ?? null;
                if ($data['source'] === 'generate') {
                    $generatedFile = tempnam(sys_get_temp_dir(), 'exalt-invoice-');
                    if ($generatedFile === false || file_put_contents($generatedFile, Pdf::loadView('invoices.exalt-purchase-invoice', [
                        'invoice' => $data, 'reference' => $reference, 'propertyAddress' => $propertyAddress,
                    ])->setPaper('a4')->output()) === false) {
                        throw new \RuntimeException('Could not generate the invoice PDF.');
                    }
                    $pdf = new UploadedFile($generatedFile, (Str::slug($reference) ?: 'purchase-invoice').'.pdf', 'application/pdf', null, true);
                }
                // A separate root keeps invoice files outside any existing tenancy folder share.
                $folder = MediaFolder::create([
                    'tenant_id' => $tenant->id,
                    'client_key' => MediaFolder::PURCHASE_INVOICE_PREFIX.$data['submission_key'],
                    'name' => 'Purchase invoice '.$reference,
                    'created_by' => $user->id,
                ]);
                $library->event($folder, 'folder_created', $user->id);
                $invoiceFile = null;
                foreach ([$pdf, ...($data['receipts'] ?? [])] as $index => $file) {
                    [$asset] = $uploads->store($folder, $file, (string) Str::uuid(), $user);
                    $paths[] = $asset->file_path;
                    if ($index === 0) {
                        $invoiceFile = $asset;
                    }
                }
                AccommodationPurchaseInvoice::create([
                    ...collect($data)->only(['submission_key', 'issue_date', 'due_date', 'total'])->all(),
                    'submission_hash' => $fingerprint,
                    'reference' => $reference,
                    'tenant_id' => $tenant->id,
                    'tenant_name' => $tenant->display_name,
                    'property_id' => $property->id,
                    'property_address' => $propertyAddress,
                    'folder_id' => $folder->id,
                    'invoice_file_id' => $invoiceFile->id,
                    'created_by' => $user->id,
                ]);
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($paths);
            if ($exception instanceof UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['reference' => 'This invoice number is already recorded.']);
            }
            throw $exception;
        } finally {
            if ($generatedFile) {
                unlink($generatedFile);
            }
        }

        return to_route('portal.accommodation.purchase-invoices', ['tenant' => $tenant->id])->with('success', 'Purchase invoice saved.');
    }

    public function download(Request $request, AccommodationPurchaseInvoice $invoice, int $file, Library $library)
    {
        TenantDocument::authorizeStaff($request->user());

        return $library->stream($invoice->folder->files()->findOrFail($file), $request->boolean('inline'));
    }
}
