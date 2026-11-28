<?php

namespace App\Services\Accommodation;

use App\Models\AccommodationMoveOut;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class MoveOutWorkflow
{
    public const ACTIONS = [
        'check_in' => ['check_in'],
        'renew' => ['check_in', 'decision', 'reminded'],
        'confirm_move' => ['check_in', 'decision', 'reminded'],
        'complete_renewal' => ['decision'],
        'remind' => ['decision'],
        'inspect' => ['decision', 'reminded'],
        'feedback' => ['inspected'],
        'resolve' => ['feedback'],
        'statement' => ['feedback'],
        'accept' => ['statement'],
        'refund' => ['statement'],
        'close_move' => ['refunded'],
    ];

    public function apply(AccommodationMoveOut $record, string $action, array $input, string $actor, array &$stored): void
    {
        $today = now('Pacific/Auckland')->toDateString();
        $has = fn (string $event) => $record->events()->where('action', $event)->exists();
        $require = fn (bool $ok, string $message) => $this->ensure($ok, $message);
        $require($record->tenant !== null, 'The original tenant record is unavailable.');
        $require(! $record->closed_at && in_array($record->stage, self::ACTIONS[$action], true), 'This action is unavailable at the current stage.');

        $details = array_intersect_key($input, array_flip(['notes', 'response', 'return_at', 'resolution', 'reference']));
        $subject = $body = null;

        switch ($action) {
            case 'check_in':
                $require(! $has('check_in'), 'The check-in email has already been sent.');
                $require($today >= $record->check_in_on->toDateString() && $today <= $record->agreement_ends_on->toDateString(), 'Send the check-in during the month before the agreement ends.');
                $require($record->property_id && $record->tenant->property_id === $record->property_id
                    && $record->tenant->contract_end?->toDateString() === $record->agreement_ends_on->toDateString()
                    && in_array($record->tenant->current_status, Tenant::ACTIVE_STATUSES, true), 'The tenant or agreement has changed. Review the current tenancy before sending.');
                $subject = 'Would you like to renew your Exalt agreement?';
                $body = "Your current agreement ends on {$record->agreement_ends_on->toDateString()}. Please reply to let us know whether you would like to renew for another 12 months or move out.";
                break;

            case 'renew':
            case 'confirm_move':
                $require($record->property_id && $record->tenant->property_id === $record->property_id
                    && in_array($record->tenant->current_status, Tenant::ACTIVE_STATUSES, true), 'The tenant or property has changed. Review the current tenancy first.');
                $require(($record->stage === 'check_in' && $has('check_in')) || filled($input['notes'] ?? null), 'Record the tenant’s decision or explain the change.');
                $details['previous_decision'] = $record->decision;
                $details['previous_move_out_on'] = $record->move_out_on?->toDateString();
                $record->stage = 'decision';
                if ($action === 'renew') {
                    $require($record->decision !== 'renewal', 'A renewal request has already been recorded.');
                    $record->decision = 'renewal';
                    $record->move_out_on = null;
                    $record->reminder_on = null;
                    $subject = 'Your Exalt tenancy renewal';
                    $body = 'Thank you for requesting a renewal. Please complete this tenancy form so we can prepare the renewal agreement: '.route('pre-tenancy.general')
                        ."\n\nPlease continue your regular payments. Your existing bond will carry over.";
                } else {
                    $require($record->decision !== 'move_out' || $record->move_out_on?->toDateString() !== $input['move_out_on'], 'This move-out date is already on record.');
                    $record->decision = 'move_out';
                    $record->move_out_on = $input['move_out_on'];
                    $record->reminder_on = \Carbon\CarbonImmutable::parse($input['move_out_on'], 'Pacific/Auckland')->subWeek()->toDateString();
                    $details['move_out_on'] = $input['move_out_on'];
                    $subject = 'Your Exalt move-out and key handover';
                    $body = "We have recorded your move-out date as {$input['move_out_on']}. Please remove belongings and rubbish, vacuum, mop and clean the applicable areas. Reply to arrange handover and return all keys and access devices.";
                }
                break;

            case 'complete_renewal':
                $tenant = Tenant::whereKey($record->tenant_id)->lockForUpdate()->firstOrFail();
                $require($record->decision === 'renewal' && $record->property_id && $tenant->property_id === $record->property_id
                    && in_array($tenant->current_status, Tenant::ACTIVE_STATUSES, true)
                    && $tenant->contract_end?->gt($record->agreement_ends_on), 'Use the existing Renew action to record the new term after the renewal agreement is fully signed.');
                $record->closed_at = now();
                $details['renewed_through'] = $tenant->contract_end->toDateString();
                break;

            case 'remind':
                $require($record->decision === 'move_out' && $record->move_out_on
                    && $today >= $record->reminder_on->toDateString()
                    && $record->property_id && $record->tenant->property_id === $record->property_id
                    && in_array($record->tenant->current_status, Tenant::ACTIVE_STATUSES, true)
                    && $today <= $record->move_out_on->toDateString(), 'Send the reminder during the week before the confirmed move-out.');
                $record->stage = 'reminded';
                $subject = 'Reminder of your upcoming Exalt move-out';
                $body = "Your confirmed move-out date is {$record->move_out_on->toDateString()}. Please remove belongings and rubbish, vacuum, mop, clean the applicable areas and return all keys and access devices. Please reply if you need to confirm the handover arrangements.";
                break;

            case 'inspect':
                $require($record->decision === 'move_out' && $record->tenant->current_status === 'vacated', 'Use the tenant’s Vacate action after they have fully moved out.');
                $require($record->stage === 'reminded' || filled($input['reference'] ?? null), 'Document why the one-week reminder was skipped.');
                $record->stage = 'inspected';
                $details['inspection_date'] = $today;
                break;

            case 'feedback':
                $record->stage = 'feedback';
                $subject = 'Your Exalt move-out inspection feedback';
                $body = $input['notes'];
                $details['work_needed'] = $input['work_needed'];
                if ($input['work_needed']) {
                    $details['proposed_charge_cents'] = $this->cents($input['proposed_charge'] ?? '0');
                    $body .= filled($input['return_at'] ?? null)
                        ? "\n\nProposed return time: {$input['return_at']}. Please reply to agree on this time."
                        : "\n\nExalt can arrange the work. Please reply to discuss the proposed charge of ".$this->nzd($details['proposed_charge_cents']).'.';
                }
                break;

            case 'resolve':
                $require(! $has('resolve') && $this->feedbackNeedsWork($record), 'There are no unresolved inspection items on this record.');
                break;

            case 'statement':
                $require(! $this->feedbackNeedsWork($record) || $has('resolve'), 'Record the tenant’s response and resolve the inspection items before sending the statement.');
                $bond = $this->cents($input['bond']);
                $deductions = [];
                foreach ($input['deductions'] ?? [] as $deduction) {
                    $deductions[] = ['item' => $deduction['item'], 'cents' => $this->cents($deduction['amount'])];
                }
                $total = array_sum(array_column($deductions, 'cents'));
                $require($total <= $bond, 'The deductions cannot exceed the original bond.');
                $details += ['bond_cents' => $bond, 'deductions' => $deductions, 'refund_cents' => $bond - $total];
                $items = array_map(fn ($item) => $item['item'].' — '.$this->nzd($item['cents']), $deductions);
                $record->stage = 'statement';
                $subject = 'Your Exalt bond refund statement';
                $body = 'Original bond: '.$this->nzd($bond)."\nProposed deductions:\n".($items ? implode("\n", $items) : 'None')
                    ."\nRefund amount: ".$this->nzd($details['refund_cents'])."\n\nPlease reply to review and confirm this statement.";
                break;

            case 'accept':
                $require(! $has('accept'), 'The tenant’s confirmation has already been recorded.');
                break;

            case 'refund':
                $require($has('accept'), 'Record the tenant’s confirmation of the statement first.');
                $amount = $record->events()->where('action', 'statement')->firstOrFail()->details['refund_cents'];
                $require($amount === 0 || ! empty($input['files']), 'Attach proof of the completed refund before recording it.');
                $details['refund_cents'] = $amount;
                $record->stage = 'refunded';
                break;

            case 'close_move':
                $refund = $record->events()->where('action', 'refund')->firstOrFail()->details;
                $record->closed_at = now();
                $subject = 'Your Exalt move-out is complete';
                $body = $refund['refund_cents'] > 0
                    ? 'Your bond refund of '.$this->nzd($refund['refund_cents']).' has been processed. Proof of the refund is attached.'
                    : 'Your confirmed bond statement shows no balance to refund.';
                $body .= "\n\nThank you for staying with Exalt.";
                $details['refund_cents'] = $refund['refund_cents'];
                break;
        }

        foreach ($input['files'] ?? [] as $file) {
            /** @var UploadedFile $file */
            $path = $file->store('move-out/'.$record->id, 'tenant_private');
            $stored[] = $path;
            $details['files'][] = ['path' => $path, 'name' => $file->getClientOriginalName()];
        }

        if ($subject !== null) {
            $tenant = $record->tenant;
            $require(filter_var((string) $tenant->email, FILTER_VALIDATE_EMAIL) !== false, 'Add a valid email to the tenant record before sending.');
            $property = $record->property?->address ?: ($record->property?->name ?? $record->property_label);
            $body = "Hi {$tenant->display_name},\n\nProperty: {$property}\n\n{$body}\n\nExalt Property Management";
            $proof = $refund['files'] ?? [];
            try {
                $sent = Mail::raw($body, function ($message) use ($tenant, $subject, $proof) {
                    $message->to($tenant->email)->subject($subject);
                    foreach ($proof as $file) {
                        $message->attach(Storage::disk('tenant_private')->path($file['path']), ['as' => $file['name']]);
                    }
                });
            } catch (Throwable $error) {
                report($error);
                throw ValidationException::withMessages(['action' => 'The mail connection failed. Check delivery before retrying.']);
            }
            $require($sent !== null, 'The mail service did not accept the message.');
            $details['email'] = ['to' => $tenant->email, 'subject' => $subject, 'body' => $body];
        }

        $record->save();
        $record->events()->create(['action' => $action, 'actor' => $actor, 'details' => $details]);
    }

    private function feedbackNeedsWork(AccommodationMoveOut $record): bool
    {
        return (bool) ($record->events()->where('action', 'feedback')->first()?->details['work_needed'] ?? false);
    }

    private function ensure(bool $ok, string $message): void
    {
        if (! $ok) {
            throw ValidationException::withMessages(['action' => $message]);
        }
    }

    private function cents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    private function nzd(int $cents): string
    {
        return 'NZ$'.number_format($cents / 100, 2);
    }
}
