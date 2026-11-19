<?php

namespace Tests\Feature;

use App\Mail\TemplatedMessage;
use App\Models\EmailAutomationMessage;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Submitting a consultancy agreement for verification fires the Education
 * "Consultancy agreement submitted for verification" automation.
 */
class ConsultancySubmitAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_submit_for_verification_fires_the_automation(): void
    {
        Mail::fake();
        MessageTemplate::create([
            'key' => 'consultancy_submitted_notice', 'department' => 'education', 'name' => 'Submitted',
            'channels' => ['email'], 'email_subject' => 'Received {{first_name}}', 'email_body' => 'We got it.', 'is_active' => true,
        ]);
        EmailAutomationMessage::create([
            'event_key' => 'education.consultancy.submitted', 'recipient' => 'client',
            'template_key' => 'consultancy_submitted_notice', 'channel' => 'email', 'enabled' => true, 'sort_order' => 0,
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $lead = Lead::create(['first_name' => 'Emma', 'last_name' => 'T', 'email' => 'emma@example.com']);

        $this->actingAs($admin)
            ->post("/admin/leads/{$lead->id}/generate/consultancy_std_100", ['currency' => 'php', 'school_enrolment_fee' => 100000])
            ->assertRedirect();

        // It entered verification as pending (no PDF generated at submit).
        $this->assertSame('pending', $lead->refresh()->consultancy_review['status']);
        Mail::assertQueued(TemplatedMessage::class, fn ($m) => $m->hasTo('emma@example.com'));
    }

    public function test_english_submit_automation_excludes_separate_usd_exam_fee(): void
    {
        $this->mock(\App\Services\EmailAutomationService::class)->shouldReceive('fire')->once()
            ->with('education.consultancy.submitted', \Mockery::type(Lead::class), \Mockery::on(fn ($context) => $context['total_amount'] === 14500));
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Client']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post("/admin/leads/{$lead->id}/generate/english_engagement", ['english_fee' => 14500, 'pte_fee' => 240])
            ->assertSessionHasNoErrors();
        $this->assertSame(240, $lead->fresh()->consultancy_review['items']['pte_exam']['amount']);
    }
}
