<?php

namespace Tests\Feature\Immigration;

use App\Mail\TemplatedMessage;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use App\Models\VisaType;
use App\Services\CommunicationService;
use App\Services\EmailAutomationService;
use App\Services\Immigration\EngagementDocumentGenerator;
use App\Services\Immigration\StaffEngagementSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

class StaffEngagementSignatureTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $adviser;

    private User $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Mail::fake();
        Notification::fake();
        $this->staff = User::factory()->create(['role' => 'immigration_manager', 'name' => 'Synthetic Uploader']);
        $this->adviser = User::factory()->create(['role' => 'immigration_adviser', 'iaa_licence_number' => 'TEST123', 'iaa_licence_expiry' => now()->addYear()]);
        $this->team = User::factory()->create(['role' => 'immigration_manager']);
        $this->actingAs($this->staff);
        $this->mock(EmailAutomationService::class)->shouldNotReceive('fire');
    }

    private function document(string $type = 'written_agreement'): LeadDocument
    {
        VisaType::firstOrCreate(['name' => 'Synthetic Signature Visa'], ['code' => 'SYNTHETIC-SIGNATURE', 'professional_fees' => 1234.56, 'inz_application_fee' => 100]);
        $lead = Lead::create(['first_name' => 'Issued', 'last_name' => 'Synthetic', 'email' => 'synthetic@example.test',
            'is_immigration_case' => true, 'immigration_stage' => 'Agreement Sent', 'inz_visa_type' => 'Synthetic Signature Visa', 'principal_fee_override' => 1234.56]);
        $snapshot = app(EngagementDocumentGenerator::class)->snapshot($lead, $type, ['signer_id' => $this->adviser->id, 'service_fee' => 333.25]);
        $path = 'lead-documents/'.$lead->id.'/original.pdf';
        Storage::disk('local')->put($path, '%PDF-original-issued');

        return LeadDocument::create(['lead_id' => $lead->id, 'original_name' => 'Issued agreement.pdf', 'file_path' => $path,
            'source_variant' => 'engagement:'.$type, 'status' => 'Submitted', 'mime' => 'application/pdf', 'size' => 20,
            'engagement_signer_id' => $this->adviser->id, 'notes' => ['engagement_pack_id' => (string) Str::uuid(),
                'engagement_sent_at' => now()->toIso8601String(), 'engagement_payload' => $snapshot]]);
    }

    private function signature(): UploadedFile
    {
        $image = imagecreatetruecolor(400, 100);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        $ink = imagecolorallocate($image, 20, 30, 40);
        imagestring($image, 5, 50, 40, 'SYNTHETIC TEST SIGNATURE', $ink);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return UploadedFile::fake()->createWithContent('signature.png', $bytes);
    }

    private function data(bool $client = false, bool $internal = false): array
    {
        return ['signature_image' => $this->signature(), 'signer_name' => 'Issued Synthetic', 'authorized' => true,
            'notify_client' => $client, 'notify_internal' => $internal];
    }

    private function url(LeadDocument $doc): string
    {
        return '/portal/immigration/engagement/documents/'.$doc->id.'/signature';
    }

    public function test_all_four_notification_combinations_are_independent_and_do_not_fire_generic_automation(): void
    {
        foreach ([[false, false], [true, false], [false, true], [true, true]] as [$client, $internal]) {
            Mail::fake();
            Notification::fake();
            $doc = $this->document();
            $this->postJson($this->url($doc), $this->data($client, $internal))->assertOk()
                ->assertJsonPath('notifications.client', $client ? 'queued' : 'not_requested')
                ->assertJsonPath('notifications.internal', $internal ? 'queued' : 'not_requested');
            if (! $client && ! $internal) {
                Mail::assertNothingOutgoing();
            } else {
                Mail::assertQueued(TemplatedMessage::class, ($client ? 1 : 0) + ($internal ? 2 : 0));
                $client ? Mail::assertQueued(TemplatedMessage::class, fn ($mail) => $mail->hasTo('synthetic@example.test'))
                    : Mail::assertNotQueued(TemplatedMessage::class, fn ($mail) => $mail->hasTo('synthetic@example.test'));
                foreach ([$this->staff, $this->team] as $manager) {
                    if ($internal) {
                        Mail::assertQueued(TemplatedMessage::class, fn ($mail) => $mail->hasTo(strtolower($manager->email))
                            && str_contains($mail->subjectLine, 'Immigration management'));
                    } else {
                        Mail::assertNotQueued(TemplatedMessage::class, fn ($mail) => $mail->hasTo($manager->email));
                    }
                }
            }
            Notification::assertNothingSent();
            $this->assertSame('email', $doc->fresh()->notes['staff_signature_upload']['internal_notification_channel']);
            $this->assertNotNull($doc->fresh()->client_signed_at);
            $this->assertSame('Agreement Signed', $doc->lead->fresh()->immigration_stage);
        }
    }

    public function test_management_email_routing_excludes_advisers_admins_inactive_and_invalid_accounts(): void
    {
        User::factory()->create(['role' => 'admin']);
        User::factory()->create(['role' => 'immigration_manager', 'is_active' => false]);
        User::factory()->create(['role' => 'immigration_manager', 'email' => 'not-an-email']);
        User::factory()->create(['role' => 'education', 'portal_access' => ['immigration']]);
        $ids = app(StaffEngagementSignature::class)->internalRecipients()->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$this->staff->id, $this->team->id], $ids);
        $doc = $this->document();
        $this->postJson($this->url($doc), $this->data(false, true))->assertOk()->assertJsonPath('notifications.internal', 'queued');
        Mail::assertQueued(TemplatedMessage::class, 2);
        Mail::assertNotQueued(TemplatedMessage::class, fn ($mail) => $mail->hasTo($this->adviser->email));
        Notification::assertNothingSent();
        $this->assertSame([$this->staff->id, $this->team->id], $doc->fresh()->notes['staff_signature_upload']['internal_recipient_user_ids']);
    }

    public function test_partial_management_email_failure_keeps_signature_and_continues_other_recipients(): void
    {
        $doc = $this->document();
        $this->mock(CommunicationService::class)->shouldReceive('sendComposedEmail')->twice()->andReturn(
            new \App\Models\MessageLog(['status' => 'failed']), new \App\Models\MessageLog(['status' => 'queued'])
        );
        $this->postJson($this->url($doc), $this->data(false, true))->assertOk()
            ->assertJsonPath('notifications.client', 'not_requested')->assertJsonPath('notifications.internal', 'failed');
        $doc->refresh();
        $this->assertNotNull($doc->client_signed_at);
        $this->assertSame('failed', $doc->notes['staff_signature_upload']['internal_email_results'][$this->staff->id]);
        $this->assertSame('queued', $doc->notes['staff_signature_upload']['internal_email_results'][$this->team->id]);
        Notification::assertNothingSent();
        $this->postJson($this->url($doc), $this->data(false, true))->assertConflict();
    }

    public function test_original_snapshot_pdf_and_staff_audit_are_preserved_and_duplicate_upload_is_rejected(): void
    {
        $doc = $this->document();
        $originalPath = $doc->file_path;
        $snapshot = $doc->notes['engagement_payload'];
        $doc->lead->update(['first_name' => 'Changed', 'principal_fee_override' => 9999]);
        $this->postJson($this->url($doc), $this->data())->assertOk();
        $doc->refresh();
        $this->assertNotSame($originalPath, $doc->file_path);
        $this->assertSame('%PDF-original-issued', Storage::disk('local')->get($originalPath));
        $this->assertSame($snapshot, $doc->notes['engagement_payload']);
        $this->get('/portal/immigration/cases/engagement')->assertOk()->assertInertia(fn ($page) => $page
            ->where('canUploadSignature', true)->where('internalSignatureNotificationsReady', true)
            ->where('generated.0.signature_uploaded_by', 'Synthetic Uploader')
            ->where('generated.0.audit.signature_uploaded_by', 'Synthetic Uploader'));

        $this->assertSame($this->staff->id, $doc->notes['staff_signature_upload']['uploaded_by']);
        $this->assertSame('staff_upload', $doc->notes['staff_signature_upload']['method']);
        $this->assertTrue($doc->notes['staff_signature_upload']['authorization_confirmed']);
        Storage::disk('local')->assertExists($doc->client_signature_path);
        $pdf = Storage::disk('local')->get($doc->file_path);
        $text = (new Parser)->parseContent($pdf)->getText();
        $this->assertStringContainsString('Issued Synthetic', $text);
        $this->assertStringNotContainsString('Changed Synthetic', $text);
        $this->assertStringContainsString('1,234.56', $text);
        $this->assertStringNotContainsString('9,999.00', $text);
        if (getenv('STAFF_SIGNATURE_PDF_SAMPLE')) {
            file_put_contents(getenv('STAFF_SIGNATURE_PDF_SAMPLE'), $pdf);
        }
        $files = Storage::disk('local')->allFiles();
        $this->postJson($this->url($doc), $this->data(true, true))->assertConflict();
        $this->assertSame($files, Storage::disk('local')->allFiles());
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
        $this->assertSame($pdf, Storage::disk('local')->get($doc->fresh()->file_path));
    }

    public function test_drafts_legacy_without_snapshot_rejected_documents_and_nonagreements_are_blocked(): void
    {
        foreach (['draft', 'legacy', 'rejected', 'invoice'] as $kind) {
            $doc = $this->document();
            $notes = $doc->notes;
            if ($kind === 'draft') {
                unset($notes['engagement_sent_at']);
            }
            if ($kind === 'legacy') {
                unset($notes['engagement_payload']);
            }
            $doc->update(['notes' => $notes, 'status' => $kind === 'rejected' ? 'Rejected' : 'Submitted', 'source_variant' => $kind === 'invoice' ? 'invoice' : $doc->source_variant]);
            $this->postJson($this->url($doc), $this->data())->assertUnprocessable();
            $this->assertNull($doc->fresh()->client_signed_at);
        }
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public function test_authorization_image_validation_and_missing_management_emails_fail_before_saving(): void
    {
        $doc = $this->document();
        foreach ([['authorized' => false], ['signature_image' => UploadedFile::fake()->create('not-image.pdf', 2)], ['signature_image' => UploadedFile::fake()->create('large.png', 2049)], ['signer_name' => '']] as $invalid) {
            $this->postJson($this->url($doc), array_replace($this->data(), $invalid))->assertUnprocessable();
        }
        $this->staff->update(['role' => 'immigration']);
        $this->team->update(['is_active' => false]);
        $this->postJson($this->url($doc), $this->data(false, true))->assertUnprocessable()->assertJsonValidationErrors('notify_internal');
        $doc->lead->update(['email' => null]);
        $this->postJson($this->url($doc), $this->data(true))->assertUnprocessable()->assertJsonValidationErrors('notify_client');
        $this->assertNull($doc->fresh()->client_signed_at);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_external_and_unrelated_department_grants_cannot_upload_signatures(): void
    {
        $doc = $this->document();
        foreach (['lead', 'agent', 'education', 'finance'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'portal_access' => ['immigration']]))
                ->postJson($this->url($doc), $this->data())->assertForbidden();
        }
        $this->assertNull($doc->fresh()->client_signed_at);
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public function test_signed_upload_does_not_downgrade_lodged_case_and_initial_consultation_is_supported(): void
    {
        $doc = $this->document();
        $doc->lead->update(['immigration_stage' => 'Visa Lodged']);
        $this->postJson($this->url($doc), $this->data())->assertOk();
        $this->assertSame('Visa Lodged', $doc->lead->fresh()->immigration_stage);
        foreach (['initial_consultation', 'oia'] as $type) {
            $agreement = $this->document($type);
            $this->postJson($this->url($agreement), $this->data())->assertOk();
            $this->assertNotNull($agreement->fresh()->client_signed_at);
            $this->assertSame('Agreement Sent', $agreement->lead->fresh()->immigration_stage);
        }
    }

    public function test_delivery_audit_does_not_overwrite_notes_added_during_notification(): void
    {
        $doc = $this->document();
        $this->mock(CommunicationService::class)->shouldReceive('sendComposedEmail')->once()->andReturnUsing(function () use ($doc) {
            $fresh = $doc->fresh();
            $notes = $fresh->notes;
            $notes['concurrent_staff_note'] = 'Retain this unrelated update';
            $fresh->update(['notes' => $notes]);

            return new \App\Models\MessageLog(['status' => 'queued']);
        });
        $this->postJson($this->url($doc), $this->data(true))->assertOk();
        $notes = $doc->fresh()->notes;
        $this->assertSame('Retain this unrelated update', $notes['concurrent_staff_note']);
        $this->assertSame('queued', $notes['staff_signature_upload']['notification_result']['client']);
    }

    public function test_notification_failure_does_not_undo_saved_signature_or_allow_a_second_upload(): void
    {
        $doc = $this->document();
        $this->mock(CommunicationService::class)->shouldReceive('sendComposedEmail')->once()->andThrow(new \RuntimeException('Synthetic mail outage'));
        $this->postJson($this->url($doc), $this->data(true))->assertOk()->assertJsonPath('notifications.client', 'failed');
        $this->assertNotNull($doc->fresh()->client_signed_at);
        $this->assertSame('failed', $doc->fresh()->notes['staff_signature_upload']['notification_result']['client']);
        $this->postJson($this->url($doc), $this->data(true))->assertConflict();
    }

    public function test_partial_storage_failure_cleans_up_the_signature_without_changing_the_issued_document(): void
    {
        $doc = $this->document();
        $original = $doc->fresh()->getRawOriginal();
        $disk = Storage::disk('local');
        $files = $disk->allFiles();
        $proxy = \Mockery::mock($disk);
        $proxy->shouldReceive('put')->andReturnUsing(fn ($path, $contents) => str_contains($path, '/signed-engagement-') ? false : $disk->put($path, $contents));
        Storage::shouldReceive('disk')->with('local')->andReturn($proxy);
        $this->postJson($this->url($doc), $this->data())->assertServerError();
        $this->assertSame($original, $doc->fresh()->getRawOriginal());
        $this->assertSame($files, $disk->allFiles());
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public function test_pdf_failure_keeps_the_original_document_unsigned_without_orphaned_files(): void
    {
        $doc = $this->document();
        $original = $doc->fresh()->getRawOriginal();
        $files = Storage::disk('local')->allFiles();
        $this->mock(EngagementDocumentGenerator::class)->shouldReceive('pdfBinary')->once()->andThrow(new \RuntimeException('Synthetic PDF failure'));
        $this->postJson($this->url($doc), $this->data())->assertServerError();
        $this->assertSame($original, $doc->fresh()->getRawOriginal());
        $this->assertSame($files, Storage::disk('local')->allFiles());
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }
}
