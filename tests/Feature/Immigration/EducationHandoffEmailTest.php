<?php

namespace Tests\Feature\Immigration;

use App\Mail\TemplatedMessage;
use App\Models\EmailAutomationMessage;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EducationHandoffEmailTest extends TestCase
{
    use RefreshDatabase;

    private function configureHandoff(): void
    {
        foreach (['educ_to_migration' => 'client', 'educ_to_migration_internal' => 'team'] as $key => $recipient) {
            MessageTemplate::create(['key' => $key, 'name' => $key, 'department' => 'immigration', 'channels' => ['email'], 'email_subject' => $key, 'email_body' => 'Synthetic {{first_name}}', 'is_active' => true]);
            EmailAutomationMessage::create(['event_key' => 'immigration.education.handoff', 'recipient' => $recipient, 'template_key' => $key, 'channel' => 'email', 'enabled' => true]);
        }
        User::factory()->create(['role' => 'immigration_manager', 'email' => 'manager@example.test']);
    }

    public function test_stage_handoff_sends_both_named_templates_once(): void
    {
        Mail::fake();
        $this->configureHandoff();
        $student = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Student', 'email' => 'student@example.test', 'is_student' => true]);
        $this->actingAs(User::factory()->create(['role' => 'education']));
        foreach ([1, 2] as $attempt) {
            $this->post("/portal/education/students/{$student->id}/dashboard-field", ['education_stage' => 'Endorsed to Immigration'])->assertSessionHasNoErrors();
        }
        Mail::assertQueued(TemplatedMessage::class, 2);
        Mail::assertQueued(TemplatedMessage::class, fn ($mail) => $mail->subjectLine === 'educ_to_migration' && $mail->hasTo('student@example.test'));
        Mail::assertQueued(TemplatedMessage::class, fn ($mail) => $mail->subjectLine === 'educ_to_migration_internal' && $mail->hasTo('manager@example.test') && ! $mail->hasTo('student@example.test'));
    }

    public function test_move_to_case_from_student_sends_handoff_only_on_first_conversion(): void
    {
        Mail::fake();
        $this->configureHandoff();
        $student = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Student', 'email' => 'student@example.test', 'is_student' => true]);
        $this->actingAs(User::factory()->create(['role' => 'education']));
        $this->post("/admin/leads/{$student->id}/convert-to-case")->assertRedirect();
        $this->post("/admin/leads/{$student->id}/convert-to-case")->assertRedirect();
        Mail::assertQueued(TemplatedMessage::class, 2);
    }
    public function test_external_portal_grants_do_not_allow_converting_another_student(): void
    {
        Mail::fake();
        $this->configureHandoff();
        $student = Lead::create(['first_name' => 'Private', 'last_name' => 'Student', 'email' => 'private@example.test', 'is_student' => true]);
        foreach (['lead', 'agent', 'sub_agent'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'portal_access' => ['education']]));
            $this->post("/admin/leads/{$student->id}/convert-to-case")->assertForbidden();
            $this->post("/portal/education/students/{$student->id}/dashboard-field", ['education_stage' => 'Endorsed to Immigration'])->assertForbidden();
        }
        $this->assertFalse($student->fresh()->is_immigration_case);
        Mail::assertNothingOutgoing();
    }

    public function test_configuration_migration_maps_existing_templates_without_sending_or_overwriting(): void
    {
        Mail::fake();
        foreach (['educ_to_migration', 'educ_to_migration_internal'] as $key) {
            MessageTemplate::create(['key' => $key, 'name' => $key, 'department' => 'immigration', 'channels' => ['email'], 'email_subject' => $key, 'email_body' => 'Synthetic', 'is_active' => true]);
        }
        $path = database_path('migrations/2026_10_21_170000_configure_education_handoff_email.php');
        $this->assertFileExists($path);
        $migration = require $path;
        $migration->up();
        $client = EmailAutomationMessage::where('event_key', 'immigration.education.handoff')->where('recipient', 'client')->firstOrFail();
        $client->update(['enabled' => false]);
        $migration->up();
        $this->assertSame(2, EmailAutomationMessage::where('event_key', 'immigration.education.handoff')->count());
        $this->assertFalse($client->fresh()->enabled);
        $this->assertDatabaseHas('email_automation_messages', ['event_key' => 'immigration.education.handoff', 'recipient' => 'team', 'template_key' => 'educ_to_migration_internal']);
        Mail::assertNothingOutgoing();
    }

}
