<?php

namespace Tests\Feature\Education;

use App\Models\ActivityLog;
use App\Models\EducationEventRegistration;
use App\Models\EducationRsvp;
use App\Models\EducationVisit;
use App\Models\EmailReply;
use App\Models\Lead;
use App\Models\MessageLog;
use App\Services\EducationSchoolMailAudience;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class JmcLegacyConsentTest extends TestCase
{
    use RefreshDatabase;

    private function visit(array $overrides = []): EducationVisit
    {
        return EducationVisit::create($overrides + ['slug' => (string) Str::uuid(), 'school' => 'Jose Maria College Foundation, Inc.', 'kind' => 'partnership', 'activity' => 'Education event', 'event_date' => '2026-11-06', 'event_time' => '08:30', 'timezone' => 'Asia/Manila', 'status' => 'open']);
    }

    private function rsvp(EducationVisit $visit, array $overrides = []): EducationRsvp
    {
        return EducationRsvp::create($overrides + ['education_visit_id' => $visit->id, 'public_id' => (string) Str::uuid(), 'submission_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'name_key' => 'alextaylor', 'full_name' => 'Alex Taylor', 'email' => 'alex@example.test', 'consented_at' => '2026-11-06 06:00:00', 'created_at' => '2026-11-06 06:00:00']);
    }

    private function registration(EducationVisit $visit, string $email): EducationEventRegistration
    {
        $lead = Lead::create(['first_name' => 'Alex', 'last_name' => 'Taylor', 'email' => $email]);

        return EducationEventRegistration::create(['lead_id' => $lead->id, 'submission_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('b', 64), 'education_visit_id' => $visit->id, 'event_name' => 'Education event', 'event_timezone' => 'Asia/Manila', 'country' => 'Philippines', 'school' => $visit->school, 'goal' => 'Study', 'planning_timeline' => 'Later', 'education_level' => 'Bachelor', 'contact_details' => ['first_name' => 'Alex', 'last_name' => 'Taylor', 'email' => $email], 'consented_at' => '2026-11-06 06:00:00', 'created_at' => '2026-11-06 06:00:00']);
    }

    private function repair(): void
    {
        (require database_path('migrations/2026_11_18_150000_replay_jmc_event_consent_mapping.php'))->up();
    }

    public function test_restores_both_legacy_sources_with_audit_and_no_mail_or_queue(): void
    {
        Mail::fake();
        Queue::fake();
        $visit = $this->visit(['slug' => 'jmc']);
        $rsvp = $this->rsvp($visit);
        $registration = $this->registration($visit, 'alex@example.test');
        $programmeOnly = $this->registration($visit, 'programme-only@example.test');
        $publicId = $rsvp->public_id;
        $filters = ['visit_ids' => [$visit->id], 'audience' => 'registered', 'purpose' => 'email_follow_up'];
        $audience = app(EducationSchoolMailAudience::class);
        $this->assertSame(0, $audience->rows($filters)->where('eligible', true)->count());
        $this->repair();
        $this->repair();
        foreach ([$rsvp, $registration, $programmeOnly] as $record) {
            $record->refresh();
            $this->assertTrue($record->legacy_event_follow_up);
            $this->assertFalse($record->email_follow_up);
            $this->assertFalse($record->email_newsletter);
            $this->assertSame('2026-11-06', $record->consented_at->toDateString());
        }
        $this->assertSame(2, $audience->rows($filters)->where('eligible', true)->count());
        $url = URL::temporarySignedRoute('education.register.email-preferences', now()->addDay(), ['registration' => $programmeOnly->submission_id]);
        $this->get($url)->assertViewHas('choices', ['follow_up' => false, 'newsletter' => false]);
        $this->assertSame($publicId, $rsvp->public_id);
        $this->assertNull($rsvp->verified_at);
        $this->assertNull($rsvp->lead_id);
        $this->assertSame(str_repeat('a', 64), $rsvp->payload_hash);
        $audits = ActivityLog::where('action', 'education.jmc.legacy_follow_up_restored')->get();
        $this->assertCount(2, $audits);
        $this->assertSame(3, $audits->sum(fn ($log) => count($log->properties['record_ids'])));
        $this->assertDatabaseCount('education_school_mailings', 0);
        $this->assertDatabaseCount('education_outreach', 0);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Queue::assertNothingPushed();
    }

    public function test_preserves_later_choices_withdrawals_cancelled_records_and_other_visits(): void
    {
        $visit = $this->visit(['slug' => 'jmc']);
        $later = $this->rsvp($visit, ['email' => 'later@example.test', 'created_at' => '2026-11-08 00:00:00']);
        $lateConsent = $this->rsvp($visit, ['email' => 'late-consent@example.test', 'consented_at' => '2026-11-08 00:00:00']);
        $cancelled = $this->rsvp($visit, ['email' => 'cancelled@example.test', 'attendance_status' => 'cancelled']);
        $other = $this->rsvp($this->visit(['event_date' => '2026-11-09']));
        $schoolType = $this->rsvp($this->visit(['kind' => 'school']));
        $otherSlug = $this->rsvp($this->visit());
        $withdrawn = $this->rsvp($visit, ['email' => 'withdrawn@example.test']);
        ActivityLog::create(['action' => 'education.rsvp.answers_updated', 'entity_type' => EducationRsvp::class, 'entity_id' => $withdrawn->id,
            'changes' => ['before' => ['email_follow_up' => true], 'after' => ['email_follow_up' => false]]]);
        $corrected = $this->rsvp($visit, ['email' => 'corrected@example.test']);
        ActivityLog::create(['action' => 'education.rsvp.answers_updated', 'entity_type' => EducationRsvp::class, 'entity_id' => $corrected->id,
            'properties' => ['identity_changed' => true]]);
        $explicit = $this->rsvp($visit, ['email' => 'explicit-no@example.test', 'legacy_event_follow_up' => false]);
        $this->repair();
        foreach ([$later, $lateConsent, $cancelled, $other, $schoolType, $otherSlug, $withdrawn, $corrected, $explicit] as $record) {
            $this->assertFalse($record->fresh()->email_follow_up);
            $this->assertNotSame(true, $record->fresh()->legacy_event_follow_up);
        }
        $this->assertFalse(ActivityLog::where('action', 'education.jmc.legacy_follow_up_restored')->exists());
    }

    public function test_rollback_and_repair_respect_a_later_event_contact_withdrawal(): void
    {
        $visit = $this->visit(['slug' => 'jmc']);
        $rsvp = $this->rsvp($visit);
        $this->repair();
        $rsvp->refresh()->update(['legacy_event_follow_up' => false]);
        ActivityLog::create(['action' => 'education.rsvp.answers_updated', 'entity_type' => EducationRsvp::class, 'entity_id' => $rsvp->id,
            'changes' => ['before' => ['legacy_event_follow_up' => true], 'after' => ['legacy_event_follow_up' => false]]]);
        $migration = require database_path('migrations/2026_11_18_140000_restore_jmc_event_follow_up_consent.php');
        $migration->down();
        $migration->up();
        $this->assertFalse($rsvp->fresh()->legacy_event_follow_up);
        $this->assertFalse($rsvp->fresh()->email_follow_up);
    }

    public function test_confirmed_name_correction_is_guarded_audited_and_preserves_other_contact_data(): void
    {
        Mail::fake();
        Queue::fake();
        $visit = $this->visit(['slug' => 'jmc']);
        $rsvp = $this->rsvp($visit, ['full_name' => 'Alex M. Taylor']);
        $registration = $this->registration($visit, 'alex@example.test');
        $contact = array_replace($registration->contact_details, ['last_name' => 'Tayler', 'phone' => '+639171234567', 'extra' => 'preserve']);
        $registration->update(['contact_details' => $contact]);
        $migration = require database_path('migrations/2026_11_18_150000_replay_jmc_event_consent_mapping.php');
        $method = new \ReflectionMethod($migration, 'correctConfirmedName');
        $fingerprint = hash('sha256', $rsvp->full_name.'|Alex Tayler');
        $method->invoke($migration, $visit->id, $rsvp->id, $registration->id, str_repeat('0', 64));
        $this->assertSame('Tayler', $registration->fresh()->contact_details['last_name']);
        $rsvp->update(['full_name' => 'Alex M.Taylor']);
        $method->invoke($migration, $visit->id, $rsvp->id, $registration->id, $fingerprint);
        $this->assertSame('Tayler', $registration->fresh()->contact_details['last_name']);
        $rsvp->update(['full_name' => 'Alex M. Taylor', 'email' => 'different@example.test']);
        $method->invoke($migration, $visit->id, $rsvp->id, $registration->id, $fingerprint);
        $this->assertSame('Tayler', $registration->fresh()->contact_details['last_name']);
        $rsvp->update(['email' => 'alex@example.test']);
        $method->invoke($migration, $visit->id, $rsvp->id, $registration->id, $fingerprint);
        $method->invoke($migration, $visit->id, $rsvp->id, $registration->id, $fingerprint);
        $registration->refresh();
        $this->assertSame(array_replace($contact, ['first_name' => 'Alex M.', 'last_name' => 'Taylor']), $registration->contact_details);
        $this->assertSame(str_repeat('b', 64), $registration->payload_hash);
        $this->assertFalse($registration->email_follow_up);
        $this->assertFalse($registration->email_newsletter);
        $this->assertSame('Alex M. Taylor', $rsvp->fresh()->full_name);
        $this->assertSame('Taylor', $registration->lead->last_name);
        $audit = ActivityLog::where('action', 'education.jmc.confirmed_registration_name_corrected')->sole();
        $this->assertSame('Tayler', $audit->changes['before']['last_name']);
        $this->assertSame('Taylor', $audit->changes['after']['last_name']);
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('education_school_mailings', 0);
        $this->assertDatabaseCount('education_outreach', 0);
    }

    public function test_restored_permission_does_not_override_unsubscribes_bounces_or_newsletter_confirmation(): void
    {
        $visit = $this->visit(['slug' => 'jmc']);
        $this->rsvp($visit, ['email' => 'unsubscribed@example.test']);
        $this->rsvp($visit, ['email' => 'bounced@example.test']);
        EmailReply::create(['direction' => 'inbound', 'from_email' => 'unsubscribed@example.test', 'to_email' => 'staff@example.test', 'subject' => 'Unsubscribe', 'message_id' => 'jmc-unsubscribe', 'body_text' => 'Stop']);
        MessageLog::create(['channel' => 'email', 'recipient_type' => 'raw', 'recipient_address' => 'bounced@example.test', 'status' => 'bounced']);
        $this->repair();
        $audience = app(EducationSchoolMailAudience::class);
        foreach (['email_follow_up', 'email_newsletter'] as $purpose) {
            $this->assertSame(0, $audience->rows(['visit_ids' => [$visit->id], 'audience' => 'registered', 'purpose' => $purpose])->where('eligible', true)->count());
        }
    }
}
