<?php

namespace Tests\Feature\Portal;

use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use App\Services\Immigration\EngagementDocumentGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LeadPortalAgreementSigningTest extends TestCase
{
    use RefreshDatabase;

    private static ?string $issuedPdf = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Mail::fake();
        Notification::fake();

    }

    private function fixture(): array
    {
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Client', 'is_immigration_case' => true]);
        $user = User::factory()->create(['role' => 'lead', 'lead_id' => $lead->id]);
        $path = 'lead-documents/'.$lead->id.'/written.pdf';
        self::$issuedPdf ??= \Barryvdh\DomPDF\Facade\Pdf::loadHTML('<h1>Synthetic issued written agreement</h1><p>Original approved terms.</p>')->setPaper('a4')->output();
        Storage::disk('local')->put($path, self::$issuedPdf);
        $doc = LeadDocument::create(['lead_id' => $lead->id, 'original_name' => 'Written Agreement.pdf',
            'file_path' => $path, 'mime' => 'application/pdf', 'source' => LeadDocument::SOURCE_GENERATED,
            'source_variant' => 'engagement:written_agreement', 'status' => LeadDocument::STATUS_SUBMITTED]);
        $response = $this->actingAs($user)->get('/portal/lead/tracker')->assertOk();
        $row = collect($response->inertiaProps()['shared_documents'])->firstWhere('id', $doc->id);

        return [$lead, $user, $doc, $row];
    }

    public function test_signed_in_immigration_client_can_load_the_preview_url_supplied_by_the_portal(): void
    {
        [, , , $row] = $this->fixture();
        $this->get($row['preview_url'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_signed_in_immigration_client_can_submit_to_the_sign_url_supplied_by_the_portal(): void
    {
        [, , $doc, $row] = $this->fixture();
        $this->from('/portal/lead/tracker')->post($row['sign_url'], $this->signature())->assertRedirect('/portal/lead/tracker')->assertSessionHasNoErrors();
        $this->assertNotNull($doc->fresh()->client_signed_at);
    }

    private function signature(): array
    {
        $image = imagecreatetruecolor(200, 60);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagestring($image, 5, 15, 20, 'SYNTHETIC', imagecolorallocate($image, 0, 0, 0));
        ob_start();
        imagepng($image);
        $binary = ob_get_clean();
        imagedestroy($image);

        $doc = LeadDocument::latest('id')->firstOrFail();

        return ['acknowledged' => 1, 'issued_agreement_hash' => \App\Services\IssuedClientAgreement::checksum($doc), 'signature_data' => 'data:image/png;base64,'.base64_encode($binary)];
    }

    public function test_public_immigration_tracker_stays_blocked_and_the_portal_works_when_public_tracker_is_off(): void
    {
        [$lead, , $doc, $row] = $this->fixture();
        \App\Models\Setting::set('tracker.enabled', false, 'bool');
        $this->get('/track/'.$lead->tracking_code.'/documents/'.$doc->id.'/preview')->assertNotFound();
        $this->post('/track/'.$lead->tracking_code.'/documents/'.$doc->id.'/sign', $this->signature())->assertNotFound();
        $this->get($row['preview_url'])->assertOk();
        $this->post($row['sign_url'], $this->signature())->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull($doc->fresh()->client_signed_at);
    }

    public function test_foreign_hidden_unshared_and_nonagreement_documents_cannot_be_previewed_or_signed(): void
    {
        [$lead, $user, $doc, $row] = $this->fixture();
        $other = Lead::create(['first_name' => 'Other', 'last_name' => 'Synthetic']);
        $foreignUser = User::factory()->create(['role' => 'lead', 'lead_id' => $other->id]);
        $this->actingAs($foreignUser)->get($row['preview_url'])->assertNotFound();
        $this->post($row['sign_url'], $this->signature())->assertNotFound();
        $this->actingAs($user);
        $doc->update(['checklist_key' => 'synthetic.hidden']);
        $lead->update(['hidden_track_documents' => ['synthetic.hidden']]);
        $this->get($row['preview_url'])->assertNotFound();
        $this->post($row['sign_url'], $this->signature())->assertNotFound();
        $lead->update(['hidden_track_documents' => []]);
        $doc->update(['source' => LeadDocument::SOURCE_UPLOAD]);
        $this->get($row['preview_url'])->assertNotFound();
        $this->post($row['sign_url'], $this->signature())->assertNotFound();
        $doc->update(['source' => LeadDocument::SOURCE_GENERATED, 'source_variant' => 'engagement:code_of_conduct']);
        $this->get($row['preview_url'])->assertForbidden();
        $this->post($row['sign_url'], $this->signature())->assertForbidden();
        $this->assertNull($doc->fresh()->client_signed_at);
        $this->assertSame(self::$issuedPdf, Storage::disk('local')->get($doc->file_path));
    }

    public function test_terms_signature_validation_and_duplicate_submission_preserve_the_first_signature(): void
    {
        [, , $doc, $row] = $this->fixture();
        $this->postJson($row['sign_url'], ['signature_data' => $this->signature()['signature_data']])->assertUnprocessable()->assertJsonValidationErrors('acknowledged');
        $this->postJson($row['sign_url'], ['acknowledged' => 1, 'issued_agreement_hash' => $row['issued_agreement_hash']])->assertUnprocessable()->assertJsonValidationErrors('signature_data');
        $this->assertNull($doc->fresh()->client_signed_at);
        $this->post($row['sign_url'], $this->signature())->assertRedirect()->assertSessionHasNoErrors();
        $before = $doc->refresh()->getAttributes();
        $pdf = Storage::disk('local')->get($doc->file_path);
        $signature = Storage::disk('local')->get($doc->client_signature_path);
        $this->post($row['sign_url'], $this->signature())->assertStatus(409);
        $this->assertSame($before, $doc->fresh()->getAttributes());
        $this->assertSame($pdf, Storage::disk('local')->get($doc->file_path));
        $this->assertSame($signature, Storage::disk('local')->get($doc->client_signature_path));
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public function test_guest_and_staff_preview_cannot_sign_and_signed_state_is_returned_to_the_portal(): void
    {
        [, $user, $doc, $row] = $this->fixture();
        auth()->logout();
        $this->get($row['preview_url'])->assertRedirect('/login');
        $this->post($row['sign_url'], $this->signature())->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['role' => 'super_admin']))->post($row['sign_url'], $this->signature())->assertRedirect()->assertSessionHas('error');
        $this->assertNull($doc->fresh()->client_signed_at);
        $this->actingAs($user)->post($row['sign_url'], $this->signature())->assertRedirect()->assertSessionHasNoErrors();
        $response = $this->get('/portal/lead/tracker')->assertOk();
        $signed = collect($response->inertiaProps()['shared_documents'])->firstWhere('id', $doc->id);
        $this->assertTrue($signed['signed']);
        $this->assertSame('Synthetic Client', $signed['signer_name']);
        $this->assertNotNull($doc->fresh()->client_signer_ip);
    }

    public function test_real_renderer_preserves_issued_details_and_persists_a_readable_signed_pdf(): void
    {
        [$lead, $user, $doc, $row] = $this->fixture();
        $generator = new EngagementDocumentGenerator;
        $this->app->instance(EngagementDocumentGenerator::class, $generator);
        $snapshot = $generator->snapshot($lead, 'written_agreement', []);
        $doc->update(['notes' => ['engagement_payload' => $snapshot]]);
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        $lead->update(['first_name' => 'ChangedAfterIssue']);
        $this->actingAs($user);
        $this->get($row['preview_url'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->post($row['sign_url'], $this->signature())->assertRedirect()->assertSessionHasNoErrors();
        $pdf = Storage::disk('local')->get($doc->refresh()->file_path);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $text = (new \Smalot\PdfParser\Parser)->parseContent($pdf)->getText();
        $this->assertStringContainsString('Synthetic', $text);
        $pages = (new \Smalot\PdfParser\Parser)->parseContent($pdf)->getPages();
        $this->assertStringNotContainsString('ChangedAfterIssue', $pages[0]->getText());
        $this->assertStringContainsString('Client signature confirmation', end($pages)->getText());
        $this->assertNotNull($doc->client_signed_at);
        $this->assertSame(1, LeadDocument::count());
    }

    public function test_renderer_failure_does_not_change_the_record_or_leave_signature_files(): void
    {
        [, , $doc, $row] = $this->fixture();
        \Barryvdh\DomPDF\Facade\Pdf::shouldReceive('loadView')->once()->andThrow(new \RuntimeException('Synthetic render failure'));
        $before = $doc->refresh()->getAttributes();
        $files = Storage::disk('local')->allFiles();
        $this->post($row['sign_url'], $this->signature())->assertRedirect()->assertSessionHasErrors('error');
        $this->assertSame($before, $doc->fresh()->getAttributes());
        $this->assertSame(self::$issuedPdf, Storage::disk('local')->get($doc->file_path));
        $this->assertSame($files, Storage::disk('local')->allFiles());
    }

    public function test_client_upload_edit_and_delete_paths_cannot_replace_issued_written_agreements(): void
    {
        foreach ([false, true] as $signed) {
            [$lead, , $doc, $row] = $this->fixture();
            if ($signed) {
                $this->post($row['sign_url'], $this->signature())->assertRedirect()->assertSessionHasNoErrors();
            }
            $lead->forceFill(['is_immigration_case' => false])->saveQuietly();
            $before = $doc->refresh()->getAttributes();
            $pdf = Storage::disk('local')->get($doc->file_path);
            $files = Storage::disk('local')->allFiles();
            foreach (['/portal/lead/documents/'.$doc->id.'/replace', '/track/'.$lead->tracking_code.'/document/'.$doc->id] as $url) {
                $this->post($url, ['file' => \Illuminate\Http\UploadedFile::fake()->create('replacement.pdf', 10, 'application/pdf')])->assertStatus(409);
            }
            foreach (['/portal/lead/documents/'.$doc->id, '/track/'.$lead->tracking_code.'/document/'.$doc->id] as $url) {
                $this->delete($url)->assertRedirect()->assertSessionHas('error');
            }
            $this->assertSame($before, $doc->fresh()->getAttributes());
            $this->assertSame($pdf, Storage::disk('local')->get($doc->file_path));
            $this->assertSame($files, Storage::disk('local')->allFiles());
        }
    }

    public function test_review_guards_reject_base64_that_is_not_a_signature_image(): void
    {
        [, , $doc, $row] = $this->fixture();
        $this->postJson($row['sign_url'], [...$this->signature(), 'signature_data' => 'data:image/png;base64,'.base64_encode('not an image')])->assertUnprocessable()->assertJsonValidationErrors('signature_data');
        $this->assertNull($doc->fresh()->client_signed_at);
    }

    public function test_review_guards_hide_unsent_modern_drafts_and_block_direct_delivery_and_signing(): void
    {
        [, , $doc, $row] = $this->fixture();
        $doc->update(['notes' => ['engagement_pack_id' => 'synthetic-draft']]);
        $response = $this->get('/portal/lead/tracker')->assertOk();
        $this->assertNull(collect($response->inertiaProps()['shared_documents'])->firstWhere('id', $doc->id));
        $this->get($row['preview_url'])->assertNotFound();
        $this->get($row['view_url'])->assertNotFound();
        $this->post($row['sign_url'], $this->signature())->assertNotFound();
    }

    public function test_review_guards_do_not_report_success_when_signature_storage_returns_false(): void
    {
        [, , $doc, $row] = $this->fixture();
        $disk = Storage::disk('local');
        $failing = \Mockery::mock($disk)->makePartial();
        $failing->shouldReceive('put')->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($failing);
        $before = $doc->refresh()->getAttributes();
        $this->post($row['sign_url'], $this->signature())->assertRedirect()->assertSessionHasErrors('error');
        $this->assertSame($before, $doc->fresh()->getAttributes());
        $this->assertSame(self::$issuedPdf, $disk->get($doc->file_path));
    }

    public function test_database_failure_rolls_back_signature_metadata_and_cleans_new_files(): void
    {
        [, , $doc, $row] = $this->fixture();
        $before = $doc->refresh()->getAttributes();
        $files = Storage::disk('local')->allFiles();
        \Illuminate\Support\Facades\Event::listen('eloquent.updated: '.LeadDocument::class, function () {
            throw new \RuntimeException('Synthetic database failure after save');
        });
        $this->post($row['sign_url'], $this->signature())->assertRedirect()->assertSessionHasErrors('error');
        $this->assertSame($before, $doc->fresh()->getAttributes());
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertSame(self::$issuedPdf, Storage::disk('local')->get($doc->file_path));
    }

    public function test_oversized_or_invalid_signature_inputs_leave_the_agreement_unchanged(): void
    {
        [, , $doc, $row] = $this->fixture();
        $data = $this->signature();
        foreach (['data:image/png;base64,'.base64_encode('invalid'), str_repeat('x', 2800001)] as $invalid) {
            $this->postJson($row['sign_url'], [...$data, 'signature_data' => $invalid])->assertUnprocessable()->assertJsonValidationErrors('signature_data');
        }
        $image = imagecreatetruecolor(4097, 1);
        ob_start();
        imagepng($image);
        $large = ob_get_clean();
        imagedestroy($image);
        $this->postJson($row['sign_url'], [...$data, 'signature_data' => 'data:image/png;base64,'.base64_encode($large)])->assertUnprocessable()->assertJsonValidationErrors('signature_data');
        $this->assertNull($doc->fresh()->client_signed_at);
        $this->assertSame(self::$issuedPdf, Storage::disk('local')->get($doc->file_path));
    }

    public function test_success_preserves_every_issued_page_and_only_advances_eligible_case_stages_without_mail(): void
    {
        [$lead, , $doc, $row] = $this->fixture();
        $lead->forceFill(['immigration_stage' => 'Agreement Sent'])->saveQuietly();
        $original = $doc->file_path;
        $issuedPages = (new \Smalot\PdfParser\Parser)->parseContent(self::$issuedPdf)->getPages();
        $this->post($row['sign_url'], $this->signature())->assertRedirect()->assertSessionHasNoErrors();
        $doc->refresh();
        $this->assertNotSame($original, $doc->file_path);
        $this->assertSame(self::$issuedPdf, Storage::disk('local')->get($original));
        $signedPages = (new \Smalot\PdfParser\Parser)->parseContent(Storage::disk('local')->get($doc->file_path))->getPages();
        $this->assertCount(count($issuedPages) + 1, $signedPages);
        foreach ($issuedPages as $i => $page) {
            $this->assertSame(trim($page->getText()), trim($signedPages[$i]->getText()));
        }
        $this->assertSame('Agreement Signed', $lead->fresh()->immigration_stage);
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public function test_partial_storage_failure_cleans_the_new_signature_and_retains_the_issued_pdf(): void
    {
        [, , $doc, $row] = $this->fixture();
        $disk = Storage::disk('local');
        $before = $doc->refresh()->getAttributes();
        $files = $disk->allFiles();
        $data = $this->signature();
        $failing = \Mockery::mock($disk)->makePartial();
        $failing->shouldReceive('put')->andReturnUsing(fn ($path, $bytes) => str_starts_with($path, 'signatures/') ? $disk->put($path, $bytes) : false);
        Storage::shouldReceive('disk')->with('local')->andReturn($failing);
        $this->post($row['sign_url'], $data)->assertRedirect()->assertSessionHasErrors('error');
        $this->assertSame($before, $doc->fresh()->getAttributes());
        $this->assertSame($files, $disk->allFiles());
        $this->assertSame(self::$issuedPdf, $disk->get($doc->file_path));
    }

    public function test_sent_or_explicitly_shared_modern_agreements_are_available_but_stale_checksums_are_rejected(): void
    {
        foreach ([['engagement_sent_at' => now()->toIso8601String()], []] as $sent) {
            [, , $doc, $row] = $this->fixture();
            $doc->update(['notes' => ['engagement_pack_id' => 'synthetic-modern'] + $sent,
                'status' => $sent ? LeadDocument::STATUS_SUBMITTED : LeadDocument::STATUS_STAFF_SHARED]);
            $this->get($row['preview_url'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->postJson($row['sign_url'], [...$this->signature(), 'issued_agreement_hash' => str_repeat('0', 64)])->assertUnprocessable()->assertJsonValidationErrors('issued_agreement_hash');
            $this->assertNull($doc->fresh()->client_signed_at);
            $this->post($row['sign_url'], $this->signature())->assertRedirect()->assertSessionHasNoErrors();
            $this->assertNotNull($doc->fresh()->client_signed_at);
        }
    }

    public function test_public_tracker_conversion_is_rechecked_under_the_common_signing_lock(): void
    {
        [$lead, , $doc] = $this->fixture();
        $lead->forceFill(['is_immigration_case' => false])->saveQuietly();
        $data = $this->signature();
        $cache = \Illuminate\Support\Facades\Cache::getFacadeRoot();
        $proxy = \Mockery::mock($cache)->makePartial();
        \Illuminate\Support\Facades\Cache::swap($proxy);
        $proxy->shouldReceive('lock')->once()->with('engagement:case:'.$lead->id, 90)->andReturn(new class($lead)
        {
            public function __construct(private Lead $lead) {}

            public function block($seconds, $callback)
            {
                $this->lead->forceFill(['is_immigration_case' => true])->saveQuietly();

                return $callback();
            }
        });
        try {
            $this->post('/track/'.$lead->tracking_code.'/documents/'.$doc->id.'/sign', $data)->assertNotFound();
        } finally {
            \Illuminate\Support\Facades\Cache::swap($cache);
        }
        $this->assertNull($doc->fresh()->client_signed_at);
        $this->assertSame(self::$issuedPdf, Storage::disk('local')->get($doc->file_path));
    }

    public function test_both_channels_recheck_another_writers_signature_after_acquiring_the_case_lock(): void
    {
        foreach ([false, true] as $public) {
            [$lead, , $doc, $row] = $this->fixture();
            $lead->forceFill(['is_immigration_case' => false])->saveQuietly();
            $data = $this->signature();
            $files = Storage::disk('local')->allFiles();
            $cache = \Illuminate\Support\Facades\Cache::getFacadeRoot();
            $proxy = \Mockery::mock($cache)->makePartial();
            \Illuminate\Support\Facades\Cache::swap($proxy);
            $proxy->shouldReceive('lock')->once()->with('engagement:case:'.$lead->id, 90)->andReturn(new class($doc)
            {
                public function __construct(private LeadDocument $doc) {}

                public function block($seconds, $callback)
                {
                    $this->doc->forceFill(['client_signed_at' => now()])->saveQuietly();

                    return $callback();
                }
            });
            try {
                $this->post($public ? '/track/'.$lead->tracking_code.'/documents/'.$doc->id.'/sign' : $row['sign_url'], $data)->assertStatus(409);
            } finally {
                \Illuminate\Support\Facades\Cache::swap($cache);
            }
            $this->assertNull($doc->fresh()->client_signature_path);
            $this->assertSame($files, Storage::disk('local')->allFiles());
            $this->assertSame(self::$issuedPdf, Storage::disk('local')->get($doc->file_path));
        }
    }

    public function test_documents_page_omits_draft_and_hidden_metadata_but_keeps_the_issued_control(): void
    {
        [$lead, , $issued] = $this->fixture();
        $issued->update(['checklist_key' => 'engage.written_agreement', 'original_name' => 'VISIBLE-ISSUED.pdf']);
        $request = \App\Models\LeadDocumentRequest::create(['lead_id' => $lead->id, 'label' => 'Review request', 'requested_at' => now()]);
        $draft = LeadDocument::create(['lead_id' => $lead->id, 'request_id' => $request->id, 'checklist_key' => 'engage.written_agreement',
            'source' => LeadDocument::SOURCE_GENERATED, 'source_variant' => 'engagement:written_agreement', 'status' => LeadDocument::STATUS_SUBMITTED,
            'file_path' => $issued->file_path, 'mime' => 'application/pdf', 'original_name' => 'UNSENT-DRAFT-SENTINEL.pdf', 'notes' => ['engagement_pack_id' => 'unsent-modern-pack']]);
        $hidden = LeadDocument::create(['lead_id' => $lead->id, 'checklist_key' => 'synthetic.hidden', 'source' => LeadDocument::SOURCE_UPLOAD,
            'status' => LeadDocument::STATUS_STAFF_SHARED, 'file_path' => $issued->file_path, 'original_name' => 'HIDDEN-SENTINEL.pdf']);
        $lead->forceFill(['hidden_track_documents' => ['synthetic.hidden'], 'document_checklist' => [['key' => 'engage.written_agreement', 'label' => 'Agreement', 'category' => 'Team']]])->saveQuietly();
        $response = $this->get('/portal/lead/documents')->assertOk();
        $props = $response->inertiaProps();
        $json = json_encode($props);
        $this->assertStringContainsString('VISIBLE-ISSUED.pdf', $json);
        $this->assertStringNotContainsString('UNSENT-DRAFT-SENTINEL.pdf', $json);
        $this->assertStringNotContainsString('HIDDEN-SENTINEL.pdf', $json);
        $this->assertNull($props['requests'][0]['latest_document']);
        $this->assertSame([$issued->id], collect($props['checklistFiles']['engage.written_agreement'])->pluck('id')->all());
        $this->assertSame($issued->id, $props['checklist']['Team'][0]['document_id']);
    }

    public function test_media_drive_linked_education_agreement_download_returns_the_signed_pdf_and_preserves_the_asset(): void
    {
        [$lead, $user] = $this->fixture();
        $staff = User::factory()->create(['role' => 'admin']);
        $root = \App\Models\MediaFolder::create(['client_key' => 'lead:'.$lead->id, 'lead_id' => $lead->id, 'name' => 'Client files', 'ancestry' => '/']);
        $path = 'media-drive/assets/'.$root->id.'/issued.pdf';
        Storage::disk('local')->put($path, self::$issuedPdf);
        $asset = \App\Models\MediaFile::create(['folder_id' => $root->id, 'original_name' => 'Shared issued agreement.pdf', 'file_path' => $path,
            'mime' => 'application/pdf', 'size' => strlen(self::$issuedPdf), 'sha256' => hash('sha256', self::$issuedPdf),
            'upload_key' => (string) \Illuminate\Support\Str::uuid(), 'actor_key' => 'user:'.$staff->id, 'uploaded_by' => $staff->id]);
        $attached = app(\App\Services\MediaDrive\Attachments::class)->attach($staff, ['client_type' => 'lead', 'client_id' => $lead->id,
            'file_ids' => [$asset->id], 'checklist_key' => 'agree.consultancy']);
        $doc = LeadDocument::findOrFail($attached['document_ids'][0]);
        $doc->update(['status' => LeadDocument::STATUS_STAFF_SHARED]);
        $assetBefore = $asset->refresh()->getRawOriginal();
        $this->actingAs($user)->get('/portal/lead/documents/'.$doc->id.'/preview')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->post('/portal/lead/documents/'.$doc->id.'/sign', $this->signature())->assertRedirect()->assertSessionHasNoErrors();
        $doc->refresh();
        $response = $this->get('/portal/lead/documents/'.$doc->id.'/download?inline=1')->assertOk();
        $download = file_get_contents($response->baseResponse->getFile()->getPathname());
        $this->assertSame(hash('sha256', Storage::disk('local')->get($doc->file_path)), hash('sha256', $download));
        $this->assertStringContainsString('Client signature confirmation', (new \Smalot\PdfParser\Parser)->parseContent($download)->getText());
        $this->assertSame(self::$issuedPdf, Storage::disk('local')->get($path));
        $this->assertSame($assetBefore, $asset->fresh()->getRawOriginal());
        $this->assertSame($asset->id, $doc->media_file_id);
    }
}
