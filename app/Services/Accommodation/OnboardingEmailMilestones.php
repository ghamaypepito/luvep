<?php

namespace App\Services\Accommodation;

use App\Models\AccommodationAgreement;
use App\Models\EoiSubmission;
use App\Models\MessageLog;
use App\Services\OnboardingTransitionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class OnboardingEmailMilestones
{
    public const TEMPLATE_BY_STATUS = [
        'shortlisted' => 'exalt_client_02',
        'viewing_email_sent' => 'exalt_client_02',
        'viewing_completed' => 'exalt_client_04',
        'post_viewing_followup' => 'exalt_client_04',
        'pre_tenancy_form_sent' => 'exalt_client_04',
        'agreement_signed' => 'exalt_client_07',
        'invoice_sent' => 'exalt_client_07',
        'payment_confirmed' => 'exalt_client_08',
        'moved_in' => 'exalt_client_08',
    ];

    public function prepare(int $sourceId, string $template, string $email, ?int $record): array
    {
        $source = EoiSubmission::findOrFail($sourceId);
        if ((self::TEMPLATE_BY_STATUS[$source->status] ?? null) !== $template
            || mb_strtolower(trim($source->email)) !== $email
            || $source->welcome_email_sent_at !== null) {
            throw ValidationException::withMessages(['template_id' => 'The applicant or stage has changed. Reopen the record before sending.']);
        }

        $metadata = ['source_id' => $sourceId, 'template' => $template, 'record' => $record];
        $matches = match ($template) {
            'exalt_client_02' => $record && (int) $source->property_id === $record,
            'exalt_client_04' => $record === $sourceId && ! $source->preTenancyFormCompleted(),
            'exalt_client_07' => $this->invoice($source, $record) !== null,
            'exalt_client_08' => $record && (int) $source->converted_to_tenant_id === $record && $source->payment_confirmed_at !== null,
            default => false,
        };

        if (! $matches) {
            throw ValidationException::withMessages(['action_id' => 'Select the property or document linked to this applicant.']);
        }

        return $metadata;
    }

    public function sent(MessageLog $log, OnboardingTransitionService $transitions): void
    {
        $metadata = $log->compose_details['onboarding'] ?? null;
        if ($log->source !== MessageLog::SOURCE_ACCOMMODATION_COMPOSE || ! $metadata) {
            return;
        }

        DB::transaction(function () use ($log, $metadata, $transitions) {
            $source = EoiSubmission::lockForUpdate()->find($metadata['source_id']);
            if (! $source || (self::TEMPLATE_BY_STATUS[$source->status] ?? null) !== $metadata['template']
                || mb_strtolower(trim($source->email)) !== $log->recipient_address) {
                return;
            }

            switch ($metadata['template']) {
                case 'exalt_client_02':
                    if ((int) $source->property_id === (int) $metadata['record'] && $source->status === 'shortlisted') {
                        $transitions->transition($source, 'viewing_email_sent');
                    }
                    break;
                case 'exalt_client_04':
                    if ($source->status === 'viewing_completed') {
                        $transitions->transition($source, 'post_viewing_followup');
                    }
                    if ($source->status === 'post_viewing_followup') {
                        $transitions->transition($source, 'pre_tenancy_form_sent');
                    }
                    break;
                case 'exalt_client_07':
                    if ($invoice = $this->invoice($source, $metadata['record'])) {
                        $values = ['move_in_invoice_id' => $metadata['record'], 'invoice_amount_nzd' => $invoice['total_cents'] / 100];
                        if ($source->status === 'agreement_signed') {
                            $transitions->transition($source, 'invoice_sent', $values);
                        } elseif (! $source->move_in_invoice_id) {
                            $source->update($values);
                        }
                    }
                    break;
                case 'exalt_client_08':
                    if ($source->payment_confirmed_at && (int) $source->converted_to_tenant_id === (int) $metadata['record']) {
                        $source->welcome_email_sent_at ??= $log->sent_at ?? now();
                        $source->save();
                    }
                    break;
            }
        }, 3);
    }

    private function invoice(EoiSubmission $source, ?int $id): ?array
    {
        if (! $id || ! $source->converted_to_tenant_id || ! Schema::hasTable('accommodation_rental_invoices')
            || ($source->move_in_invoice_id && (int) $source->move_in_invoice_id !== $id)) {
            return null;
        }

        $invoice = DB::table('accommodation_rental_invoices')->where('kind', 'move-in')
            ->where('tenant_id', $source->converted_to_tenant_id)->where('id', $id)->value('details');
        $details = $invoice ? json_decode($invoice, true, flags: JSON_THROW_ON_ERROR) : [];
        if (! isset($details['agreement_id'], $details['total_cents']) || $details['total_cents'] <= 0
            || ! AccommodationAgreement::where('status', AccommodationAgreement::STATUS_SIGNED)
                ->where('eoi_submission_id', $source->id)->whereKey($details['agreement_id'])->exists()) {
            return null;
        }

        return ['total_cents' => $details['total_cents']];
    }
}
