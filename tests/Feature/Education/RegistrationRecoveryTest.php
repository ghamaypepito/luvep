<?php

namespace Tests\Feature\Education;

use App\Models\{Lead, LeadDocument, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Bus, Http, Mail, Notification, Storage};
use Tests\TestCase;

class RegistrationRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Bus::fake();
        Mail::fake();
        Notification::fake();
        Http::preventStrayRequests();
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_returning_registration_keeps_old_uploads_and_staff_metadata_and_appends_new_files(): void
    {
        $payload = ['first_name' => 'Repeat', 'last_name' => 'Applicant', 'email' => 'repeat@example.test',
            'phone' => '123456789', 'terms_accepted' => true, 'declaration_accepted' => true];
        $lead = Lead::create(array_intersect_key($payload, array_flip(['first_name', 'last_name', 'email', 'phone'])) + [
            'education_notes' => ['uploaded_files' => ['cv' => ['enrolment-docs/old.pdf']],
                'actual_course_started_on' => '2026-10-01', 'advisor_question' => 'Earlier question', 'high_school_institution' => 'Earlier school'],
        ]);
        Storage::disk('public')->put('enrolment-docs/old.pdf', 'old content');
        $this->post('/register', $payload)->assertSessionHas('success')->assertSessionHasNoErrors();
        $this->assertSame($lead->id, Lead::sole()->id);
        $notes = $lead->fresh()->education_notes;
        $this->assertSame('2026-10-01', $notes['actual_course_started_on']);
        $this->assertSame('Earlier school', $notes['high_school_institution']);
        $this->assertSame('Earlier question', $notes['advisor_question']);
        $this->post('/register', $payload + ['advisor_question' => 'Updated question',
            'cv_files' => [UploadedFile::fake()->create('new.pdf', 2, 'application/pdf')]])
            ->assertSessionHas('success')->assertSessionHasNoErrors();
        $notes = $lead->fresh()->education_notes;
        $this->assertCount(2, $notes['uploaded_files']['cv']);
        $this->assertContains('enrolment-docs/old.pdf', $notes['uploaded_files']['cv']);
        $this->assertSame('Updated question', $notes['advisor_question']);
        $this->assertSame('2026-10-01', $notes['actual_course_started_on']);
        Storage::disk('local')->assertExists(LeadDocument::sole()->file_path);
        Storage::disk('public')->assertExists('enrolment-docs/old.pdf');
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_second_document_failure_rolls_back_every_record_and_new_file_then_retry_creates_once(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'education']));
        $files = fn () => [UploadedFile::fake()->create('one.pdf', 2, 'application/pdf'), UploadedFile::fake()->create('two.pdf', 2, 'application/pdf')];
        $count = 0;
        $listener = function () use (&$count) { if (++$count === 2) throw new \RuntimeException('Synthetic second document failure'); };
        LeadDocument::creating($listener);
        $payload = ['first_name' => 'Manual', 'program_offered' => 'Synthetic degree', 'pre_screened_by' => 'Staff'];
        $this->post('/portal/education/leads', $payload + ['cv_files' => $files()])->assertSessionHas('error');
        foreach (['leads', 'lead_documents', 'lead_study_plans', 'lead_notes'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->post('/portal/education/leads', $payload + ['cv_files' => $files()])->assertSessionHas('success');
        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseCount('lead_documents', 2);
        $this->assertCount(2, Storage::disk('local')->allFiles());
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }
}
