<?php

namespace Tests\Feature\Immigration;

use App\Mail\TemplatedMessage;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MedicalRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_medical_request_stores_private_notice_deadline_and_notifies_client(): void
    {
        Mail::fake();
        Storage::fake('local');
        Storage::fake('public');
        $lead = Lead::create(['first_name' => 'Medical', 'last_name' => 'Test', 'email' => 'medical@example.test', 'is_immigration_case' => true, 'immigration_stage' => 'Visa Lodged']);
        $this->actingAs(User::factory()->create(['role' => 'immigration']))
            ->post("/portal/immigration/cases/{$lead->id}/medical", [
                'deadline' => '2027-01-15', 'notify' => 1,
                'documents' => [UploadedFile::fake()->create('inz-notice.pdf', 25, 'application/pdf')],
            ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Request for Medical', $lead->fresh()->immigration_stage);
        $this->assertSame('2027-01-15', $lead->fresh()->medical_deadline->toDateString());
        $this->assertNull($lead->fresh()->rfi_deadline);
        $doc = LeadDocument::where('lead_id', $lead->id)->sole();
        $this->assertSame('medical', $doc->source_variant);
        $this->assertSame(LeadDocument::STATUS_STAFF_SHARED, $doc->status);
        Storage::disk('local')->assertExists($doc->file_path);
        Storage::disk('public')->assertMissing($doc->file_path);
        Mail::assertQueued(TemplatedMessage::class, fn ($mail) => $mail->hasTo($lead->email)
            && $mail->subjectLine === 'Request for Medical'
            && str_contains($mail->markdownBody, '15 Jan 2027')
            && $mail->attachmentFiles[0]['path'] === $doc->file_path);
        Mail::assertQueuedCount(1);
        $client = User::factory()->create(['role' => 'lead', 'lead_id' => $lead->id]);
        $this->actingAs($client)->get('/portal/lead/documents')->assertOk()
            ->assertInertia(fn ($page) => $page->where('lead.medical_deadline', '2027-01-15')
                ->where('shared_by_staff.0.source_variant', 'medical'));
        $this->get("/portal/lead/documents/{$doc->id}/download")->assertOk();
        $other = Lead::create(['first_name' => 'Other', 'last_name' => 'Client']);
        $this->actingAs(User::factory()->create(['role' => 'lead', 'lead_id' => $other->id]))
            ->get("/portal/lead/documents/{$doc->id}/download")->assertForbidden();

    }

    public function test_medical_request_requires_pdf_and_deadline_without_changing_case(): void
    {
        Mail::fake();
        $lead = Lead::create(['first_name' => 'Test', 'last_name' => 'Client', 'is_immigration_case' => true, 'immigration_stage' => 'Visa Lodged']);
        $this->actingAs(User::factory()->create(['role' => 'immigration']))
            ->post("/portal/immigration/cases/{$lead->id}/medical", ['notify' => 1])
            ->assertSessionHasErrors(['deadline', 'documents']);
        $this->assertSame('Visa Lodged', $lead->fresh()->immigration_stage);
        $this->assertDatabaseCount('lead_documents', 0);
        Mail::assertNothingOutgoing();
    }

    public function test_medical_request_can_be_saved_without_email(): void
    {
        Mail::fake();
        Storage::fake('local');
        $lead = Lead::create(['first_name' => 'Test', 'last_name' => 'Client', 'email' => 'medical@example.test', 'is_immigration_case' => true]);
        $this->actingAs(User::factory()->create(['role' => 'immigration']))
            ->post("/portal/immigration/cases/{$lead->id}/medical", ['deadline' => '2027-01-15', 'notify' => 0,
                'documents' => [UploadedFile::fake()->create('inz-notice.pdf', 25, 'application/pdf')],
            ])->assertRedirect()->assertSessionHasNoErrors();
        Mail::assertNothingOutgoing();
    }
    public function test_failed_storage_keeps_stage_unchanged_and_creates_no_document(): void
    {
        Mail::fake();
        $lead = Lead::create(['first_name' => 'Test', 'last_name' => 'Client', 'is_immigration_case' => true, 'immigration_stage' => 'Visa Lodged']);
        Storage::shouldReceive('disk')->with('local')->andThrow(new \RuntimeException('Disk unavailable'));
        $this->actingAs(User::factory()->create(['role' => 'immigration']))
            ->post("/portal/immigration/cases/{$lead->id}/medical", ['deadline' => '2027-01-15', 'notify' => 1,
                'documents' => [UploadedFile::fake()->create('inz-notice.pdf', 25, 'application/pdf')],
            ])->assertStatus(500);
        $this->assertSame('Visa Lodged', $lead->fresh()->immigration_stage);
        $this->assertDatabaseCount('lead_documents', 0);
        Mail::assertNothingOutgoing();
    }

    public function test_failed_notification_can_retry_without_duplicating_documents_or_history(): void
    {
        Mail::fake();
        Storage::fake('local');
        $template = \App\Models\MessageTemplate::where('key', 'request_for_medical')->firstOrFail();
        $template->update(['is_active' => false]);
        $lead = Lead::create(['first_name' => 'Test', 'last_name' => 'Client', 'email' => 'medical@example.test', 'is_immigration_case' => true]);
        $this->actingAs(User::factory()->create(['role' => 'immigration']))
            ->post("/portal/immigration/cases/{$lead->id}/medical", ['deadline' => '2027-01-15', 'notify' => 1,
                'documents' => [UploadedFile::fake()->create('inz-notice.pdf', 25, 'application/pdf')],
            ])->assertSessionHasErrors('notification');
        $history = $lead->fresh()->stage_history;
        $this->assertSame('Request for Medical', $lead->fresh()->immigration_stage);
        Mail::assertNothingOutgoing();
        $template->update(['is_active' => true]);
        $this->post("/portal/immigration/cases/{$lead->id}/medical", ['retry_notification' => 1])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($history, $lead->fresh()->stage_history);
        $this->assertDatabaseCount('lead_documents', 1);
        Mail::assertQueuedCount(1);
    }

    public function test_generic_stage_and_edit_cannot_skip_required_medical_notice(): void
    {
        $lead = Lead::create(['first_name' => 'Test', 'last_name' => 'Client', 'is_immigration_case' => true, 'immigration_stage' => 'Visa Lodged']);
        $this->actingAs(User::factory()->create(['role' => 'immigration']));
        $this->post("/portal/immigration/cases/{$lead->id}/stage", ['immigration_stage' => 'Request for Medical'])
            ->assertSessionHasErrors('immigration_stage');
        $this->post("/portal/immigration/cases/{$lead->id}", ['first_name' => 'Test', 'last_name' => 'Client', 'immigration_stage' => 'Request for Medical'])
            ->assertSessionHasErrors('immigration_stage');
        $this->assertSame('Visa Lodged', $lead->fresh()->immigration_stage);
    }

    public function test_external_roles_cannot_send_medical_requests_even_with_portal_access(): void
    {
        Mail::fake();
        foreach (['lead', 'agent', 'sub_agent'] as $role) {
            $user = User::factory()->create(['role' => $role, 'portal_access' => ['immigration']]);
            $lead = Lead::create(['first_name' => 'Test', 'last_name' => 'Client', 'is_immigration_case' => true, 'agent_id' => $user->id]);
            $this->actingAs($user)->post("/portal/immigration/cases/{$lead->id}/medical", ['notify' => 1])->assertForbidden();
            $this->post("/portal/immigration/cases/{$lead->id}/medical", ['retry_notification' => 1])->assertForbidden();
            $this->assertNull($lead->fresh()->immigration_stage);
        }
        Mail::assertNothingOutgoing();
    }

    public function test_document_database_failure_removes_stored_file_and_rolls_back_stage(): void
    {
        Mail::fake();
        Storage::fake('local');
        $lead = Lead::create(['first_name' => 'Test', 'last_name' => 'Client', 'is_immigration_case' => true, 'immigration_stage' => 'Visa Lodged']);
        \Illuminate\Support\Facades\Event::listen('eloquent.creating: '.LeadDocument::class, function () {
            throw new \RuntimeException('Document write failed');
        });
        try {
            $this->actingAs(User::factory()->create(['role' => 'immigration']))
                ->post("/portal/immigration/cases/{$lead->id}/medical", ['deadline' => '2027-01-15', 'notify' => 1,
                    'documents' => [UploadedFile::fake()->create('inz-notice.pdf', 25, 'application/pdf')],
                ])->assertStatus(500);
        } finally {
            \Illuminate\Support\Facades\Event::forget('eloquent.creating: '.LeadDocument::class);
        }
        $this->assertSame('Visa Lodged', $lead->fresh()->immigration_stage);
        $this->assertDatabaseCount('lead_documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        Mail::assertNothingOutgoing();
    }

}
