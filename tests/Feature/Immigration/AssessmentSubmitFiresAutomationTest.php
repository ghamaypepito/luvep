<?php

namespace Tests\Feature\Immigration;

use App\Mail\TemplatedMessage;
use App\Models\EmailAutomationMessage;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Models\WorkIntake;
use App\Services\EmailAutomationService;
use App\Services\Immigration\PreScreeningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

/**
 * When a client submits a visa assessment on the public site, the configurable
 * "New enquiry captured" automation (immigration.lead.captured) must fire so an
 * admin can notify staff and/or confirm to the client. The intake funnel has no
 * Lead yet, so the event fires against a transient Lead carrying the applicant's
 * contact details + visa type.
 */
class AssessmentSubmitFiresAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_work_intake_notifies_intake_team_then_endorsement_notifies_hendry_once(): void
    {
        Mail::fake();
        MessageTemplate::create([
            'key' => 'work-notice-for-test', 'department' => 'immigration', 'name' => 'Staff notice',
            'is_active' => true, 'channels' => ['email'], 'email_subject' => 'New work assessment',
            'email_body' => '<p>New assessment</p>', 'to_extra' => 'other@example.test',
            'cc' => 'cc@example.test', 'bcc' => 'bcc@example.test',
        ]);
        EmailAutomationMessage::create([
            'event_key' => 'immigration.lead.captured', 'recipient' => 'team',
            'template_key' => 'work-notice-for-test', 'enabled' => true,
        ]);
        $nova = User::factory()->create(['role' => 'immigration_manager', 'email' => 'visa@dimmigration.co']);
        $eireen = User::factory()->create(['role' => 'admin', 'email' => 'eireen@dimmigration.co']);
        $hendry = User::factory()->create(['role' => 'immigration_adviser', 'email' => 'hendry@dimmigration.co']);

        $this->post('/work-interest', [
            'family_name' => 'Works', 'first_name' => 'Applicant',
            'dob' => '1990-01-01', 'phone' => '+64 21 555 0000',
            'email' => 'applicant@example.test', 'declaration_accepted' => true,
        ])->assertSessionHasNoErrors()->assertRedirect();
        Mail::assertQueuedCount(1);
        Mail::assertQueued(TemplatedMessage::class, fn ($message) => $message->hasTo($nova->email)
            && $message->hasTo($eireen->email) && count($message->to) === 2
            && $message->ccList === null && $message->bccList === null);

        $intake = WorkIntake::sole();
        $pre = app(PreScreeningService::class)->ensureFor($intake, $nova);
        $route = "/portal/immigration/pre-screening/{$pre->token}/aewv-endorse";
        $this->actingAs($hendry)->post($route)->assertForbidden();
        $this->actingAs($nova)->post($route)->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($nova)->post($route)->assertSessionHasNoErrors()->assertRedirect();
        Mail::assertQueuedCount(2);
        Mail::assertQueued(TemplatedMessage::class, fn ($message) => $message->hasTo($hendry->email) && count($message->to) === 1 && str_contains($message->subjectLine, 'endorsed')
            && ! str_contains($message->markdownBody, $pre->token));
        $this->assertNotNull($pre->refresh()->aewv_endorsed_at);
        $this->assertSame($nova->id, (int) $pre->aewv_endorsed_by);
        $this->assertNull($pre->converted_lead_id);
        $this->assertSame('Submitted', $intake->fresh()->status);
    }

    public function test_visitor_assessment_submit_fires_lead_captured(): void
    {
        $mock = Mockery::mock(EmailAutomationService::class);
        $mock->shouldReceive('fire')
            ->once()
            ->with(
                'immigration.lead.captured',
                Mockery::on(fn ($lead) => $lead instanceof Lead
                    && $lead->first_name === 'Vera'
                    && $lead->last_name === 'Visitor'          // from family_name
                    && $lead->email === 'vera@example.com'
                    && $lead->inz_visa_type === 'Visitor Visa (GVV)'),
                Mockery::on(fn ($ctx) => is_array($ctx)
                    && ($ctx['visa_type'] ?? null) === 'Visitor Visa (GVV)')
            )
            ->andReturn(false);
        $this->app->instance(EmailAutomationService::class, $mock);

        $this->post('/visitor-interest', [
            'family_name' => 'Visitor',
            'first_name' => 'Vera',
            'dob' => '1990-01-01',
            'phone' => '+64 21 555 0000',
            'email' => 'vera@example.com',
            'declaration_accepted' => true,
        ])->assertRedirect();
    }
}
