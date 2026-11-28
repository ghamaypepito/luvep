<?php

namespace App\Http\Controllers\Portal\Accommodation;

use App\Http\Controllers\Controller;
use App\Models\AccommodationRentalInvoice;
use App\Models\Tenant;
use App\Models\TenantDocument;
use App\Services\Accommodation\RentalInvoices;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RentalInvoiceController extends Controller
{
    public function index(Request $request, string $kind, RentalInvoices $builder)
    {
        $this->authorizeKind($request, $kind);
        $data = $request->validate(['tenant' => 'nullable|integer']);
        $tenant = isset($data['tenant']) ? Tenant::withTrashed()->findOrFail($data['tenant']) : null;
        $history = AccommodationRentalInvoice::where('kind', $kind)
            ->when($tenant, fn ($query) => $query->where('tenant_id', $tenant->id))
            ->latest('id')->paginate(20)->withQueryString()
            ->through(fn ($invoice) => [
                'id' => $invoice->id, 'reference' => $invoice->reference, 'bill_to' => $invoice->details['bill_to'],
                'property' => $invoice->details['property'], 'issue_date' => $invoice->details['issue_date'],
                'due_date' => $invoice->details['due_date'], 'total_cents' => $invoice->details['total_cents'],
                'download' => route('portal.accommodation.rental-invoices.download', [$kind, $invoice->id], false),
            ]);
        $today = CarbonImmutable::today('Pacific/Auckland');
        $canIssue = $tenant && ! $tenant->trashed();

        return inertia('portal/accommodation/RentalInvoices', [
            'kind' => $kind, 'title' => AccommodationRentalInvoice::KINDS[$kind],
            'can_purchase' => $request->user()->isAdmin() || $request->user()->hasRole('accommodation'),
            'can_overdue' => $request->user()->canSeeModule('accommodation_finance'),
            'tenant' => $tenant ? ['id' => $tenant->id, 'name' => $tenant->display_name, 'property' => $tenant->property?->address ?: $tenant->property?->name] : null,
            'tenants' => Tenant::withTrashed()->get(['id', 'first_name', 'family_name', 'display_name_override'])
                ->sortBy('display_name')->values()->map(fn ($row) => ['id' => $row->id, 'name' => $row->display_name]),
            'agreements' => $canIssue && $kind === 'move-in' ? $builder->agreements($tenant) : [],
            'weeks' => $canIssue && $kind === 'overdue' ? $builder->weeks($tenant) : [],
            'bank' => RentalInvoices::BANK, 'today' => $today->toDateString(), 'due_date' => $today->addDays(2)->toDateString(),
            'history' => $history,
        ]);
    }

    public function preview(Request $request, string $kind, Tenant $tenant, RentalInvoices $builder)
    {
        $this->authorizeKind($request, $kind);
        $invoice = $builder->prepare($tenant, $kind, $this->invoiceData($request, $kind));

        return response($this->pdf($invoice, null), 200, [
            'Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store',
        ]);
    }

    public function store(Request $request, string $kind, Tenant $tenant, RentalInvoices $builder)
    {
        $this->authorizeKind($request, $kind);
        $data = $this->invoiceData($request, $kind);
        $fingerprint = hash('sha256', json_encode([$kind, $data], JSON_THROW_ON_ERROR));
        $path = null;

        try {
            DB::transaction(function () use ($request, $kind, $tenant, $builder, $data, $fingerprint, &$path) {
                $tenant = Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                $prior = AccommodationRentalInvoice::where('submission_key', $data['submission_key'])->first();
                if ($prior) {
                    if ($prior->tenant_id !== $tenant->id || ! hash_equals($prior->submission_hash, $fingerprint)) {
                        throw ValidationException::withMessages(['submission_key' => 'This request has already issued a different invoice. Reload before continuing.']);
                    }

                    return;
                }

                $invoice = $builder->prepare($tenant, $kind, $data);
                $prefix = $kind === 'move-in' ? 'EXALT-MI-' : 'EXALT-OD-';
                $reference = $prefix.strtoupper($data['submission_key']);
                $pdf = $this->pdf($invoice, $reference);
                $path = 'accommodation-rental-invoices/'.Str::uuid().'.pdf';
                if (! Storage::disk('local')->put($path, $pdf)) {
                    throw new \RuntimeException('Could not save the invoice PDF.');
                }
                AccommodationRentalInvoice::create([
                    'tenant_id' => $tenant->id, 'created_by' => $request->user()->id,
                    'kind' => $kind, 'reference' => $reference, 'submission_key' => $data['submission_key'],
                    'submission_hash' => $fingerprint, 'details' => $invoice, 'pdf_path' => $path,
                ]);
            });
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }

        return to_route('portal.accommodation.rental-invoices.index', ['kind' => $kind, 'tenant' => $tenant->id])
            ->with('success', 'Invoice issued.');
    }

    public function download(Request $request, string $kind, AccommodationRentalInvoice $invoice)
    {
        $this->authorizeKind($request, $kind);
        abort_unless($invoice->kind === $kind && Storage::disk('local')->exists($invoice->pdf_path), 404);

        return Storage::disk('local')->download($invoice->pdf_path, $invoice->reference.'.pdf');
    }

    private function invoiceData(Request $request, string $kind): array
    {
        $amount = 'required|integer|min:1|max:999999999';
        $data = $request->validate([
            'submission_key' => 'required|uuid',
            'issue_date' => 'required|date_format:Y-m-d',
            'due_date' => 'nullable|date_format:Y-m-d|after_or_equal:issue_date',
            ...($kind === 'move-in' ? [
                'agreement_id' => 'required|integer',
                'lines' => 'required|array|list|min:1|max:30',
                'lines.*' => 'required|array:description,amount_cents,group',
                'lines.*.description' => 'required|string|max:255',
                'lines.*.amount_cents' => $amount,
                'lines.*.group' => 'required|in:move-in,package',
            ] : [
                'payment_check_confirmed' => 'accepted',
                'weeks' => 'required|array|list|min:1|max:30',
                'weeks.*' => 'required|array:start,version,amount_cents',
                'weeks.*.start' => 'required|date_format:Y-m-d|distinct',
                'weeks.*.version' => 'required|string|size:64',
                'weeks.*.amount_cents' => $amount,
            ]),
        ]);
        if (array_sum(array_column($data['lines'] ?? $data['weeks'], 'amount_cents')) > 999999999) {
            throw ValidationException::withMessages(['total' => 'The invoice total must be below NZD 10 million.']);
        }

        return $data;
    }

    private function authorizeKind(Request $request, string $kind): void
    {
        abort_unless(isset(AccommodationRentalInvoice::KINDS[$kind]), 404);
        abort_unless($request->user()?->is_active && ! $request->attributes->has('user_view_actor'), 403);
        if ($kind === 'overdue') {
            abort_unless($request->user()->canSeeModule('accommodation_finance'), 403);
        } else {
            TenantDocument::authorizeStaff($request->user());
        }
    }

    private function pdf(array $invoice, ?string $reference): string
    {
        return Pdf::loadView('invoices.exalt-rental', compact('invoice', 'reference'))->setPaper('a4')->output();
    }
}
