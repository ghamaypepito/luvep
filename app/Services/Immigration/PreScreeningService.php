<?php

namespace App\Services\Immigration;

use App\Models\Booking;
use App\Models\Lead;
use App\Models\PreScreening;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Pre-screening vetting flow on a Visa Assessment: book the two consultations,
 * send + acknowledge the consent (+audio), then mark Eligible / Convert to case,
 * or Decline — with a "skip these steps" bypass. Procedural, not advice; every
 * state change is attributed for the audit trail.
 */
class PreScreeningService
{
    /** Get (or lazily create) the pre-screening hub for an assessment subject. */
    public function ensureFor(Model $subject, ?User $by = null): PreScreening
    {
        return PreScreening::firstOrCreate(
            ['subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey()],
            ['status' => PreScreening::STATUS_NEW, 'created_by' => $by?->id],
        );
    }

    /**
     * Bulk-ensure a hub exists for many subjects in a few queries (used when
     * building the Visa Assessment list, so every row has a token/link).
     *
     * @param  iterable<Model>  $subjects
     * @return array<string,PreScreening>  keyed "{morphClass}:{id}"
     */
    public function ensureMany(iterable $subjects, ?User $by = null): array
    {
        $keyed = [];
        foreach ($subjects as $s) {
            $keyed["{$s->getMorphClass()}:{$s->getKey()}"] = $s;
        }
        if (! $keyed) {
            return [];
        }

        $existing = PreScreening::query()
            ->where(function ($q) use ($keyed) {
                foreach ($keyed as $s) {
                    $q->orWhere(fn ($w) => $w->where('subject_type', $s->getMorphClass())->where('subject_id', $s->getKey()));
                }
            })
            ->get()
            ->keyBy(fn (PreScreening $p) => "{$p->subject_type}:{$p->subject_id}");

        foreach ($keyed as $key => $s) {
            if (! isset($existing[$key])) {
                $existing[$key] = PreScreening::create([
                    'subject_type' => $s->getMorphClass(),
                    'subject_id' => $s->getKey(),
                    'status' => PreScreening::STATUS_NEW,
                    'created_by' => $by?->id,
                ]);
            }
        }

        return $existing->all();
    }

    /**
     * The "working lead" that holds this pre-screening's client uploads before
     * conversion. Returns the linked lead if set; otherwise uses the subject
     * when it is already a Lead (free assessment / draft), or finds-or-creates a
     * lightweight non-case Lead from the intake's details. Stored on lead_id so
     * the same lead is reused on later uploads, and so conversion (which matches
     * on email + name) picks up the uploaded documents.
     */
    public function ensureWorkingLead(PreScreening $pre): Lead
    {
        if ($pre->lead_id && ($existing = Lead::find($pre->lead_id))) {
            return $existing;
        }

        $subject = $pre->subject;

        if ($subject instanceof Lead) {
            $pre->lead_id = $subject->id;
            $pre->save();

            return $subject;
        }

        $first = $subject->first_name ?? null;
        $last = $subject->last_name ?? $subject->family_name ?? null;
        $email = $subject->email ?? null;
        $wantFirst = strtolower(trim((string) $first));
        $wantLast = strtolower(trim((string) $last));

        // Reuse an existing non-case lead for the same person (email + full
        // name) rather than creating a duplicate; otherwise create a fresh one.
        $lead = null;
        if ($email && $wantFirst !== '' && $wantLast !== '') {
            $lead = Lead::where('email', $email)->get()
                ->first(fn ($l) => strtolower(trim((string) $l->first_name)) === $wantFirst
                    && strtolower(trim((string) $l->last_name)) === $wantLast);
        }

        if (! $lead) {
            $lead = Lead::create([
                'lead_id' => Lead::generateLeadId(),
                'first_name' => $first,
                'last_name' => $last,
                'email' => $email,
                'phone' => $subject->phone ?? null,
                'source' => 'pre-screening',
                'status' => 'New Leads',
            ]);
        }

        $pre->lead_id = $lead->id;
        $pre->save();

        return $lead;
    }

    public function setConsultBooking(PreScreening $pre, Booking $booking): PreScreening
    {
        $pre->consult_booking_id = $booking->id;
        $this->refreshStatus($pre);

        return $pre;
    }

    public function setConsentBooking(PreScreening $pre, Booking $booking): PreScreening
    {
        $pre->consent_booking_id = $booking->id;
        $this->refreshStatus($pre);

        return $pre;
    }

    public function markConsentSent(PreScreening $pre): PreScreening
    {
        $pre->consent_sent_at ??= Carbon::now();
        $this->refreshStatus($pre);

        return $pre;
    }

    /** The client acknowledged the consent + key-matters audio. */
    public function acknowledgeConsent(PreScreening $pre): PreScreening
    {
        $pre->consent_ack_at ??= Carbon::now();
        $this->refreshStatus($pre);

        return $pre;
    }

    /** "Skip these steps" — bypass the consultation/consent gate, logged. */
    public function skip(PreScreening $pre, User $by, string $reason): PreScreening
    {
        $pre->skip_at = Carbon::now();
        $pre->skip_by = $by->id;
        $pre->skip_reason = $reason;
        $this->refreshStatus($pre);

        return $pre;
    }

    /** Gate on converting: consent acknowledged, or the steps were skipped. */
    public function gatePassed(PreScreening $pre): bool
    {
        return $pre->isSkipped() || $pre->consentAcknowledged();
    }

    public function markEligible(PreScreening $pre, User $by): PreScreening
    {
        abort_unless($this->gatePassed($pre), 422, 'Consent must be acknowledged (or the steps skipped) before marking eligible.');

        $pre->eligible_at ??= Carbon::now();
        $pre->eligible_by = $by->id;
        $pre->status = PreScreening::STATUS_ELIGIBLE;
        $pre->save();

        return $pre;
    }

    /**
     * Record that this pre-screening was converted to an official case. The
     * actual case creation is done by the caller (reusing the existing
     * convert-to-case path); this stamps the outcome + who/when.
     */
    public function recordConversion(PreScreening $pre, ?int $leadId, ?User $by): PreScreening
    {
        $pre->converted_lead_id = $leadId;
        $pre->converted_by = $by?->id;
        $pre->converted_at = Carbon::now();
        $pre->status = PreScreening::STATUS_CONVERTED;
        $pre->save();

        return $pre;
    }

    public function decline(PreScreening $pre, User $by, string $reason): PreScreening
    {
        $pre->declined_by = $by->id;
        $pre->decline_reason = $reason;
        $pre->declined_at = Carbon::now();
        $pre->status = PreScreening::STATUS_DECLINED;
        $pre->save();

        return $pre;
    }

    /** Recompute + persist the pipeline status from the steps completed. */
    private function refreshStatus(PreScreening $pre): void
    {
        if (! $pre->isTerminal() && $pre->status !== PreScreening::STATUS_ELIGIBLE) {
            if ($pre->consent_booking_id || $pre->consent_sent_at || $pre->consent_ack_at) {
                $pre->status = PreScreening::STATUS_CONSENT;
            } elseif ($pre->consult_booking_id) {
                $pre->status = PreScreening::STATUS_CONSULTATION;
            } else {
                $pre->status = PreScreening::STATUS_NEW;
            }
        }
        $pre->save();
    }

    /** Compact state for a Visa Assessment row + its detail panel. */
    public function summary(PreScreening $pre): array
    {
        return [
            'token' => $pre->token,
            'link' => '/application/'.$pre->token,
            'status' => $pre->status,
            'consult_booked' => $pre->consult_booking_id !== null,
            'consent_booked' => $pre->consent_booking_id !== null,
            'consent_sent' => $pre->consent_sent_at !== null,
            'consent_ack' => $pre->consent_ack_at !== null,
            'skipped' => $pre->isSkipped(),
            'gate_passed' => $this->gatePassed($pre),
            'eligible_at' => optional($pre->eligible_at)->toIso8601String(),
            'converted_at' => optional($pre->converted_at)->toIso8601String(),
            'declined_at' => optional($pre->declined_at)->toIso8601String(),
            'decline_reason' => $pre->decline_reason,
        ];
    }
}
