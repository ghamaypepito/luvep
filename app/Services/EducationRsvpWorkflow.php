<?php

namespace App\Services;

use App\Models\EducationEventRegistration;
use App\Models\EducationOutreach;
use App\Models\EducationRsvp;
use App\Models\Lead;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EducationRsvpWorkflow
{
    public static function nameKey(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii(trim($name))));
    }

    public function recipients()
    {
        $ids = (array) Setting::get('education_landing_notify_ids', []);

        return ($ids ? User::whereIn('id', $ids) : User::where('role', 'education'))->get()->filter(fn ($u) => $u->canAccessPortal('education'));
    }

    public function queueRsvp(EducationRsvp $r): void
    {
        $this->receipt('rsvp:'.$r->id.':confirmation', ['education_rsvp_id' => $r->id, 'channel' => 'mail', 'kind' => 'confirmation']);
        foreach ($this->recipients() as $staff) {
            $this->receipt('rsvp:'.$r->id.':staff:'.$staff->id, ['education_rsvp_id' => $r->id, 'channel' => 'staff', 'kind' => 'received', 'user_id' => $staff->id]);
        }$this->receipt('rsvp:'.$r->id.':slack', ['education_rsvp_id' => $r->id, 'channel' => 'slack', 'kind' => 'received']);
    }

    public function queueRegistration(EducationEventRegistration $r): void
    {
        $this->receipt('registration:'.$r->id.':slack', ['education_registration_id' => $r->id, 'channel' => 'slack', 'kind' => 'registration']);
        $this->linkLead($r->lead);
    }

    public function receipt(string $key, array $data): EducationOutreach
    {
        return EducationOutreach::firstOrCreate(['event_key' => $key], $data);
    }

    public function linkLead(Lead $lead): int
    {
        $name = self::nameKey($lead->first_name.' '.$lead->last_name);
        $email = strtolower(trim((string) $lead->email));
        if (! $name || ! $email) {
            return 0;
        }
        $matches = Lead::whereRaw('LOWER(TRIM(email)) = ?', [$email])->get()->filter(fn ($l) => self::nameKey($l->first_name.' '.$l->last_name) === $name);
        if ($matches->count() !== 1 || $matches->first()->id !== $lead->id) {
            return 0;
        }

        return DB::transaction(function () use ($lead, $name, $email) {
            $count = 0;
            $rows = EducationRsvp::with('visit')->where('email', $email)->where('name_key', $name)->whereNotNull('verified_at')->whereNull('lead_id')->lockForUpdate()->get();
            foreach ($rows as $r) {
                $r->update(['lead_id' => $lead->id, 'linked_at' => now()]);
                $lead->notes()->create(['author_name' => 'Education RSVP', 'author_role' => 'system', 'kind' => 'note', 'body' => 'Verified school-visit RSVP #'.$r->id.' · '.$r->visit->school."\nRSVP received: ".$r->created_at->toIso8601String()."\n".$r->visit->summary()."\nAttendance status: ".$r->attendance_status]);
                $count++;
            }

            return $count;
        });
    }

    public function matchVerified(EducationRsvp $r): void
    {
        foreach (Lead::whereRaw('LOWER(TRIM(email)) = ?', [$r->email])->whereHas('educationEventRegistrations')->get() as $lead) {
            $this->linkLead($lead);
        }
    }

    public function history(Lead $lead): array
    {
        return EducationRsvp::with('visit')->where('lead_id', $lead->id)->whereNotNull('verified_at')->get()->map(fn ($r) => ['type' => 'education_rsvp', 'title' => 'Education '.($r->attendance_status === 'attended' ? 'visit attended' : 'RSVP received'), 'subtitle' => $r->visit->school.' · '.$r->visit->activity, 'status' => $r->attendance_status === 'attended' ? 'Attended' : 'RSVP', 'status_tone' => 'success', 'reference' => 'RSVP-'.$r->id, 'date' => $r->created_at->toIso8601String(), 'detail' => $r->visit->summary()])->all();
    }
}
