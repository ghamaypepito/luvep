<?php

use App\Models\ActivityLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The live JMC visit is classified as partnership; the original school-only match skipped it.
        (require __DIR__.'/2026_11_18_140000_restore_jmc_event_follow_up_consent.php')->up();
        $visitId = DB::table('education_visits')->where('slug', 'jmc')->where('school', 'Jose Maria College Foundation, Inc.')->whereDate('event_date', '2026-11-06')->value('id');
        if (! $visitId) {
            return;
        }
        // Only the two source pairs whose full names the requester explicitly confirmed.
        foreach ([[102, 78, '50277def7ba27ac2daab857acf2311382ce2b82cdb8885552aa923023f6bf8e9'],
            [111, 82, '1581f635a41f646bc479177db2d03c8305c05e00d9525e7316f7651eae8f4fbb']] as [$rsvpId, $registrationId, $fingerprint]) {
            $this->correctConfirmedName($visitId, $rsvpId, $registrationId, $fingerprint);
        }
    }

    private function correctConfirmedName(int $visitId, int $rsvpId, int $registrationId, string $expected): void
    {
        DB::transaction(function () use ($visitId, $rsvpId, $registrationId, $expected) {
            $rsvp = DB::table('education_rsvps')->where('education_visit_id', $visitId)->where('id', $rsvpId)->lockForUpdate()->first();
            $registration = DB::table('education_event_registrations')->where('education_visit_id', $visitId)->where('id', $registrationId)->lockForUpdate()->first();
            if (! $rsvp || ! $registration) {
                return;
            }
            $contact = json_decode($registration->contact_details, true, 512, JSON_THROW_ON_ERROR);
            $key = preg_replace('/\s+/u', ' ', trim($rsvp->full_name)).'|'.preg_replace('/\s+/u', ' ', trim(($contact['first_name'] ?? '').' '.($contact['last_name'] ?? '')));
            $email = strtolower(trim($rsvp->email));
            if (! hash_equals($expected, hash('sha256', $key)) || ! filter_var($email, FILTER_VALIDATE_EMAIL) || $email !== strtolower(trim($contact['email'] ?? ''))) {
                return;
            }
            // Both confirmed surnames are single words; retain the full given-name part from the RSVP.
            $parts = preg_split('/\s+/u', trim($rsvp->full_name));
            $family = array_pop($parts);
            $given = implode(' ', $parts);
            if ($given === '' || $family === '' || mb_strlen($given) > 100 || mb_strlen($family) > 100) {
                return;
            }
            $before = array_intersect_key($contact, array_flip(['first_name', 'last_name']));
            $contact = array_replace($contact, ['first_name' => $given, 'last_name' => $family]);
            DB::table('education_event_registrations')->where('id', $registrationId)->update(['contact_details' => json_encode($contact, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            ActivityLog::create([
                'actor_name' => 'Education name review', 'actor_role' => 'system', 'portal' => 'education',
                'action' => 'education.jmc.confirmed_registration_name_corrected',
                'description' => 'Corrected the event-registration name from the requester-confirmed RSVP; no email queued.',
                'properties' => ['visit_id' => $visitId, 'rsvp_id' => $rsvpId, 'registration_id' => $registrationId, 'source' => 'requester_confirmed_full_name_2026-11-18'],
                'changes' => ['before' => $before, 'after' => ['first_name' => $given, 'last_name' => $family]],
            ]);
        });
    }

    public function down(): void
    {
        // Keep the recorded event permissions and withdrawals, as in the original repair.
    }
};
