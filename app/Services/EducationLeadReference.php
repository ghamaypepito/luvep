<?php

namespace App\Services;

use App\Models\EducationRsvp;
use App\Models\Lead;
use App\Models\LeadTag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EducationLeadReference
{
    public const TAG = 'reference-ph-events';

    /** Attach the reference without replacing tags or touching the contact. */
    public function attach(Lead $lead): bool
    {
        return DB::transaction(function () use ($lead) {
            // Serialize against public intake and a concurrent backfill for this contact.
            Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();
            $tag = LeadTag::whereRaw('LOWER(name) = ?', [self::TAG])->first();
            $now = now();
            if (! $tag) {
                // A different free-form tag may already own the plain slug.
                $created = DB::table('lead_tags')->insertOrIgnore([
                    'name' => self::TAG, 'slug' => self::TAG.'-'.Str::uuid(), 'color' => 'green',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                // Current read also sees a concurrent creator under REPEATABLE READ.
                $tag = LeadTag::whereRaw('LOWER(name) = ?', [self::TAG])->lockForUpdate()->firstOrFail();
                if ($created) {
                    $tag->recordActivity('created');
                }
            }

            // An older transaction snapshot must not turn a repeat attach into a duplicate-key error.
            return DB::table('lead_tag_lead')->insertOrIgnore([
                'lead_id' => $lead->id, 'lead_tag_id' => $tag->id, 'user_id' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]) > 0;
        });
    }

    /** Recorded Education touchpoints in the staff source-feed contract. */
    public function sources(Lead $lead): array
    {
        $items = $lead->educationEventRegistrations()->get()->map(fn ($registration) => [
            'kind' => 'event',
            'label' => 'Education registration — '.$registration->event_name,
            'detail' => implode(' · ', array_filter([self::TAG, $registration->school, $registration->eventSummary()])),
            'reference' => 'EDU-REG-'.$registration->id,
            'date' => $registration->created_at?->toIso8601String(),
        ]);

        foreach (EducationRsvp::with('visit')->where('lead_id', $lead->id)->whereNotNull('verified_at')->get() as $rsvp) {
            $status = match ($rsvp->attendance_status) {
                'attended' => 'Attended',
                'cancelled' => 'Cancelled',
                default => 'RSVP',
            };
            $items->push([
                'kind' => 'event',
                'label' => 'Education RSVP — '.$rsvp->visit->school,
                // The timestamp is the received RSVP, not an invented check-in/cancellation time.
                'detail' => 'RSVP received · Current attendance: '.$status.' · '.$rsvp->visit->activity.' · '.$rsvp->visit->summary(),
                'reference' => 'RSVP-'.$rsvp->id,
                'date' => $rsvp->created_at?->toIso8601String(),
            ]);
        }

        return $items->all();
    }
}
