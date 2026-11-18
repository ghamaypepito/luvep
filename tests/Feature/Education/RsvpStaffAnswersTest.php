<?php

namespace Tests\Feature\Education;

use App\Models\ActivityLog;
use App\Models\EducationOutreach;
use App\Models\EducationRsvp;
use App\Models\EducationVisit;
use App\Models\Lead;
use App\Models\User;
use App\Services\EducationRsvpWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class RsvpStaffAnswersTest extends TestCase
{
    use RefreshDatabase;

    private function rsvp(array $overrides = []): EducationRsvp
    {
        $visit = EducationVisit::create(['slug' => (string) Str::uuid(), 'school' => 'Example college', 'kind' => 'school', 'activity' => 'School visit', 'status' => 'open']);

        return EducationRsvp::create($overrides + ['education_visit_id' => $visit->id, 'full_name' => 'Alex Taylor', 'name_key' => 'alextaylor', 'email' => 'alex@example.test', 'phone' => null,
            'public_id' => (string) Str::uuid(), 'submission_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'consented_at' => now(), 'verified_at' => now()])->fresh();
    }

    private function answers(EducationRsvp $rsvp, array $overrides = []): array
    {
        return $overrides + ['_form' => 'answers-'.$rsvp->id, 'revision' => $rsvp->answersRevision(), 'full_name' => $rsvp->full_name, 'email' => $rsvp->email,
            'phone' => $rsvp->phone, 'role' => $rsvp->role, 'email_reminders' => false, 'future_invites' => false, 'email_follow_up' => true, 'email_newsletter' => false];
    }

    public function test_staff_corrects_normalised_answers_with_audit_without_sending_or_rewriting_submission(): void
    {
        Mail::fake();
        Queue::fake();
        $staff = User::factory()->create(['role' => 'education']);
        $rsvp = $this->rsvp(['email' => 'ALEX@example.test']);
        $payloadHash = $rsvp->payload_hash;
        $publicId = $rsvp->public_id;
        $this->actingAs($staff)->get('/portal/education/rsvps')->assertOk()->assertSee('Save RSVP answers')->assertSee('Adviser follow-ups: No');
        $this->from('/portal/education/rsvps?visit_id='.$rsvp->education_visit_id)
            ->put(route('education.rsvps.answers', $rsvp), $this->answers($rsvp, ['full_name' => ' Alex   Taylor ', 'email' => 'ALEX@example.test', 'phone' => ' +639171234567 ', 'role' => ' Student ', 'verified_at' => null, 'lead_id' => 999, 'consented_at' => null, 'attendance_status' => 'cancelled']))
            ->assertRedirect('/portal/education/rsvps?visit_id='.$rsvp->education_visit_id)->assertSessionHasNoErrors();
        $rsvp->refresh();
        $this->assertSame('Alex Taylor', $rsvp->full_name);
        $this->assertSame('alex@example.test', $rsvp->email);
        $this->assertSame('+639171234567', $rsvp->phone);
        $this->assertSame('Student', $rsvp->role);
        $this->assertTrue($rsvp->email_follow_up);
        $this->assertFalse($rsvp->email_newsletter);
        $this->assertSame('rsvp', $rsvp->attendance_status);
        $this->assertNotNull($rsvp->verified_at);
        $this->assertNotNull($rsvp->consented_at);
        $this->assertNull($rsvp->lead_id);
        $this->assertSame($publicId, $rsvp->public_id);
        $this->assertSame($payloadHash, $rsvp->payload_hash);
        $audit = ActivityLog::where('action', 'education.rsvp.answers_updated')->sole();
        $this->assertSame($staff->id, $audit->user_id);
        $this->assertSame('education', $audit->portal);
        $this->assertNull($audit->changes['before']['phone']);
        $this->assertSame('+639171234567', $audit->changes['after']['phone']);
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_identity_correction_invalidates_old_links_match_and_pending_delivery_but_keeps_lead(): void
    {
        $lead = Lead::create(['first_name' => 'Alex', 'last_name' => 'Taylor', 'email' => 'alex@example.test']);
        $rsvp = $this->rsvp(['lead_id' => $lead->id, 'linked_at' => now()]);
        $oldLink = URL::temporarySignedRoute('education.rsvp.verify', now()->addDay(), ['rsvp' => $rsvp->public_id]);
        app(EducationRsvpWorkflow::class)->queueRsvp($rsvp);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->put(route('education.rsvps.answers', $rsvp), $this->answers($rsvp, ['full_name' => 'Morgan Lee', 'email' => 'morgan@example.test']))->assertSessionHasNoErrors();
        $rsvp->refresh();
        $this->assertSame('morganlee', $rsvp->name_key);
        $this->assertNull($rsvp->verified_at);
        $this->assertNull($rsvp->linked_at);
        $this->assertNull($rsvp->lead_id);
        $this->assertSame('alex@example.test', $lead->fresh()->email);
        $this->assertFalse(EducationOutreach::where('education_rsvp_id', $rsvp->id)->whereNull('cancelled_at')->exists());
        $this->get($oldLink)->assertNotFound();
        $this->assertNull($rsvp->fresh()->verified_at);
    }

    public function test_already_bound_verification_and_contact_requests_cannot_act_after_a_name_correction(): void
    {
        $bound = $this->rsvp();
        $this->actingAs(User::factory()->create(['role' => 'education']))
            ->put(route('education.rsvps.answers', $bound), $this->answers($bound, ['full_name' => 'Morgan Lee']))->assertSessionHasNoErrors();
        foreach (['verify', 'contact'] as $action) {
            try {
                if ($action === 'verify') {
                    app(\App\Http\Controllers\EducationRsvpController::class)->verify(request(), $bound, app(EducationRsvpWorkflow::class));
                } else {
                    $service = app(\App\Services\EducationRsvpContactUpdate::class);
                    $service->save($bound, $service->emailKey($bound), '+639170000000');
                }
                $this->fail('An old bound public identity was accepted.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                $this->assertSame($action === 'verify' ? 404 : 403, $exception->getStatusCode());
            }
        }
        $this->assertNull($bound->fresh()->verified_at);
        $this->assertNull($bound->fresh()->phone);
    }

    public function test_stale_form_and_duplicate_email_are_rejected_without_partial_changes(): void
    {
        $rsvp = $this->rsvp();
        $this->rsvp(['education_visit_id' => $rsvp->education_visit_id, 'email' => 'existing@example.test']);
        $data = $this->answers($rsvp);
        $this->actingAs(User::factory()->create(['role' => 'education']))
            ->putJson(route('education.rsvps.answers', $rsvp), array_replace($data, ['email' => 'Existing@example.test']))->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertFalse($rsvp->fresh()->email_follow_up);
        $rsvp->update(['phone' => '+639170000000']);
        $this->putJson(route('education.rsvps.answers', $rsvp), $data)->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->assertSame('+639170000000', $rsvp->fresh()->phone);
        $this->assertFalse(ActivityLog::where('action', 'education.rsvp.answers_updated')->exists());
    }

    public function test_staff_can_request_one_fresh_verification_email_after_correction(): void
    {
        Mail::fake();
        $rsvp = $this->rsvp();
        $this->actingAs(User::factory()->create(['role' => 'education']))
            ->put(route('education.rsvps.answers', $rsvp), $this->answers($rsvp, ['email' => 'corrected@example.test']))->assertSessionHasNoErrors();
        $rsvp->refresh();
        $data = ['revision' => $rsvp->answersRevision()];
        foreach (range(1, 2) as $attempt) {
            $this->post(route('education.rsvps.confirmation', $rsvp), $data)->assertRedirect()->assertSessionHasNoErrors();
        }
        $delivery = EducationOutreach::whereNull('cancelled_at')->sole();
        $this->assertSame('confirmation', $delivery->kind);
        app(\App\Services\EducationOutreachService::class)->process($delivery);
        Mail::assertSent(\App\Mail\EducationRsvpMessage::class, fn ($mail) => $mail->hasTo('corrected@example.test') && str_contains($mail->render(), $rsvp->public_id));
        $this->assertNotNull($delivery->fresh()->sent_at);
        $this->assertNull($rsvp->fresh()->verified_at);
        $this->assertSame(1, ActivityLog::where('action', 'education.rsvp.confirmation_requested')->count());
        $this->post(route('education.rsvps.confirmation', $rsvp), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('education_outreach', 1);
        $delivery->update(['sent_at' => now()->subDays(31)]);
        $this->post(route('education.rsvps.confirmation', $rsvp), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('education_outreach', 2);
        EducationOutreach::latest('id')->first()->update(['attempts' => 8]);
        $this->post(route('education.rsvps.confirmation', $rsvp), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('education_outreach', 3);
        $this->assertSame(1, EducationOutreach::whereNull('sent_at')->whereNull('cancelled_at')->count());
        $rsvp->update(['phone' => '+639171111111']);
        $this->postJson(route('education.rsvps.confirmation', $rsvp), $data)->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->actingAs(User::factory()->create(['role' => 'sales']))
            ->postJson(route('education.rsvps.confirmation', $rsvp), ['revision' => $rsvp->answersRevision()])->assertForbidden();
    }

    public function test_outreach_revoked_during_rsvp_load_is_not_sent(): void
    {
        Mail::fake();
        $rsvp = $this->rsvp();
        $delivery = app(EducationRsvpWorkflow::class)->receipt('test-race', ['education_rsvp_id' => $rsvp->id, 'channel' => 'mail', 'kind' => 'confirmation']);
        $event = 'eloquent.retrieved: '.EducationRsvp::class;
        \Illuminate\Support\Facades\Event::listen($event, fn () => EducationOutreach::whereKey($delivery->id)->update(['cancelled_at' => now()]));
        try {
            app(\App\Services\EducationOutreachService::class)->process($delivery);
        } finally {
            \Illuminate\Support\Facades\Event::forget($event);
        }
        Mail::assertNothingSent();
        $this->assertSame(0, $delivery->fresh()->attempts);
    }

    public function test_legacy_blank_phone_is_editable_but_all_choices_and_valid_contact_are_required(): void
    {
        $rsvp = $this->rsvp();
        $this->actingAs(User::factory()->create(['role' => 'education']));
        $this->putJson(route('education.rsvps.answers', $rsvp), $this->answers($rsvp, ['full_name' => '---', 'email' => 'invalid']))->assertUnprocessable();
        $this->putJson(route('education.rsvps.answers', $rsvp), ['revision' => $rsvp->answersRevision(), 'full_name' => 'Alex Taylor', 'email' => $rsvp->email])->assertUnprocessable()->assertJsonValidationErrors('email_follow_up');
        $this->put(route('education.rsvps.answers', $rsvp), $this->answers($rsvp))->assertSessionHasNoErrors();
        $this->assertNull($rsvp->fresh()->phone);
    }

    public function test_guests_and_other_departments_cannot_change_answers(): void
    {
        $rsvp = $this->rsvp();
        $this->putJson(route('education.rsvps.answers', $rsvp), $this->answers($rsvp))->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'sales']))
            ->putJson(route('education.rsvps.answers', $rsvp), $this->answers($rsvp))->assertForbidden();
        $this->assertFalse($rsvp->fresh()->email_follow_up);
    }
}
