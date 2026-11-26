<?php

namespace Tests\Feature\Sales;

use App\Mail\TemplatedMessage;
use App\Models\EmailReply;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\MessageLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LeadCommunicationsTest extends TestCase
{
    use RefreshDatabase;

    private function lead(array $a = []): Lead
    {
        return Lead::create(array_merge(['first_name' => 'Co', 'last_name' => 'Lead', 'email' => 'co@example.com'], $a));
    }

    private function logFor(Lead $lead, array $a = []): MessageLog
    {
        return MessageLog::create(array_merge([
            'channel' => 'email', 'recipient_type' => 'lead', 'recipient_id' => $lead->id,
            'recipient_address' => $lead->email, 'subject' => 'Hi', 'body' => 'Body', 'status' => 'queued',
        ], $a));
    }

    public function test_staff_can_see_communications_for_accessible_lead(): void
    {
        $sales = User::factory()->create(['role' => 'sales']);
        $lead = $this->lead();
        $this->logFor($lead, ['subject' => 'Welcome']);

        $this->actingAs($sales)
            ->getJson("/admin/leads/{$lead->id}/communications")
            ->assertOk()
            ->assertJsonPath('data.0.subject', 'Welcome');
    }

    public function test_staff_cannot_see_communications_for_inaccessible_lead(): void
    {
        $sales = User::factory()->create(['role' => 'sales']);
        $case = $this->lead(['is_immigration_case' => true]);
        $this->logFor($case);

        $this->actingAs($sales)->getJson("/admin/leads/{$case->id}/communications")->assertForbidden();
    }

    public function test_feed_returns_the_thread_without_pagination(): void
    {
        $sales = User::factory()->create(['role' => 'sales']);
        $lead = $this->lead();
        foreach (range(1, 60) as $i) {
            $this->logFor($lead, ['subject' => "Msg {$i}"]);
        }

        $res = $this->actingAs($sales)->getJson("/admin/leads/{$lead->id}/communications")->assertOk();

        $this->assertCount(60, $res->json('data'));
        $this->assertNull($res->json('next_page_url'));
        $this->assertTrue($res->json('can_email'));
    }

    public function test_inbound_replies_are_merged_into_the_feed(): void
    {
        $sales = User::factory()->create(['role' => 'sales']);
        $lead = $this->lead();
        $this->logFor($lead, ['subject' => 'Our email']);
        EmailReply::create([
            'lead_id' => $lead->id, 'direction' => 'inbound',
            'from_email' => $lead->email, 'from_name' => 'Co Lead', 'to_email' => 'hello@epathways.ph',
            'subject' => 'Their reply', 'body_text' => 'Thanks!', 'received_at' => now(), 'is_read' => false,
        ]);

        $res = $this->actingAs($sales)->getJson("/admin/leads/{$lead->id}/communications")->assertOk();

        $data = collect($res->json('data'));
        $this->assertTrue($data->contains(fn ($m) => $m['direction'] === 'in' && $m['subject'] === 'Their reply'));
        $this->assertTrue($data->contains(fn ($m) => $m['direction'] === 'out' && $m['subject'] === 'Our email'));
    }

    public function test_can_email_is_false_when_lead_has_no_email(): void
    {
        $sales = User::factory()->create(['role' => 'sales']);
        $lead = $this->lead(['email' => null]);

        $this->actingAs($sales)->getJson("/admin/leads/{$lead->id}/communications")
            ->assertOk()->assertJsonPath('can_email', false);
    }

    public function test_staff_can_reply_and_it_is_logged(): void
    {
        Mail::fake();
        $sales = User::factory()->create(['role' => 'sales']);
        $lead = $this->lead();

        $this->actingAs($sales)
            ->postJson("/admin/leads/{$lead->id}/communications", ['subject' => 'Re: hi', 'body' => '<p>Hello there</p>'])
            ->assertOk()->assertJsonPath('ok', true);

        $this->assertDatabaseHas('message_logs', [
            'recipient_type' => 'lead', 'recipient_id' => $lead->id, 'channel' => 'email', 'subject' => 'Re: hi',
        ]);
    }

    public function test_education_can_cc_recipients_on_lead_and_student_emails(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'education']));

        foreach ([false, true] as $isStudent) {
            Mail::fake();
            $lead = $this->lead(['is_student' => $isStudent]);
            $this->postJson("/admin/leads/{$lead->id}/communications", [
                'body' => 'Hello', 'cc' => ['team@example.com', 'TEAM@example.com', 'other@example.com', $lead->email],
            ])->assertOk();

            Mail::assertQueued(TemplatedMessage::class, fn ($mail) => $mail->hasTo($lead->email)
                && array_map(fn ($address) => $address->address, $mail->envelope()->cc) === ['team@example.com', 'other@example.com']);
        }
    }

    public function test_invalid_cc_and_cc_without_education_access_do_not_queue_mail(): void
    {
        Mail::fake();
        $lead = $this->lead();
        $url = "/admin/leads/{$lead->id}/communications";

        $this->actingAs(User::factory()->create(['role' => 'education']))
            ->postJson($url, ['body' => 'Hello', 'cc' => ['invalid']])
            ->assertUnprocessable()->assertJsonValidationErrors('cc.0');
        $this->actingAs(User::factory()->create(['role' => 'sales']))
            ->postJson($url, ['body' => 'Hello', 'cc' => ['team@example.com']])
            ->assertUnprocessable()->assertJsonValidationErrors('cc');

        Mail::assertNothingQueued();
        $this->assertDatabaseCount('message_logs', 0);
    }

    public function test_reply_is_blocked_when_lead_has_no_email(): void
    {
        $sales = User::factory()->create(['role' => 'sales']);
        $lead = $this->lead(['email' => null]);

        $this->actingAs($sales)
            ->postJson("/admin/leads/{$lead->id}/communications", ['body' => 'Hello'])
            ->assertStatus(422);

        $this->assertDatabaseCount('message_logs', 0);
    }

    public function test_education_can_send_a_local_offer_pdf_without_a_message_body(): void
    {
        Mail::fake();
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'education']);
        $student = $this->lead(['is_student' => true]);

        $this->actingAs($user)
            ->withHeader('Accept', 'application/json')
            ->post("/admin/leads/{$student->id}/communications", [
                'subject' => 'Offer of Place',
                'attachments' => [UploadedFile::fake()->create('Offer of Place.pdf', 100, 'application/pdf')],
            ])->assertOk()->assertJsonPath('ok', true);

        Mail::assertQueued(TemplatedMessage::class, fn ($mail) => $mail->hasTo($student->email)
            && $mail->attachmentFiles[0]['name'] === 'Offer of Place.pdf'
            && Storage::disk('local')->exists($mail->attachmentFiles[0]['path']));
    }

    public function test_education_can_attach_an_approved_student_document_to_a_conversation(): void
    {
        Mail::fake();
        Storage::fake('local');
        Storage::fake('public');
        $user = User::factory()->create(['role' => 'education']);
        $student = $this->lead(['is_student' => true]);
        $approved = $this->offer($student, 'student/offer.pdf', LeadDocument::STATUS_APPROVED);
        $pending = $this->offer($student, 'student/draft.pdf', LeadDocument::STATUS_SUBMITTED);
        $hidden = $this->offer($student, 'student/hidden.pdf', LeadDocument::STATUS_STAFF_SHARED);
        $hidden->update(['checklist_key' => 'custom.hidden']);
        $student->update(['hidden_track_documents' => ['custom.hidden']]);
        $other = $this->offer($this->lead(['is_student' => true]), 'other/offer.pdf', LeadDocument::STATUS_APPROVED);
        // Older documents may still live on public. The queued copy must be private.
        Storage::disk('public')->put($approved->file_path, '%PDF synthetic offer');
        Storage::disk('local')->put($pending->file_path, '%PDF synthetic draft');
        Storage::disk('local')->put($other->file_path, '%PDF other student');
        $url = "/admin/leads/{$student->id}/communications";

        $this->actingAs($user)->getJson($url)
            ->assertOk()
            ->assertJsonPath('can_attach_documents', true)
            ->assertJsonPath('attachment_documents.0.id', $approved->id)
            ->assertJsonPath('attachment_documents.0.status', LeadDocument::STATUS_APPROVED)
            ->assertJsonPath('attachment_documents.0.added_at', $approved->created_at->toIso8601String())
            ->assertJsonMissingPath('attachment_documents.0.file_path')
            ->assertJsonCount(1, 'attachment_documents');

        foreach ([$pending, $hidden, $other] as $blocked) {
            $this->postJson($url, ['body' => 'Your offer', 'document_ids' => [$approved->id, $blocked->id]])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('document_ids');
        }
        Mail::assertNothingQueued();

        $unavailable = $this->offer($student, 'student/unavailable.pdf', LeadDocument::STATUS_APPROVED);
        $this->postJson($url, ['document_ids' => [$approved->id, $unavailable->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('document_ids');
        $this->assertSame([], Storage::disk('local')->allFiles('compose-attachments'));
        Mail::assertNothingQueued();

        $this->postJson($url, ['subject' => 'Your offer', 'document_ids' => [$approved->id]])
            ->assertOk()
            ->assertJsonPath('ok', true);

        Mail::assertQueued(TemplatedMessage::class, fn ($mail) => $mail->hasTo($student->email)
            && $mail->attachmentFiles[0]['name'] === 'Offer of Place.pdf'
            && Storage::disk('local')->get($mail->attachmentFiles[0]['path']) === '%PDF synthetic offer');
    }

    private function offer(Lead $lead, string $path, string $status): LeadDocument
    {
        return $lead->documents()->create([
            'checklist_key' => 'acad.offer_of_place', 'original_name' => 'Offer of Place.pdf',
            'file_path' => $path, 'mime' => 'application/pdf', 'size' => 100,
            'status' => $status, 'source' => LeadDocument::SOURCE_UPLOAD,
        ]);
    }

    public function test_reply_to_inaccessible_lead_is_forbidden(): void
    {
        $sales = User::factory()->create(['role' => 'sales']);
        $case = $this->lead(['is_immigration_case' => true]);

        $this->actingAs($sales)
            ->postJson("/admin/leads/{$case->id}/communications", ['body' => 'Hello'])
            ->assertForbidden();
    }
}
