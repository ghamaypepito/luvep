<?php

namespace App\Services\Immigration;

use App\Models\Lead;
use App\Models\PotentialCase;
use App\Models\User;
use App\Services\EmailAutomationService;
use Illuminate\Support\Carbon;

/**
 * The Potential Cases onboarding pipeline (vetting before an official case):
 * two logged consultations + a "key matters" audio acknowledgment gate the
 * initial written agreement; a licensed adviser can override the gate with a
 * reason; passing promotes the lead into an official immigration case.
 *
 * Procedural onboarding/compliance tracking — not advice. Every state change is
 * attributed (who + when) for the audit trail.
 */
class PotentialCaseService
{
    /** Mark a lead as a potential case (idempotent — returns the existing row). */
    public function createFor(Lead $lead, ?User $by): PotentialCase
    {
        return PotentialCase::firstOrCreate(
            ['lead_id' => $lead->id],
            ['status' => PotentialCase::STATUS_ONBOARDING, 'created_by' => $by?->id],
        );
    }

    /**
     * Log consultation 1 or 2 with the attending staff and a note.
     *
     * @param  array<int,array{id?:int,name:string}>  $staff
     */
    public function logMeeting(PotentialCase $pc, int $no, Carbon $at, array $staff, ?string $note, User $logger): PotentialCase
    {
        abort_unless(in_array($no, [1, 2], true), 422, 'Meeting must be 1 or 2.');

        $meetings = $pc->meetings ?? [];
        $meetings[$no] = [
            'at' => $at->toIso8601String(),
            'note' => $note ?: null,
            'staff' => array_values($staff),
            'logged_by' => ['id' => $logger->id, 'name' => $logger->name],
            'logged_at' => Carbon::now()->toIso8601String(),
        ];
        $pc->meetings = $meetings;
        $pc->save();

        return $pc;
    }

    /** Staff records that the client acknowledged the standard key-matters audio. */
    public function acknowledgeAudio(PotentialCase $pc, User $by): PotentialCase
    {
        $pc->audio_ack_at = Carbon::now();
        $pc->audio_ack_by = $by->id;
        $pc->save();

        return $pc;
    }

    /** Mark the initial written agreement as sent to the client. */
    public function markAgreementSent(PotentialCase $pc): PotentialCase
    {
        $pc->agreement_sent_at ??= Carbon::now();
        $pc->save();

        return $pc;
    }

    /**
     * Sign the initial written agreement — GATED on the two meetings + audio
     * acknowledgment, unless a licensed adviser has overridden the gate.
     */
    public function sign(PotentialCase $pc): PotentialCase
    {
        abort_unless($pc->gatePassed(), 422, 'Both consultations and the key-matters acknowledgment are required before signing (or a licensed adviser override).');

        $pc->agreement_signed_at ??= Carbon::now();
        $pc->status = PotentialCase::STATUS_SIGNED;
        $pc->save();

        return $pc;
    }

    /**
     * Licensed-adviser override of the two-meeting gate, with a reason. Advice
     * is not involved, but the waiver of a compliance gate is licence-gated.
     */
    public function override(PotentialCase $pc, User $adviser, string $reason): PotentialCase
    {
        abort_unless($adviser->holdsCurrentLicence(), 403, 'Only a licensed immigration adviser can override the consultation gate.');

        $pc->override_by = $adviser->id;
        $pc->override_reason = $reason;
        $pc->override_at = Carbon::now();
        $pc->save();

        return $pc;
    }

    /**
     * Promote a signed potential case to an official immigration case. Records
     * who promoted it (a staff member, or the System when $by is null), stamps
     * the case at "For Assessment", and fires the conversion email automation.
     */
    public function promote(PotentialCase $pc, ?User $by): PotentialCase
    {
        abort_unless($pc->status === PotentialCase::STATUS_SIGNED, 422, 'The written agreement must be signed before promoting to a case.');

        $lead = $pc->lead;
        $lead->is_immigration_case = true;
        $lead->immigration_converted_at = now();
        $lead->immigration_converted_by = $by?->id;
        if (empty($lead->immigration_stage)) {
            $lead->immigration_stage = 'For Assessment';
            $lead->stage_updated_at = now();
            $lead->stage_updated_by = $by?->id;
            $lead->pushStageHistory('immigration', 'For Assessment', $lead->immigration_assignee);
        }
        $lead->save();

        $pc->promoted_by = $by?->id;
        $pc->promoted_by_system = $by === null;
        $pc->promoted_at = Carbon::now();
        $pc->status = PotentialCase::STATUS_PROMOTED;
        $pc->save();

        // Email automation — no-op unless an admin enabled a message for it.
        app(EmailAutomationService::class)->fire('immigration.case.converted', $lead, [
            'visa_type' => $lead->inz_visa_type ?? '',
        ]);

        return $pc;
    }

    /** Record that a potential case did not pass onboarding. */
    public function decline(PotentialCase $pc, User $by, string $reason): PotentialCase
    {
        $pc->declined_by = $by->id;
        $pc->decline_reason = $reason;
        $pc->declined_at = Carbon::now();
        $pc->status = PotentialCase::STATUS_DECLINED;
        $pc->save();

        return $pc;
    }

    /** Payload for the frontend detail panel. */
    public function detail(PotentialCase $pc): array
    {
        return [
            'id' => $pc->id,
            'lead_id' => $pc->lead_id,
            'status' => $pc->status,
            'meetings' => [
                1 => $pc->meeting(1),
                2 => $pc->meeting(2),
            ],
            'audio_ack_at' => optional($pc->audio_ack_at)->toIso8601String(),
            'audio_ack_by' => $pc->audio_ack_by ? optional(User::find($pc->audio_ack_by))->name : null,
            'agreement_sent_at' => optional($pc->agreement_sent_at)->toIso8601String(),
            'agreement_signed_at' => optional($pc->agreement_signed_at)->toIso8601String(),
            'gate_passed' => $pc->gatePassed(),
            'override' => $pc->isOverridden() ? [
                'by' => optional($pc->overrider)->name,
                'reason' => $pc->override_reason,
                'at' => optional($pc->override_at)->toIso8601String(),
            ] : null,
            'promotion' => $pc->promoted_at ? [
                'by' => $pc->promoted_by_system ? 'System' : optional($pc->promoter)->name,
                'at' => optional($pc->promoted_at)->toIso8601String(),
            ] : null,
            'decline' => $pc->declined_at ? [
                'reason' => $pc->decline_reason,
                'at' => optional($pc->declined_at)->toIso8601String(),
            ] : null,
        ];
    }
}
