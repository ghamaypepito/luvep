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

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Mail::fake();
        Notification::fake();
        $this->mock(EngagementDocumentGenerator::class, function ($mock) {
            $mock->shouldReceive('renderHtml')->andReturn('<h1>Synthetic issued written agreement</h1>');
            $mock->shouldReceive('pdfBinary')->andReturn('%PDF-synthetic-signed');
        });
    }

    private function fixture(): array
    {
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Client', 'is_immigration_case' => true]);
        $user = User::factory()->create(['role' => 'lead', 'lead_id' => $lead->id]);
        $path = 'lead-documents/'.$lead->id.'/written.pdf';
        Storage::disk('local')->put($path, '%PDF-synthetic-issued');
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
        $this->get($row['preview_url'])->assertOk()->assertSee('Synthetic issued written agreement');
    }

    public function test_signed_in_immigration_client_can_submit_to_the_sign_url_supplied_by_the_portal(): void
    {
        [, , $doc, $row] = $this->fixture();
        $this->from('/portal/lead/tracker')->post($row['sign_url'], ['acknowledged' => 1,
            'signature_data' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a2ioAAAAASUVORK5CYII='])->assertRedirect('/portal/lead/tracker')->assertSessionHasNoErrors();
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

        return ['acknowledged' => 1, 'signature_data' => 'data:image/png;base64,'.base64_encode($binary)];
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
        $this->assertSame('%PDF-synthetic-issued', Storage::disk('local')->get($doc->file_path));
    }

    public function test_terms_signature_validation_and_duplicate_submission_preserve_the_first_signature(): void
    {
        [, , $doc, $row] = $this->fixture();
        $this->postJson($row['sign_url'], ['signature_data' => $this->signature()['signature_data']])->assertUnprocessable()->assertJsonValidationErrors('acknowledged');
        $this->postJson($row['sign_url'], ['acknowledged' => 1])->assertRedirect()->assertSessionHasErrors();
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
        $this->get($row['preview_url'])->assertOk()->assertSee('Synthetic')->assertDontSee('ChangedAfterIssue');
        $this->post($row['sign_url'], $this->signature())->assertRedirect()->assertSessionHasNoErrors();
        $pdf = Storage::disk('local')->get($doc->refresh()->file_path);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $text = (new \Smalot\PdfParser\Parser)->parseContent($pdf)->getText();
        $this->assertStringContainsString('Synthetic', $text);
        $this->assertStringNotContainsString('ChangedAfterIssue', $text);
        $this->assertNotNull($doc->client_signed_at);
        $this->assertSame(1, LeadDocument::count());
    }

    public function test_renderer_failure_does_not_change_the_record_or_leave_signature_files(): void
    {
        [, , $doc, $row] = $this->fixture();
        $this->mock(EngagementDocumentGenerator::class)->shouldReceive('pdfBinary')->andThrow(new \RuntimeException('Synthetic render failure'));
        $before = $doc->refresh()->getAttributes();
        $files = Storage::disk('local')->allFiles();
        $this->post($row['sign_url'], $this->signature())->assertRedirect()->assertSessionHasErrors('error');
        $this->assertSame($before, $doc->fresh()->getAttributes());
        $this->assertSame('%PDF-synthetic-issued', Storage::disk('local')->get($doc->file_path));
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
}
