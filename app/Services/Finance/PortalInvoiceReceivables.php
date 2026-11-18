<?php

namespace App\Services\Finance;

use App\Models\ActivityLog;
use App\Models\CaseFinancePayment;
use App\Models\CaseFinancial;
use App\Models\FinanceReceivable;
use App\Models\Lead;
use App\Models\LeadDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

class PortalInvoiceReceivables
{
    public static function snapshot(array $invoice, bool $draft): array
    {
        return [
            'issue_date' => Carbon::createFromFormat('!j M Y', $invoice['date'])->toDateString(),
            'due_date' => Carbon::createFromFormat('!j M Y', $invoice['due_date'])->toDateString(),
            'draft' => $draft,
        ];
    }

    private function isDraft(LeadDocument $invoice): bool
    {
        return $invoice->status !== LeadDocument::STATUS_STAFF_SHARED && empty($invoice->notes['engagement_sent_at'])
            && (!empty($invoice->notes['engagement_pack_id']) || ($invoice->notes['invoice_finance']['draft'] ?? false));
    }

    public static function reference(string $number): string
    {
        $number = mb_strtolower(trim($number));
        if (preg_match('/^(?:(?:invoice|inv)[\s_-]*)?0*([0-9]+)$/D', $number, $matches)) {
            return $matches[1];
        }

        return $number;
    }

    private function dates(LeadDocument $invoice): ?array
    {
        if ($snapshot = $invoice->notes['invoice_finance'] ?? null) {
            return $snapshot;
        }
        // An engagement prepared before the ledger integration still has its
        // authoritative dates in the saved PDF. Never infer them from upload time.
        $bytes = Storage::disk('local')->get($invoice->file_path);
        if (!$bytes) {
            return null;
        }
        $text = (new Parser)->parseContent($bytes)->getText();
        $date = '(\d{1,2} [A-Za-z]{3} \d{4})';
        if (!preg_match('/Invoice Date\s+'.$date.'/i', $text, $issue)
            || !preg_match('/Due Date:?\s+'.$date.'/i', $text, $due)) {
            return null;
        }

        return self::snapshot(['date' => $issue[1], 'due_date' => $due[1]], false);
    }

    /** Post an issued invoice without overwriting existing Finance entries. */
    public function post(LeadDocument $document): void
    {
        if ($document->source !== LeadDocument::SOURCE_GENERATED || $document->source_variant !== 'invoice'
            || !$document->invoice_number || $document->invoice_total <= 0 || $this->isDraft($document)) {
            return;
        }

        try {
            DB::transaction(function () use ($document) {
                $lead = Lead::whereKey($document->lead_id)->lockForUpdate()->firstOrFail();
                $invoice = LeadDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
                if ($this->isDraft($invoice) || $invoice->status === LeadDocument::STATUS_REJECTED
                    || ($invoice->status === LeadDocument::STATUS_APPROVED && !$invoice->invoicePayments()->exists())) {
                    return;
                }
                $ref = self::reference($invoice->invoice_number);
                $currency = strtoupper($invoice->notes['engagement_currency'] ?? '');
                $duplicates = LeadDocument::whereNotNull('invoice_number')->where('status', '!=', LeadDocument::STATUS_REJECTED)->pluck('invoice_number')
                    ->filter(fn ($number) => self::reference($number) === $ref)->count();
                $entries = FinanceReceivable::get(['id', 'invoice_document_id', 'lead_id', 'invoice_no', 'currency', 'amount', 'notes', 'status']);
                if ($duplicates !== 1 || $entries->contains('invoice_document_id', $invoice->id)) {
                    return;
                }
                $sameRef = $entries->filter(fn ($row) => self::reference($row->invoice_no) === $ref);
                $caseEntry = $entries->first(fn ($row) => $row->lead_id === $lead->id && $row->status !== 'void'
                    && $row->currency === $currency && str_contains((string) $row->notes, FinanceSyncService::AR_MARKER));
                $caseNumber = CaseFinancial::where('lead_id', $lead->id)->value('invoice_no');
                $placeholder = $caseEntry && preg_match('/^AR-[0-9]+$/D', $caseEntry->invoice_no);
                $adopt = $caseEntry && (self::reference($caseEntry->invoice_no) === $ref
                    || ($placeholder && (!$caseNumber || self::reference($caseNumber) === $ref)));
                $caseReceiptIds = $adopt ? $caseEntry->payments()->pluck('reference')->map(function ($reference) {
                    return preg_match('/\(CFP-(\d+)\)$/D', (string) $reference, $parts) ? (int) $parts[1] : null;
                })->filter() : [];
                if (($sameRef->isNotEmpty() && (!$adopt || $sameRef->count() !== 1 || !$sameRef->contains('id', $caseEntry->id)))
                    || ($adopt && ((float) $caseEntry->amount !== (float) $invoice->invoice_total
                        || CaseFinancePayment::whereIn('id', $caseReceiptIds)->where('invoice_document_id', '!=', $invoice->id)->exists()))) {
                    return;
                }
                $dates = $this->dates($invoice);
                if (!$dates || !preg_match('/^[A-Z]{3}$/D', $currency)) {
                    return;
                }

                $receivable = $adopt ? FinanceReceivable::whereKey($caseEntry->id)->lockForUpdate()->firstOrFail() : new FinanceReceivable;
                $receivable->fill([
                    'invoice_no' => $invoice->invoice_number,
                    'invoice_document_id' => $invoice->id,
                    'lead_id' => $lead->id,
                    'client_name' => trim("{$lead->first_name} {$lead->last_name}"),
                    'description' => 'Portal invoice',
                    'currency' => $currency,
                    'amount' => $invoice->invoice_total,
                    'issue_date' => $dates['issue_date'],
                    'due_date' => $dates['due_date'],
                    'status' => $receivable->status === 'paid' ? 'paid' : 'sent',
                    'created_by' => $receivable->created_by ?? auth()->id(),
                    'notes' => $adopt ? trim(str_replace(FinanceSyncService::AR_MARKER, '', (string) $receivable->notes)) : null,
                ])->save();
                app(FinanceSyncService::class)->syncForLead($lead);
                ActivityLog::record('finance.portal_invoice_posted', [
                    'portal' => 'finance', 'entity_type' => FinanceReceivable::class, 'entity_id' => $receivable->id,
                    'description' => 'Posted issued portal invoice to receivables',
                    'properties' => ['invoice_document_id' => $invoice->id],
                ]);
            });
        } catch (\Throwable $error) {
            // The document remains visible for review even when posting fails.
            Log::warning('Portal invoice could not post to receivables', ['invoice_document_id' => $document->id, 'error' => $error->getMessage()]);
        }
    }

    /** Read-only document rows for drafts and issued invoices awaiting review. */
    public function unposted(string $search, string $status): Collection
    {
        if (!in_array($status, ['all', 'draft', 'review'], true)) {
            return collect();
        }
        $entries = FinanceReceivable::get(['invoice_no', 'lead_id', 'invoice_document_id', 'client_name', 'currency', 'amount'])
            ->groupBy(fn ($row) => self::reference($row->invoice_no));
        return LeadDocument::where('source', LeadDocument::SOURCE_GENERATED)
            ->where('source_variant', 'invoice')->whereNotNull('invoice_number')
            ->where('status', '!=', LeadDocument::STATUS_REJECTED)
            ->whereDoesntHave('receivable')
            ->with('lead:id,first_name,last_name')
            ->whereHas('lead', fn ($q) => $q->immigrationCase())
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('invoice_number', 'like', "%{$search}%")
                ->orWhereHas('lead', function ($lead) use ($search) {
                    foreach (preg_split('/\s+/', $search) as $word) {
                        $lead->whereAny(['first_name', 'last_name'], 'like', "%{$word}%");
                    }
                })))
            ->get(['id', 'lead_id', 'invoice_number', 'invoice_total', 'created_at', 'notes', 'status'])
            ->map(function (LeadDocument $invoice) use ($entries, $status) {
                $draft = $this->isDraft($invoice);
                $state = $draft ? 'draft' : 'review';
                $name = trim("{$invoice->lead->first_name} {$invoice->lead->last_name}");
                $currency = $invoice->notes['engagement_currency'] ?? null;
                $existing = $entries->get(self::reference($invoice->invoice_number), collect());
                if (($status !== 'all' && $status !== $state) || $existing->contains(fn ($row) =>
                    ($row->lead_id === $invoice->lead_id || (!$row->lead_id && strcasecmp($row->client_name, $name) === 0))
                    && (!$currency || $row->currency === $currency) && (float) $row->amount === (float) $invoice->invoice_total
                )) {
                    return null;
                }
                return [
                    'id' => 'document-'.$invoice->id, 'invoice_no' => $invoice->invoice_number, 'client_name' => $name,
                    'description' => $draft ? 'Saved portal draft' : 'Portal invoice awaiting ledger review',
                    'currency' => $currency, 'amount' => $invoice->invoice_total === null ? null : (float) $invoice->invoice_total,
                    'balance' => null, 'issue_date' => $invoice->notes['invoice_finance']['issue_date'] ?? $invoice->created_at?->toDateString(),
                    'due_date' => $invoice->notes['invoice_finance']['due_date'] ?? null,
                    'payment_status' => $state, 'days_past_due' => 0, 'document_url' => '/portal/finance/invoice',
                ];
            })->filter()->values();
    }
}
