<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\EducationOutreach;
use App\Models\EducationRsvp;
use App\Models\Lead;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

class EducationRsvpContactUpdate
{
    public const CAMPAIGN = 'rsvp-phone-request:2026-11-06:';

    public function emailKey(EducationRsvp $rsvp): string
    {
        return hash('sha256', strtolower(trim($rsvp->email)));
    }

    public function url(EducationRsvp $rsvp): string
    {
        return URL::temporarySignedRoute('education.rsvp.contact', now()->addDays(7), ['rsvp' => $rsvp->public_id, 'e' => $this->emailKey($rsvp)]);
    }

    public function contactAllowed(EducationRsvp $rsvp): bool
    {
        return (bool) $rsvp->consented_at && $rsvp->attendance_status !== 'cancelled' && $rsvp->visit && $rsvp->visit->status !== 'cancelled';
    }

    public function needsNumber(EducationRsvp $rsvp): bool
    {
        return $this->contactAllowed($rsvp) && trim((string) $rsvp->phone) === '';
    }

    public function recipients(): Collection
    {
        return EducationRsvp::with('visit')->whereNotNull('consented_at')->where('attendance_status', '!=', 'cancelled')
            ->where(fn ($q) => $q->whereNull('phone')->orWhereRaw("TRIM(phone) = ''"))
            ->whereHas('visit', fn ($q) => $q->where('status', '!=', 'cancelled'))->orderBy('id')->get()
            ->filter(fn ($r) => filter_var(trim($r->email), FILTER_VALIDATE_EMAIL))
            ->unique(fn ($r) => $this->identity($r))->values();
    }

    private function identity(EducationRsvp $rsvp): string
    {
        return strtolower(trim($rsvp->email)).'|'.EducationRsvpWorkflow::nameKey($rsvp->full_name);
    }

    public function request(EducationRsvp $rsvp): EducationOutreach
    {
        return app(EducationRsvpWorkflow::class)->receipt(self::CAMPAIGN.hash('sha256', $this->identity($rsvp)), ['education_rsvp_id' => $rsvp->id, 'channel' => 'mail', 'kind' => 'phone_request']);
    }

    public function save(EducationRsvp $rsvp, string $emailKey, string $phone): void
    {
        DB::transaction(function () use ($rsvp, $emailKey, $phone) {
            $email = strtolower(trim($rsvp->email));
            $rows = EducationRsvp::whereRaw('LOWER(TRIM(email)) = ?', [$email])->orderBy('id')->lockForUpdate()->get();
            $current = $rows->firstWhere('id', $rsvp->id);
            abort_unless($current && hash_equals($this->emailKey($current), $emailKey), 403);
            abort_unless($this->contactAllowed($current), 410);
            $name = EducationRsvpWorkflow::nameKey($current->full_name);
            $updated = collect();
            foreach ($rows as $row) {
                if (EducationRsvpWorkflow::nameKey($row->full_name) === $name && ($row->id === $current->id || trim((string) $row->phone) === '')) {
                    $row->update(['phone' => trim($phone)]);
                    $updated->push($row);
                }
            }
            $leadUpdates = 0;
            foreach ($updated->pluck('lead_id')->filter()->unique()->sort()->values() as $id) {
                $lead = Lead::whereKey($id)->lockForUpdate()->first();
                if ($lead && trim((string) $lead->phone) === '' && strtolower(trim((string) $lead->email)) === $email && EducationRsvpWorkflow::nameKey($lead->first_name.' '.$lead->last_name) === $name) {
                    $lead->update(['phone' => trim($phone)]);
                    $lead->notes()->create(['author_name' => 'Education RSVP', 'author_role' => 'system', 'kind' => 'note', 'body' => 'Contact number added through a secure RSVP update link.']);
                    $leadUpdates++;
                }
            }
            ActivityLog::record('education.rsvp.phone_updated', ['portal' => 'education', 'actor_name' => 'RSVP attendee', 'actor_role' => 'public', 'entity_type' => EducationRsvp::class, 'entity_id' => $current->id, 'description' => 'Contact number updated through a secure RSVP link', 'properties' => ['rsvp_id' => $current->id, 'rsvp_contacts_updated' => $updated->count(), 'applicant_contacts_updated' => $leadUpdates]]);
        });
    }
}
