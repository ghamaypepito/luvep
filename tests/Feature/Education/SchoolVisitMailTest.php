<?php

namespace Tests\Feature\Education;

use App\Jobs\SendEducationSchoolMail;
use App\Mail\TemplatedMessage;
use App\Models\EducationEventEmailSubscription;
use App\Models\EducationEventRegistration;
use App\Models\EducationRsvp;
use App\Models\EducationSchoolMailing;
use App\Models\EducationSchoolMailRecipient;
use App\Models\EducationVisit;
use App\Models\EmailBranding;
use App\Models\EmailReply;
use App\Models\Lead;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\EducationSchoolMailAudience;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class SchoolVisitMailTest extends TestCase
{
    use RefreshDatabase;

    private function visit(array $a = []): EducationVisit
    {
        return EducationVisit::create($a + ['slug' => (string) Str::uuid(), 'school' => 'Jose Maria College Foundation, Inc.', 'kind' => 'school', 'activity' => 'School visit', 'status' => 'open']);
    }

    private function rsvp(EducationVisit $v, array $a = []): EducationRsvp
    {
        return EducationRsvp::create($a + ['education_visit_id' => $v->id, 'public_id' => (string) Str::uuid(), 'submission_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'name_key' => 'alextaylor', 'full_name' => 'Alex Taylor', 'email' => 'alex@example.test', 'consented_at' => now(), 'verified_at' => now(), 'email_follow_up' => true, 'email_newsletter' => false]);
    }

    private function registration(EducationVisit $v, array $a = []): EducationEventRegistration
    {
        $l = Lead::create(['first_name' => 'Alex', 'last_name' => 'Taylor', 'email' => 'alex@example.test']);

        return EducationEventRegistration::create($a + ['lead_id' => $l->id, 'submission_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'education_visit_id' => $v->id, 'event_name' => 'School visit', 'event_timezone' => 'Asia/Manila', 'country' => 'Philippines', 'school' => $v->school, 'goal' => 'Study', 'planning_timeline' => 'Later', 'education_level' => 'Bachelor', 'contact_details' => ['first_name' => 'Alex', 'last_name' => 'Taylor', 'email' => 'alex@example.test'], 'consented_at' => now(), 'email_follow_up' => true]);
    }

    private function template(array $a = []): MessageTemplate
    {
        return MessageTemplate::create($a + ['key' => (string) Str::uuid(), 'name' => 'School follow-up', 'department' => 'education', 'is_active' => true, 'channels' => ['email'], 'email_subject' => 'Hello {{full_name}}', 'email_body' => '<p>{{school}} {{full_name}}</p>']);
    }

    private function filters(EducationVisit $v, array $a = []): array
    {
        return $a + ['visit_ids' => [$v->id], 'audience' => 'registered', 'purpose' => 'email_follow_up'];
    }

    private function rows(array $f)
    {
        return app(EducationSchoolMailAudience::class)->rows($f);
    }

    private function staff(string $role = 'education'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function payload(EducationVisit $v, array $a = []): array
    {
        $template = $this->template();
        $audience = app(EducationSchoolMailAudience::class);

        return $a + $this->filters($v) + ['submission_id' => (string) Str::uuid(), 'name' => 'Synthetic test', 'template_id' => $template->id,
            'template_review' => $audience->templateReview($audience->templateSnapshot($template)), 'recipient_keys' => $this->rows($this->filters($v))->pluck('id')->all(), 'confirmed' => true];
    }

    private function mailing(EducationVisit $v): EducationSchoolMailRecipient
    {
        $u = $this->staff();
        $row = $this->rows($this->filters($v))->first();
        $template = $this->template(['from_email' => 'staff@example.test', 'from_name' => 'Education']);
        $m = EducationSchoolMailing::create(['created_by' => $u->id, 'submission_id' => (string) Str::uuid(), 'name' => 'Synthetic', 'filters' => $this->filters($v),
            'template_snapshot' => app(EducationSchoolMailAudience::class)->templateSnapshot($template)]);

        return $m->recipients()->create(['email' => $row['email'], 'name' => $row['name'], 'source_refs' => $row['source_refs'], 'context_snapshot' => $row['context']]);
    }

    public function test_school_visits_are_separate_from_general_events_and_unlinked_rsvps_appear(): void
    {
        $v = $this->visit();
        $this->rsvp($v);
        $other = $this->visit(['school' => 'Tagum City College of Science and Technology Foundation, Inc.']);
        $this->rsvp($other, ['email' => 'other@example.test']);
        $this->actingAs($this->staff())->get('/portal/education/school-mail?'.http_build_query($this->filters($v)))
            ->assertOk()->assertInertia(fn ($p) => $p->component('portal/education/SchoolMail')->has('visits', 2)->has('recipients', 1)
            ->where('recipients.0.email', 'alex@example.test')->where('recipients.0.eligible', true));
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_registration_and_rsvp_are_deduplicated_across_selected_visits(): void
    {
        $v = $this->visit();
        $w = $this->visit(['school' => 'Tagum']);
        $this->rsvp($v);
        $this->rsvp($w);
        $this->registration($v);
        $rows = $this->rows($this->filters($v, ['visit_ids' => [$v->id, $w->id]]));
        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['eligible']);
        $this->assertCount(3, $rows[0]['source_refs']);
        $this->assertCount(2, $rows[0]['schools']);
    }

    public function test_purpose_verification_cancelled_and_conflicting_names_control_eligibility(): void
    {
        $v = $this->visit();
        $this->rsvp($v, ['verified_at' => null]);
        $this->rsvp($v, ['email' => 'no-permission@example.test', 'email_follow_up' => false]);
        $this->rsvp($v, ['email' => 'cancelled@example.test', 'attendance_status' => 'cancelled']);
        $this->rsvp($v, ['email' => 'newsletter@example.test', 'email_follow_up' => false, 'email_newsletter' => true]);
        $this->assertCount(3, $this->rows($this->filters($v)));
        $this->assertFalse($this->rows($this->filters($v))->contains('eligible', true));
        $this->assertSame('newsletter@example.test', $this->rows($this->filters($v, ['purpose' => 'email_newsletter']))->firstWhere('eligible', true)['email']);
        $w = $this->visit();
        $this->rsvp($w, ['full_name' => 'Another Person']);
        $this->assertFalse($this->rows($this->filters($v, ['visit_ids' => [$v->id, $w->id]]))->firstWhere('email', 'alex@example.test')['eligible']);
    }

    public function test_attended_filter_uses_recorded_rsvp_status_and_labels_automatic_records(): void
    {
        $v = $this->visit();
        $this->registration($v);
        $this->rsvp($v, ['email' => 'automatic@example.test', 'attendance_status' => 'attended', 'attendance_source' => 'automatic']);
        $this->rsvp($v, ['email' => 'registered@example.test']);
        $rows = $this->rows($this->filters($v, ['audience' => 'attended']));
        $this->assertCount(1, $rows);
        $this->assertSame('Recorded attended (automatic)', $rows[0]['attendance']);
    }

    public function test_unsubscribe_bounce_and_confirmed_subscription_are_respected(): void
    {
        $v = $this->visit();
        $this->rsvp($v);
        $reg = $this->registration($v, ['email_follow_up' => false]);
        EducationEventEmailSubscription::create(['lead_id' => $reg->lead_id, 'registration_id' => $reg->id, 'email' => 'alex@example.test', 'follow_up_confirmed_at' => now()]);
        $this->assertTrue($this->rows($this->filters($v))[0]['eligible']);
        EmailReply::create(['direction' => 'inbound', 'from_email' => 'alex@example.test', 'to_email' => 'staff@example.test', 'subject' => 'Unsubscribe', 'message_id' => 'test-unsubscribe', 'body_text' => 'Please stop']);
        $this->assertFalse($this->rows($this->filters($v))[0]['eligible']);
        EmailReply::query()->delete();
        MessageLog::create(['channel' => 'email', 'recipient_type' => 'raw', 'recipient_address' => 'alex@example.test', 'status' => 'bounced']);
        $this->assertFalse($this->rows($this->filters($v))[0]['eligible']);
    }

    public function test_create_snapshots_recipients_and_repeated_submission_does_not_duplicate(): void
    {
        Queue::fake();
        $v = $this->visit();
        $this->rsvp($v);
        $data = $this->payload($v);
        $this->actingAs($this->staff());
        $this->post('/portal/education/school-mail', $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/portal/education/school-mail', $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('education_school_mailings', 1);
        $this->assertDatabaseCount('education_school_mail_recipients', 1);
        $this->assertSame('alex@example.test', EducationSchoolMailRecipient::sole()->email);
        Queue::assertPushed(SendEducationSchoolMail::class, 1);
    }

    public function test_editing_a_reviewed_template_field_rejects_submission_before_queueing(): void
    {
        Queue::fake();
        $v = $this->visit();
        $this->rsvp($v);
        $data = $this->payload($v);
        $template = MessageTemplate::findOrFail($data['template_id']);
        $this->actingAs($this->staff());
        $this->get('/portal/education/school-mail?'.http_build_query($this->filters($v)))
            ->assertInertia(function ($page) use (&$data) {
                $page->where('templates.0.review', $data['template_review']);
                $data['template_review'] = $page->toArray()['props']['templates'][0]['review'];
            });

        foreach (['email_subject' => 'Changed subject', 'email_body' => '<p>Changed body</p>', 'from_email' => 'changed@example.test', 'reply_to_email' => 'replies@example.test', 'branding' => 'immigration', 'banner_image' => 'new-banner.png', 'footer_image' => 'new-footer.png'] as $field => $value) {
            $original = $template->{$field};
            $template->update([$field => $value]);
            $this->post('/portal/education/school-mail', $data)->assertSessionHasErrors('template_review');
            $template->update([$field => $original]);
        }
        EmailBranding::create(['department' => 'default', 'footer_company' => 'Changed branding']);
        $this->post('/portal/education/school-mail', $data)->assertSessionHasErrors('template_review');
        $this->assertDatabaseCount('education_school_mailings', 0);
        Queue::assertNothingPushed();
    }

    public function test_worker_uses_the_reviewed_sender_body_and_branding_after_queueing(): void
    {
        Queue::fake();
        $v = $this->visit();
        $this->rsvp($v);
        $brand = EmailBranding::create(['department' => 'default', 'footer_company' => 'Reviewed branding']);
        config(['mail.from.address' => 'reviewed@example.test', 'services.contact.reply_to' => 'replies@example.test']);
        $data = $this->payload($v);
        $this->actingAs($this->staff())->post('/portal/education/school-mail', $data)->assertSessionHasNoErrors();
        Queue::assertPushed(SendEducationSchoolMail::class, 1);
        MessageTemplate::findOrFail($data['template_id'])->update(['email_subject' => 'Changed subject', 'email_body' => 'Changed body', 'from_email' => 'changed@example.test']);
        $brand->update(['footer_company' => 'Changed branding']);
        config(['mail.from.address' => 'changed@example.test', 'services.contact.reply_to' => 'changed@example.test']);

        Mail::fake();
        (new SendEducationSchoolMail(EducationSchoolMailRecipient::sole()->id))->handle(app(EducationSchoolMailAudience::class));
        Mail::assertSent(TemplatedMessage::class, 1);
        $mail = Mail::sent(TemplatedMessage::class)->sole();
        $this->assertSame('Hello Alex Taylor', $mail->subjectLine);
        $this->assertSame('reviewed@example.test', $mail->envelope()->from->address);
        $this->assertSame('replies@example.test', $mail->envelope()->replyTo[0]->address);
        $html = $mail->render();
        $this->assertStringContainsString('<title>Hello Alex Taylor</title>', $html);
        $this->assertStringContainsString('Reviewed branding', $html);
        $this->assertStringContainsString('<p>Jose Maria College Foundation, Inc. Alex Taylor</p>', $html);
        $this->assertStringNotContainsString('Changed branding', $html);
        $this->assertSame('sent', EducationSchoolMailRecipient::sole()->status);
    }

    public function test_incomplete_templates_are_excluded_and_cannot_be_queued(): void
    {
        Queue::fake();
        $v = $this->visit();
        $this->rsvp($v);
        $data = $this->payload($v);
        $incomplete = collect([['email_subject' => null], ['email_body' => null], ['email_subject' => '   '], ['email_body' => "\n   "], ['email_body' => '<p>&nbsp;<br></p>']])
            ->map(fn ($attributes) => $this->template($attributes));
        $this->actingAs($this->staff())->get('/portal/education/school-mail')
            ->assertInertia(fn ($p) => $p->has('templates', 1)->where('templates.0.id', $data['template_id']));
        foreach ($incomplete as $template) {
            $this->post('/portal/education/school-mail', array_replace($data, ['template_id' => $template->id, 'template_review' => str_repeat('a', 64)]))
                ->assertSessionHasErrors('template_id');
        }
        $this->assertDatabaseCount('education_school_mailings', 0);
        Queue::assertNothingPushed();
    }

    public function test_worker_skips_an_incomplete_snapshot_without_sending(): void
    {
        Mail::fake();
        $v = $this->visit();
        $this->rsvp($v);
        $item = $this->mailing($v);
        $item->mailing->update(['template_snapshot' => array_replace($item->mailing->template_snapshot, ['body' => '<p>&nbsp;<br></p>'])]);

        (new SendEducationSchoolMail($item->id))->handle(app(EducationSchoolMailAudience::class));

        $this->assertSame('skipped', $item->fresh()->status);
        $this->assertDatabaseCount('message_logs', 0);
        Mail::assertNothingSent();
    }

    public function test_forged_recipient_keys_wrong_school_and_stale_permission_are_rejected(): void
    {
        Queue::fake();
        $v = $this->visit();
        $r = $this->rsvp($v);
        $data = $this->payload($v);
        $this->actingAs($this->staff());
        $this->post('/portal/education/school-mail', array_replace($data, ['recipient_keys' => [str_repeat('f', 64)]]))->assertSessionHasErrors('recipient_keys');
        $w = $this->visit();
        $this->post('/portal/education/school-mail', array_replace($data, ['visit_ids' => [$w->id]]))->assertSessionHasErrors('recipient_keys');
        $r->update(['email_follow_up' => false]);
        $this->post('/portal/education/school-mail', $data)->assertSessionHasErrors('recipient_keys');
        $this->assertDatabaseCount('education_school_mailings', 0);
        Queue::assertNothingPushed();
    }

    public function test_templates_with_credentials_or_unknown_tokens_and_missing_confirmation_are_rejected(): void
    {
        Queue::fake();
        $v = $this->visit();
        $this->rsvp($v);
        $data = $this->payload($v);
        $this->actingAs($this->staff());
        $bad = $this->template(['email_body' => 'Password {{password}}']);
        $this->post('/portal/education/school-mail', array_replace($data, ['template_id' => $bad->id]))->assertSessionHasErrors('template_id');
        $this->post('/portal/education/school-mail', array_replace($data, ['confirmed' => false]))->assertSessionHasErrors('confirmed');
        $this->assertDatabaseCount('education_school_mailings', 0);
    }

    public function test_other_portal_roles_and_inactive_staff_cannot_read_or_create_mailings(): void
    {
        $v = $this->visit();
        $this->rsvp($v);
        $data = $this->payload($v);
        foreach (['sales', 'finance', 'immigration', 'agent', 'lead'] as $role) {
            $this->actingAs($this->staff($role))->get('/portal/education/school-mail')->assertForbidden();
            $this->post('/portal/education/school-mail', $data)->assertForbidden();
        }
        $this->actingAs($this->staff('admin'))->get('/portal/education/school-mail')->assertOk();
        $this->assertDatabaseCount('education_school_mailings', 0);
    }

    public function test_worker_rechecks_permission_address_visit_and_sender_before_delivery(): void
    {
        Mail::fake();
        $v = $this->visit();
        $r = $this->rsvp($v);
        $item = $this->mailing($v);
        $r->update(['email_follow_up' => false]);
        (new SendEducationSchoolMail($item->id))->handle(app(EducationSchoolMailAudience::class));
        $this->assertSame('skipped', $item->fresh()->status);
        Mail::assertNothingSent();
        $r->update(['email_follow_up' => true]);
        $item = $this->mailing($v);
        $item->mailing->creator->update(['role' => 'finance']);
        (new SendEducationSchoolMail($item->id))->handle(app(EducationSchoolMailAudience::class));
        $this->assertSame('skipped', $item->fresh()->status);
        $item = $this->mailing($v);
        $v->update(['status' => 'cancelled']);
        (new SendEducationSchoolMail($item->id))->handle(app(EducationSchoolMailAudience::class));
        $this->assertSame('skipped', $item->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_worker_sends_once_with_escaped_values_and_no_accidental_lead_log_link(): void
    {
        Mail::fake();
        $v = $this->visit();
        $this->rsvp($v, ['full_name' => '<Alex> Taylor']);
        $item = $this->mailing($v);
        $job = new SendEducationSchoolMail($item->id);
        $job->handle(app(EducationSchoolMailAudience::class));
        $job->handle(app(EducationSchoolMailAudience::class));
        Mail::assertSent(TemplatedMessage::class, fn ($mail) => $mail->hasTo('alex@example.test') && str_contains($mail->markdownBody, '&lt;Alex&gt;') && ! str_contains($mail->markdownBody, '<Alex>'));
        Mail::assertSentCount(1);
        $this->assertSame('sent', $item->fresh()->status);
        $this->assertDatabaseCount('message_logs', 1);
        $this->assertNull(MessageLog::sole()->recipient_id);
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_transport_failure_is_recorded_without_automatic_duplicate_retry(): void
    {
        $v = $this->visit();
        $this->rsvp($v);
        $item = $this->mailing($v);
        Mail::shouldReceive('sendNow')->once()->andThrow(new \RuntimeException('Synthetic transport failure'));
        $job = new SendEducationSchoolMail($item->id);
        $job->handle(app(EducationSchoolMailAudience::class));
        $job->handle(app(EducationSchoolMailAudience::class));
        $this->assertSame('failed', $item->fresh()->status);
        $this->assertSame('failed', MessageLog::sole()->status);
    }

    public function test_changed_destination_or_removed_source_never_retargets_the_email(): void
    {
        Mail::fake();
        $v = $this->visit();
        $r = $this->rsvp($v);
        $item = $this->mailing($v);
        $r->update(['email' => 'changed@example.test']);
        (new SendEducationSchoolMail($item->id))->handle(app(EducationSchoolMailAudience::class));
        $this->assertSame('skipped', $item->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_pending_recovery_never_requeues_failed_sent_or_uncertain_recipients(): void
    {
        Queue::fake();
        $v = $this->visit();
        $this->rsvp($v);
        $pending = $this->mailing($v);
        foreach (['sent', 'failed', 'sending', 'skipped'] as $status) {
            $this->mailing($v)->update(['status' => $status]);
        }
        $this->artisan('education:dispatch-school-mail', ['--mailing' => $pending->mailing_id])->assertSuccessful();
        Queue::assertPushed(SendEducationSchoolMail::class, fn ($job) => $job->recipientId === $pending->id);
        Queue::assertPushed(SendEducationSchoolMail::class, 1);
    }

    public function test_worker_rechecks_unsubscribe_and_unchanged_registration_context(): void
    {
        Mail::fake();
        $v = $this->visit();
        $this->registration($v);
        $item = $this->mailing($v);
        EmailReply::create(['direction' => 'inbound', 'from_email' => 'alex@example.test', 'to_email' => 'staff@example.test', 'subject' => 'Unsubscribe', 'message_id' => 'worker-unsubscribe', 'body_text' => 'unsubscribe']);
        (new SendEducationSchoolMail($item->id))->handle(app(EducationSchoolMailAudience::class));
        $this->assertSame('skipped', $item->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_mysql_json_object_key_reordering_does_not_skip_a_valid_recipient(): void
    {
        Mail::fake();
        $v = $this->visit();
        $this->rsvp($v);
        $item = $this->mailing($v);
        $item->update(['source_refs' => array_map(fn ($ref) => array_reverse($ref, true), $item->source_refs), 'context_snapshot' => array_reverse($item->context_snapshot, true)]);
        (new SendEducationSchoolMail($item->id))->handle(app(EducationSchoolMailAudience::class));
        $this->assertSame('sent', $item->fresh()->status);
        Mail::assertSentCount(1);
    }

    public function test_changed_preview_is_rejected_before_queueing_and_changed_visit_context_is_skipped(): void
    {
        Queue::fake();
        Mail::fake();
        $v = $this->visit();
        $this->rsvp($v);
        $data = $this->payload($v);
        $item = $this->mailing($v);
        $v->update(['activity' => 'Different activity']);
        $this->actingAs($this->staff())->post('/portal/education/school-mail', $data)->assertSessionHasErrors('recipient_keys');
        (new SendEducationSchoolMail($item->id))->handle(app(EducationSchoolMailAudience::class));
        $this->assertSame('skipped', $item->fresh()->status);
        Mail::assertNothingSent();
    }
}
