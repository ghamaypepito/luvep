<?php

namespace App\Services\Immigration;

use App\Mail\ReviewedEngagementMail;
use App\Mail\TemplatedMessage;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\CommunicationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EngagementPackWorkflow
{
    public function __construct(private EngagementDocumentGenerator $generator, private InvoiceGenerator $invoices) {}

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['engagement' => $message]);
    }

    private function remember(string $kind, array $value, int $minutes = 30): string
    {
        $token = (string) Str::uuid();
        Cache::put("engagement:{$kind}:{$token}", array_merge($value, [
            'owner_id' => auth()->id(),
        ]), now()->addMinutes($minutes));

        return $token;
    }

    public function recalled(Lead $lead, string $kind, string $token): array
    {
        $data = Cache::get("engagement:{$kind}:{$token}");
        abort_unless($data && $data['owner_id'] === auth()->id() && $data['lead_id'] === $lead->id, 404);

        return $data;
    }

    public function invoiceOptions(Lead $lead, string $type, array $settings): array
    {
        if ($type === 'initial_consultation' || $type === 'oia') {
            $fee = $type === 'oia' ? 500 : 250;
            $items = [[
                'description' => $type === 'oia'
                    ? 'Official Information Act / Privacy Act request service - payable in advance'
                    : 'Initial visa consultation - non-refundable booking fee, credited toward a subsequent visa processing engagement',
                'quantity' => 1, 'unit_price' => $fee,
            ]];
            if ($type === 'oia') {
                $items[] = ['description' => 'GST (15%)', 'quantity' => 1, 'unit_price' => 75];
            }

            return ['items' => $items, 'currency' => $type === 'oia' ? 'NZD' : 'USD', 'include_disbursement' => false];
        }

        $options = array_intersect_key($settings, array_flip(['fee_tier', 'fee_location', 'include_gst']));
        if (isset($settings['professional_fee'])) {
            $options['service_fee'] = (float) $settings['professional_fee'] * (! empty($settings['include_gst']) ? 1.15 : 1);
        }
        $planService = app(InstallmentPlanService::class);
        $plan = $planService->summary($planService->activeFor($lead));
        if ($plan) {
            $options['discount_amount'] = (float) $plan['discount_amount'];
            $options['admin_fee'] = (float) $plan['admin_fee'];
        }

        return $options;
    }

    public function prepare(Lead $lead, array $settings): array
    {
        $type = $settings['engagement_type'];
        if ($type !== 'immigration_advice') {
            $settings['include_gst'] = $type === 'oia';
        }
        $signer = User::findOrFail($settings['signer_id']);
        if (! $signer->holdsCurrentLicence()) {
            $this->fail('Choose a signing adviser with a current IAA licence.');
        }
        if ($type === 'oia') {
            $missing = [];
            foreach (['dob' => 'date of birth', 'passport_number' => 'passport number', 'residence_address_line_1' => 'current address', 'email' => 'email', 'phone' => 'phone number'] as $key => $label) {
                if (! $lead->$key) {
                    $missing[] = $label;
                }
            }
            if ($missing) {
                throw ValidationException::withMessages(['client_details' => 'Update the client record before generating: '.implode(', ', $missing).'.']);
            }
        }
        if (! $lead->first_name || ! $lead->last_name) {
            throw ValidationException::withMessages(['client_details' => 'Add the client’s full name to their record before generating.']);
        }
        $allowed = EngagementDocumentGenerator::typesFor($type);
        $types = array_values(array_intersect($allowed, $settings['types']));
        if ($types !== array_values($settings['types'])) {
            // Reordering is harmless; a document outside the selected service is not.
            if (array_diff($settings['types'], $allowed)) {
                $this->fail('The document selection does not match this engagement type.');
            }
        }
        if (in_array($type, ['oia', 'initial_consultation'], true) && count($types) !== 2) {
            $this->fail('Select both the agreement and its invoice.');
        }
        $packId = $settings['pack_id'] ?? (string) Str::uuid();
        if (isset($settings['pack_id'])) {
            $existing = $this->packDocuments($lead, $packId);
            if (($existing->first()->notes['engagement_type'] ?? 'immigration_advice') !== $type) {
                $this->fail('Create a new engagement to use a different engagement type.');
            }
            if ($existing->contains(fn ($doc) => $doc->client_signed_at || ! empty($doc->notes['engagement_sent_at']))) {
                $this->fail('This pack contains a signed agreement. Create a new engagement instead of replacing it.');
            }
        }
        $files = [];
        $total = $type === 'oia' ? 575 : ($type === 'initial_consultation' ? 250 : $this->generator->feeTotals($lead, $settings)['grand_total']);
        $currency = $type === 'initial_consultation' ? 'USD' : 'NZD';
        foreach ($types as $key) {
            $snapshot = null;
            $number = null;
            if ($key === 'invoice') {
                $number = Cache::lock('engagement:invoice-number', 10)->block(5, function () {
                    $next = $this->invoices->nextInvoiceNumber();
                    $n = max((int) preg_replace('/\D/', '', $next), (int) Cache::get('engagement:reserved-invoice', 0) + 1);
                    Cache::put('engagement:reserved-invoice', $n, now()->addDay());

                    return 'INV-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
                });
                $options = array_merge($this->invoiceOptions($lead, $type, $settings), ['invoice_number' => $number]);
                $pdf = $this->invoices->pdfBinary($lead, $options);
                $invoiceTotal = $this->invoices->payload($lead, $options)['total'];
                $name = "Invoice-{$number}";
            } else {
                $snapshot = $this->generator->snapshot($lead, $key, $settings);
                $pdf = $this->generator->pdfBinary($lead, $key, $settings);
                $name = EngagementDocumentGenerator::DOCS[$key]['prefix'];
            }
            $safeName = preg_replace('/[^A-Za-z0-9]/', '', "{$lead->first_name}{$lead->last_name}") ?: 'Client';
            $files[] = [
                'type' => $key, 'name' => "{$name}-{$safeName}.pdf", 'bytes' => base64_encode($pdf),
                'size' => strlen($pdf), 'snapshot' => $snapshot, 'invoice_number' => $number,
                'invoice_total' => $key === 'invoice' ? $invoiceTotal : null,
            ];
        }
        $feeExcl = match ($type) {
            'oia' => 500, 'initial_consultation' => 250, default => $this->generator->feeTotals($lead, $settings)['professional_excl']
        };
        $data = compact('files', 'settings', 'total', 'currency', 'feeExcl') + ['pack_id' => $packId, 'lead_id' => $lead->id];
        $token = $this->remember('prepared', $data);

        return $this->publicPack($lead, $data, $token, 'prepared');
    }

    private function publicPack(Lead $lead, array $data, string $token, string $kind): array
    {
        return [
            'token' => $token, 'pack_id' => $data['pack_id'], 'total' => $data['total'] ?? null,
            'currency' => $data['currency'] ?? 'NZD',
            'documents' => array_map(fn ($file, $index) => [
                'name' => $file['name'], 'type' => $file['type'], 'size' => $file['size'],
                'preview_url' => "/admin/leads/{$lead->id}/engagement/preview/{$kind}/{$token}/{$index}",
            ], $data['files'], array_keys($data['files'])),
        ];
    }

    public function save(Lead $lead, string $token): array
    {
        return Cache::lock("engagement:case:{$lead->id}", 90)->block(5, function () use ($lead, $token) {
            $data = $this->recalled($lead, 'prepared', $token);
            $this->assertCurrentSigner($data['settings']['signer_id']);
            if (! empty($data['saved'])) {
                return $this->publicPack($lead, $data, $token, 'prepared');
            }
            DB::transaction(function () use ($lead, $data) {
                $packToken = Str::random(48);
                $old = LeadDocument::where('lead_id', $lead->id)->where('notes->engagement_pack_id', $data['pack_id'])
                    ->where(fn ($q) => $q->where('source_variant', 'like', 'engagement:%')->orWhere('source_variant', 'invoice'))->lockForUpdate()->get();
                if ($old->contains(fn ($d) => $d->client_signed_at || ! empty($d->notes['engagement_sent_at']))) {
                    $this->fail('A signed or emailed pack cannot be replaced. Create a new engagement.');
                }
                // Keep old private files for queued/historical references; replace only rows in this draft.
                foreach ($old as $doc) {
                    $doc->delete();
                }
                foreach ($data['files'] as $file) {
                    if ($file['invoice_number'] && LeadDocument::where('invoice_number', $file['invoice_number'])->exists()) {
                        $this->fail('That invoice number was issued while you were previewing. Generate the pack again.');
                    }
                    $path = "lead-documents/{$lead->id}/".Str::uuid().'-'.$file['name'];
                    Storage::disk('local')->put($path, base64_decode($file['bytes']));
                    LeadDocument::create([
                        'lead_id' => $lead->id, 'checklist_key' => $file['type'] === 'invoice' ? 'invoice' : 'engage.'.$file['type'],
                        'original_name' => $file['name'], 'file_path' => $path, 'mime' => 'application/pdf', 'size' => $file['size'],
                        'source' => LeadDocument::SOURCE_GENERATED, 'status' => LeadDocument::STATUS_SUBMITTED,
                        'source_variant' => $file['type'] === 'invoice' ? 'invoice' : 'engagement:'.$file['type'],
                        'engagement_signer_id' => $data['settings']['signer_id'], 'uploaded_by' => auth()->id(),
                        'invoice_number' => $file['invoice_number'], 'invoice_total' => $file['invoice_total'],
                        'notes' => [
                            'engagement_signing_token' => $packToken, 'engagement_pack_id' => $data['pack_id'], 'engagement_type' => $data['settings']['engagement_type'],
                            'engagement_settings' => $data['settings'], 'engagement_payload' => $file['snapshot'],
                            'engagement_total' => $data['total'], 'engagement_currency' => $data['currency'], 'engagement_fee_excl' => $data['feeExcl'],
                        ],
                    ]);
                }
            });
            $data['saved'] = true;
            Cache::put("engagement:prepared:{$token}", $data, now()->addMinutes(30));

            return $this->publicPack($lead, $data, $token, 'prepared');
        });
    }

    private function packDocuments(Lead $lead, string $packId)
    {
        $query = $lead->documents()->where(function ($q) {
            $q->where('source_variant', 'like', 'engagement:%')->orWhere('source_variant', 'invoice');
        });
        $packId === 'legacy' ? $query->whereNull('notes->engagement_pack_id') : $query->where('notes->engagement_pack_id', $packId);
        $docs = $query->orderBy('id')->get();
        if ($docs->isEmpty()) {
            $this->fail('Save the generated documents as a draft before reviewing the email.');
        }

        return $docs;
    }

    private function fingerprint(Lead $lead, $docs): string
    {
        return hash('sha256', json_encode([
            $lead->email,
            $docs->map(fn ($doc) => [$doc->id, $doc->original_name, $doc->notes, $doc->client_signed_at,
                Storage::disk('local')->exists($doc->file_path) ? hash('sha256', Storage::disk('local')->get($doc->file_path)) : null,
            ])->all(),
        ]));
    }

    public function review(Lead $lead, string $packId): array
    {
        return Cache::lock("engagement:case:{$lead->id}", 90)->block(5, fn () => $this->reviewLocked($lead, $packId));
    }

    private function reviewLocked(Lead $lead, string $packId): array
    {
        if (! filter_var($lead->email, FILTER_VALIDATE_EMAIL)) {
            $this->fail('Add a valid client email address before reviewing.');
        }
        $docs = $this->packDocuments($lead, $packId);
        $files = $docs->map(function ($doc) {
            if (! Storage::disk('local')->exists($doc->file_path)) {
                $this->fail('A document is missing. Regenerate and save the draft before emailing.');
            }

            return ['type' => $doc->source_variant === 'invoice' ? 'invoice' : substr($doc->source_variant, 11),
                'name' => $doc->original_name, 'size' => $doc->size, 'bytes' => base64_encode(Storage::disk('local')->get($doc->file_path))];
        })->all();
        $this->assertCurrentSigner($docs->first()->engagement_signer_id);
        $signToken = $docs->first()->notes['engagement_signing_token'] ?? $lead->ensureEngagementSigningToken();
        $link = rtrim(config('app.url'), '/').'/engagement/'.$signToken.($packId === 'legacy' ? '' : '?pack='.$packId);
        $invoice = $docs->firstWhere('source_variant', 'invoice');
        $context = ['engagement_url' => $link, 'invoice_number' => $invoice?->invoice_number ?? '',
            'invoice_total' => $invoice?->invoice_total !== null ? number_format((float) $invoice->invoice_total, 2) : '',
            'currency' => $invoice?->notes['engagement_currency'] ?? 'NZD'];
        $serviceType = $docs->first()->notes['engagement_type'] ?? 'immigration_advice';
        $templateKey = match ($serviceType) {
            'initial_consultation' => 'initial_consultation_agreement', 'oia' => 'oia_engagement', default => 'migration_agreement',
        };
        $template = MessageTemplate::active()->where('key', $templateKey)->orderByRaw("CASE WHEN department = '' THEN 0 ELSE 1 END")->first();
        $to = [$lead->email];
        $delivery = [];
        if ($template && in_array('email', $template->channels ?? [], true)) {
            $comm = app(CommunicationService::class);
            $subject = $comm->render($lead, (string) $template->email_subject, $context);
            $body = $comm->render($lead, (string) $template->email_body, $context, true);
            $mail = new TemplatedMessage($subject, $body, [], $template->banner_image, $template->footer_image,
                null, $template->from_email, $template->from_name, $template->cc, $template->bcc,
                $template->branding, filled($template->design_json), $template->reply_to_email, $template->reply_to_name);
            $env = $mail->envelope();
            $delivery = ['from' => $env->from, 'reply_to' => $env->replyTo, 'cc' => $env->cc, 'bcc' => $env->bcc];
            $to = array_values(array_unique(array_merge($to, $this->addresses($template->to_extra))));
            $html = $mail->render();
        } else {
            $subject = match ($serviceType) {
                'initial_consultation' => 'Your Initial Consultation Agreement and Invoice - ePathways Migration',
                'oia' => 'Your OIA Engagement Agreement and Invoice - ePathways Migration',
                default => 'Your engagement documents are ready - ePathways Migration',
            };
            $name = e($lead->first_name);
            $list = implode('', array_map(fn ($file) => '<li>'.e($file['name']).'</li>', $files));
            $body = '<p>Dear '.$name.',</p><p>Your engagement documents are attached for review.</p><ul>'.$list.'</ul><p><a href="'.e($link).'">Open and sign your agreement</a></p>';
            $html = (new TemplatedMessage($subject, $body, branding: 'immigration'))->render();
        }
        $data = compact('files', 'to', 'subject', 'html', 'delivery') + [
            'lead_id' => $lead->id, 'pack_id' => $packId, 'fingerprint' => $this->fingerprint($lead, $docs),
        ];
        $token = $this->remember('review', $data, 15);

        return array_merge($this->publicPack($lead, $data, $token, 'review'), [
            'to' => $to, 'subject' => $subject, 'html' => $html,
            'cc' => array_map(fn ($a) => $a->address, $delivery['cc'] ?? []),
            'bcc' => array_map(fn ($a) => $a->address, $delivery['bcc'] ?? []),
            'from' => isset($delivery['from']) ? $delivery['from']->address : config('mail.from.address'),
        ]);
    }

    private function assertCurrentSigner(?int $id): void
    {
        if (! $id || ! User::find($id)?->holdsCurrentLicence()) {
            $this->fail('The signing adviser’s licence is no longer current. Regenerate with a current adviser before emailing.');
        }
    }

    private function addresses(?string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;]+/', $value ?? '')), fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL)));
    }

    public function send(Lead $lead, string $token): void
    {
        Cache::lock("engagement:case:{$lead->id}", 90)->block(5, function () use ($lead, $token) {
            $review = $this->recalled($lead, 'review', $token);
            if (! empty($review['sent'])) {
                $this->fail('This reviewed email has already been sent.');
            }
            $docs = $this->packDocuments($lead->fresh(), $review['pack_id']);
            $this->assertCurrentSigner($docs->first()->engagement_signer_id);
            if (! hash_equals($review['fingerprint'], $this->fingerprint($lead->fresh(), $docs))) {
                $this->fail('The recipient or documents changed. Review the email and attachments again before sending.');
            }
            $log = MessageLog::create([
                'source' => 'compose', 'channel' => 'email', 'recipient_type' => 'lead', 'recipient_id' => $lead->id,
                'recipient_address' => implode(', ', $review['to']), 'subject' => $review['subject'], 'body' => $review['html'],
                'status' => MessageLog::STATUS_QUEUED, 'triggered_by_user_id' => auth()->id(),
            ]);
            try {
                Mail::to($review['to'])->send(new ReviewedEngagementMail($review['subject'], $review['html'], $review['files'], $review['delivery']));
            } catch (\Throwable $e) {
                $log->update(['status' => MessageLog::STATUS_FAILED, 'failed_at' => now(), 'error_message' => $e->getMessage()]);
                throw $e;
            }
            $review['sent'] = true;
            Cache::put("engagement:review:{$token}", $review, now()->addDay());
            $log->update(['status' => MessageLog::STATUS_SENT, 'sent_at' => now()]);
            foreach ($docs as $doc) {
                $notes = $doc->notes ?? [];
                $notes['engagement_sent_at'] = now()->toIso8601String();
                $doc->update(['notes' => $notes]);
            }
            $lead->forceFill(['engagement_sent_at' => now()])->save();
            if ($docs->contains(fn ($doc) => $doc->source_variant === 'engagement:written_agreement')) {
                $lead->advanceImmigrationStage('Agreement Sent', ['For Assessment', 'Endorsed', 'For Agreement & Invoice'], auth()->id(), notify: false);
            }
            $lead->recordStaffActivity('Reviewed and emailed the engagement documents');
        });
    }
}
