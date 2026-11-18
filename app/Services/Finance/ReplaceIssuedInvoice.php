<?php

namespace App\Services\Finance;

use App\Models\ActivityLog;
use App\Models\CaseFinancial;
use App\Models\FinanceReceivable;
use App\Models\InvoiceReplacement;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use App\Services\PaymentProofService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

class ReplaceIssuedInvoice
{
    public function issue(User $actor, FinanceReceivable $receivable, string $expectedNumber, string $number, string $reason): void
    {
        abort_unless(app(PaymentProofService::class)->canReview($actor), 403);
        $disk = Storage::disk('local');
        $written = null;

        try {
            DB::transaction(function () use ($actor, $receivable, $expectedNumber, $number, $reason, $disk, &$written) {
                if ($receivable->lead_id) {
                    Lead::whereKey($receivable->lead_id)->lockForUpdate()->firstOrFail();
                }
                $row = FinanceReceivable::whereKey($receivable->id)->lockForUpdate()->firstOrFail();
                $invoice = LeadDocument::whereKey($row->invoice_document_id)->lockForUpdate()->first();
                if (! $invoice || $invoice->lead_id !== $row->lead_id || ! PaymentProofService::isInvoice($invoice)
                    || $invoice->source !== LeadDocument::SOURCE_GENERATED || $invoice->mime !== 'application/pdf'
                    || $invoice->status === LeadDocument::STATUS_REJECTED || ! in_array($row->status, ['sent', 'issued', 'paid'], true)) {
                    throw ValidationException::withMessages(['invoice_number' => 'Choose an active issued invoice with its original portal PDF attached.']);
                }

                $latest = $row->invoiceReplacements()->latest('id')->first();
                if ($row->invoice_no === $number && $latest?->previous_number === $expectedNumber
                    && $latest->replacement_number === $number && $latest->reason === $reason) {
                    return;
                }
                if ($row->invoice_no !== $expectedNumber || $invoice->invoice_number !== $expectedNumber) {
                    throw ValidationException::withMessages(['expected_number' => 'The invoice number changed. Reopen the invoice and review its history.']);
                }

                $reference = PortalInvoiceReceivables::reference($number);
                $used = fn ($references) => $references->contains(fn ($candidate) => PortalInvoiceReceivables::reference($candidate) === $reference);
                if ($used(FinanceReceivable::pluck('invoice_no')) || $used(LeadDocument::whereNotNull('invoice_number')->pluck('invoice_number'))
                    || $used(CaseFinancial::whereNotNull('invoice_no')->pluck('invoice_no'))
                    || InvoiceReplacement::where('previous_reference', $reference)->orWhere('replacement_reference', $reference)->exists()) {
                    throw ValidationException::withMessages(['invoice_number' => 'This number already belongs to an existing or historical invoice.']);
                }

                $previousBytes = $disk->get($invoice->file_path);
                $original = $row->invoiceReplacements()->oldest('id')->first();
                $originalBytes = $original ? $disk->get($original->previous_path) : $previousBytes;
                if (! $previousBytes || ! $originalBytes || ($original && hash('sha256', $originalBytes) !== $original->previous_hash)) {
                    throw ValidationException::withMessages(['invoice_number' => 'The original invoice file could not be verified.']);
                }

                $cover = Pdf::loadView('pdf.invoice-number-correction', [
                    'number' => $number,
                    'previous' => $expectedNumber,
                    'original' => $original?->previous_number ?? $expectedNumber,
                    'date' => now('Pacific/Auckland')->format('j M Y'),
                ])->setPaper('a4')->output();
                $pdf = new Fpdi;
                $pdf->SetAutoPageBreak(false);
                foreach ([$cover, $originalBytes] as $source) {
                    $count = $pdf->setSourceFile(StreamReader::createByString($source));
                    for ($page = 1; $page <= $count; $page++) {
                        $template = $pdf->importPage($page);
                        $size = $pdf->getTemplateSize($template);
                        $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                        $pdf->useTemplate($template);
                    }
                }
                $bytes = $pdf->Output('S');
                $written = "lead-documents/{$row->lead_id}/invoice-replacement-".Str::uuid().'.pdf';
                if (! $disk->put($written, $bytes)) {
                    throw new \RuntimeException('Replacement PDF could not be written.');
                }

                $history = $row->invoiceReplacements()->create([
                    'issued_by' => $actor->id,
                    'previous_number' => $expectedNumber,
                    'previous_reference' => PortalInvoiceReceivables::reference($expectedNumber),
                    'previous_path' => $invoice->file_path,
                    'previous_filename' => $invoice->original_name,
                    'previous_hash' => hash('sha256', $previousBytes),
                    'replacement_number' => $number,
                    'replacement_reference' => $reference,
                    'reason' => $reason,
                ]);

                $invoice->update(['invoice_number' => $number, 'original_name' => "Invoice {$number}.pdf",
                    'file_path' => $written, 'size' => strlen($bytes)]);
                $row->update(['invoice_no' => $number]);
                $case = CaseFinancial::where('lead_id', $row->lead_id)->lockForUpdate()->first();
                if ($case?->invoice_no && PortalInvoiceReceivables::reference($case->invoice_no) === $history->previous_reference) {
                    $case->forceFill(['invoice_no' => $number, 'updated_by' => $actor->id])->saveQuietly();
                }
                ActivityLog::record('finance.issued_invoice_replaced', [
                    'portal' => 'finance', 'entity_type' => FinanceReceivable::class, 'entity_id' => $row->id,
                    'description' => 'Issued an invoice number replacement',
                    'properties' => ['invoice_document_id' => $invoice->id, 'invoice_replacement_id' => $history->id,
                        'from' => $expectedNumber, 'to' => $number, 'reason' => $reason],
                ]);
            });
        } catch (\Throwable $error) {
            if ($written) {
                $disk->delete($written);
            }
            if ($error instanceof ValidationException || $error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                throw $error;
            }
            report($error);
            throw ValidationException::withMessages(['invoice_number' => 'The replacement could not be issued. The existing invoice is still in place.']);
        }
    }
}
