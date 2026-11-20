<?php

namespace Tests\Feature\Portal;

use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use App\Services\Immigration\EngagementDocumentGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LeadPortalAgreementSigningTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->withoutVite();
        Storage::fake('local');
        Mail::fake();
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Client', 'is_immigration_case' => true]);
        $user = User::factory()->create(['role' => 'lead', 'lead_id' => $lead->id]);
        $doc = LeadDocument::create(['lead_id' => $lead->id, 'original_name' => 'Written Agreement.pdf',
            'file_path' => 'lead-documents/synthetic-agreement.pdf', 'mime' => 'application/pdf', 'size' => 20,
            'source' => LeadDocument::SOURCE_GENERATED, 'source_variant' => 'engagement:written_agreement',
            'status' => LeadDocument::STATUS_STAFF_SHARED]);
        Storage::disk('local')->put($doc->file_path, '%PDF synthetic unsigned');

        return [$lead, $user, $doc];
    }

    public function test_signed_in_case_receives_working_owner_scoped_preview_and_sign_links(): void
    {
        [$lead, $user, $doc] = $this->fixture();
        $base = '/portal/lead/documents/'.$doc->id;
        $snapshot = ['synthetic_issued_terms' => 'unchanged'];
        $doc->update(['notes' => ['engagement_payload' => $snapshot]]);
        $emailToken = str_repeat('p', 48);
        $lead->forceFill(['engagement_signing_token' => $emailToken])->save();
        $this->actingAs($user)->get('/portal/lead/tracker')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('shared_documents.0.preview_url', $base.'/preview')
            ->where('shared_documents.0.sign_url', $base.'/sign'));
        $this->mock(EngagementDocumentGenerator::class, function ($mock) use ($lead, $snapshot) {
            $matches = fn ($record, $type, $overrides) => $record->id === $lead->id
                && $type === 'written_agreement' && $overrides['payload_snapshot'] === $snapshot;
            $mock->shouldReceive('renderHtml')->withArgs($matches)->once()->andReturn('<html>Synthetic agreement</html>');
            $mock->shouldReceive('pdfBinary')->withArgs($matches)->once()->andReturn('%PDF synthetic signed');
        });
        $this->get($base.'/preview')->assertOk()->assertSee('Synthetic agreement');
        $this->from('/portal/lead/tracker')->post($base.'/sign', [
            'signature_data' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            'acknowledged' => 1,
        ])->assertRedirect('/portal/lead/tracker')->assertSessionHasNoErrors();
        $this->assertNotNull($doc->fresh()->client_signed_at);
        $this->assertSame('Synthetic Client', $doc->fresh()->client_signer_name);
        $this->assertSame('%PDF synthetic signed', Storage::disk('local')->get($doc->file_path));
        $this->assertDatabaseCount('lead_documents', 1);
        $this->get('/portal/lead/tracker')->assertInertia(fn (Assert $p) => $p->where('shared_documents.0.signed', true));
        $path = $doc->fresh()->client_signature_path;
        $this->post($base.'/sign', ['acknowledged' => 1])->assertStatus(409);
        $this->assertSame($path, $doc->fresh()->client_signature_path);
        $this->get('/engagement/'.$emailToken)->assertInertia(fn (Assert $p) => $p->where('documents.0.signed', true));
        $this->post('/engagement/'.$emailToken.'/documents/'.$doc->id.'/sign')->assertStatus(409);
    }

    public function test_public_case_codes_and_other_clients_cannot_preview_or_sign(): void
    {
        [$lead, $user, $doc] = $this->fixture();
        $public = '/track/'.$lead->tracking_code.'/documents/'.$doc->id;
        $this->get($public.'/preview')->assertNotFound();
        $this->post($public.'/sign')->assertNotFound();
        $base = '/portal/lead/documents/'.$doc->id;
        $this->get($base.'/preview')->assertRedirect('/login');
        $other = Lead::create(['first_name' => 'Other', 'last_name' => 'Synthetic', 'is_immigration_case' => true]);
        $otherUser = User::factory()->create(['role' => 'lead', 'lead_id' => $other->id]);
        $this->actingAs($otherUser)->get($base.'/preview?lead_id='.$lead->id)->assertNotFound();
        $this->post($base.'/sign', ['lead_id' => $lead->id, 'acknowledged' => 1])->assertNotFound();
        $this->actingAs($user)->get($public.'/preview')->assertNotFound();
        $this->post($public.'/sign')->assertNotFound();
        $this->assertNull($doc->fresh()->client_signed_at);
        $this->assertSame('%PDF synthetic unsigned', Storage::disk('local')->get($doc->file_path));
    }

    public function test_hidden_rejected_and_unshared_documents_are_not_signable(): void
    {
        [$lead, $user, $doc] = $this->fixture();
        $base = '/portal/lead/documents/'.$doc->id;
        $doc->update(['checklist_key' => 'engage.written_agreement']);
        $lead->update(['hidden_track_documents' => ['engage.written_agreement']]);
        $this->actingAs($user)->get($base.'/preview')->assertNotFound();
        $this->post($base.'/sign', ['acknowledged' => 1])->assertNotFound();
        $lead->update(['hidden_track_documents' => []]);
        $doc->update(['status' => LeadDocument::STATUS_REJECTED]);
        $this->get($base.'/preview')->assertStatus(409);
        $this->post($base.'/sign', ['acknowledged' => 1])->assertStatus(409);
        $doc->update(['source' => LeadDocument::SOURCE_UPLOAD, 'status' => LeadDocument::STATUS_SUBMITTED]);
        $this->get($base.'/preview')->assertForbidden();
        $this->post($base.'/sign', ['acknowledged' => 1])->assertForbidden();
        $this->assertNull($doc->fresh()->client_signed_at);
    }

    public function test_consent_and_signature_are_required_and_pdf_failure_does_not_mark_signed(): void
    {
        [, $user, $doc] = $this->fixture();
        $base = '/portal/lead/documents/'.$doc->id;
        $this->actingAs($user)->from('/portal/lead/tracker')->post($base.'/sign')->assertSessionHasErrors('acknowledged');
        $this->post($base.'/sign', ['acknowledged' => 1])->assertSessionHasErrors();
        $this->mock(EngagementDocumentGenerator::class)->shouldReceive('pdfBinary')->once()->andThrow(new \RuntimeException('Synthetic PDF failure'));
        $this->post($base.'/sign', ['acknowledged' => 1, 'signature_data' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='])
            ->assertRedirect('/portal/lead/tracker')->assertSessionHasErrors();
        $this->assertNull($doc->fresh()->client_signed_at);
        $this->assertSame('%PDF synthetic unsigned', Storage::disk('local')->get($doc->file_path));
    }

    public function test_portal_signing_generates_a_real_signed_pdf_without_public_case_access(): void
    {
        [$lead, $user, $doc] = $this->fixture();
        $adviser = User::factory()->create(['name' => 'Synthetic Adviser', 'role' => 'immigration_adviser',
            'iaa_licence_number' => 'TEST123', 'iaa_licence_expiry' => now()->addYear()]);
        $doc->update(['engagement_signer_id' => $adviser->id]);
        $image = imagecreatetruecolor(300, 80);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagestring($image, 5, 20, 30, 'SYNTHETIC TEST', imagecolorallocate($image, 0, 0, 0));
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        $base = '/portal/lead/documents/'.$doc->id;
        $this->actingAs($user)->get($base.'/preview')->assertOk()->assertSee('Synthetic');
        $this->from('/portal/lead/tracker')->post($base.'/sign', ['acknowledged' => 1, 'signature_data' => 'data:image/png;base64,'.base64_encode($bytes)])
            ->assertRedirect('/portal/lead/tracker')->assertSessionHasNoErrors();
        $this->assertNotNull($doc->fresh()->client_signed_at);
        $pdf = Storage::disk('local')->get($doc->file_path);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('Synthetic', (new \Smalot\PdfParser\Parser)->parseContent($pdf)->getText());
        $this->assertSame($bytes, Storage::disk('local')->get($doc->fresh()->client_signature_path));
        $this->get('/track/'.$lead->tracking_code.'/documents/'.$doc->id.'/preview')->assertNotFound();
    }

    public function test_staff_preview_cannot_sign_and_public_non_case_preview_still_works(): void
    {
        [$lead, , $doc] = $this->fixture();
        $base = '/portal/lead/documents/'.$doc->id;
        $staff = User::factory()->create(['role' => 'admin']);
        $this->actingAs($staff)->get($base.'/preview')->assertForbidden();
        $this->from('/portal/lead/tracker')->post($base.'/sign', ['acknowledged' => 1])
            ->assertRedirect('/portal/lead/tracker')->assertSessionHas('error');
        $this->assertNull($doc->fresh()->client_signed_at);
        $lead->update(['is_immigration_case' => false]);
        auth()->logout();
        $this->mock(EngagementDocumentGenerator::class)->shouldReceive('renderHtml')->once()->andReturn('<html>Legacy public preview</html>');
        $this->get('/track/'.$lead->tracking_code.'/documents/'.$doc->id.'/preview')->assertOk()->assertSee('Legacy public preview');
    }

    public function test_existing_education_issued_pdf_is_preserved_through_portal_signing(): void
    {
        [$lead, $user] = $this->fixture();
        $doc = app(\App\Services\AgreementGenerator::class)->consultancy($lead, 'std_100', ['school_enrolment_fee' => 121234]);
        $issuedPath = $doc->file_path;
        $issued = Storage::disk('local')->get($issuedPath);
        $hash = hash('sha256', $issued);
        $base = '/portal/lead/documents/'.$doc->id;
        $this->actingAs($user)->get($base.'/preview')->assertRedirect($base.'/download?inline=1');
        $this->get($base.'/download?inline=1')->assertOk();
        $image = imagecreatetruecolor(100, 40);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imageline($image, 5, 30, 95, 5, imagecolorallocate($image, 0, 0, 0));
        ob_start();
        imagepng($image);
        $signature = ob_get_clean();
        imagedestroy($image);
        $this->from('/portal/lead/tracker')->post($base.'/sign', ['acknowledged' => true,
            'issued_agreement_hash' => $hash, 'signature_data' => 'data:image/png;base64,'.base64_encode($signature)])
            ->assertRedirect('/portal/lead/tracker')->assertSessionHasNoErrors();
        $this->assertNotNull($doc->fresh()->client_signed_at);
        $this->assertSame($issued, Storage::disk('local')->get($issuedPath));
        $this->assertSame($hash, $doc->fresh()->notes['issued_agreement']['sha256']);
    }

    public function test_known_draft_pack_cannot_be_signed_before_issue(): void
    {
        [, $user, $doc] = $this->fixture();
        $base = '/portal/lead/documents/'.$doc->id;
        $doc->update(['notes' => ['engagement_pack_id' => 'synthetic-pack']]);
        $this->actingAs($user)->get($base.'/preview')->assertNotFound();
        $this->post($base.'/sign', ['acknowledged' => 1])->assertNotFound();
        $this->assertNull($doc->fresh()->client_signed_at);
        $doc->update(['notes' => ['engagement_pack_id' => 'synthetic-pack', 'engagement_sent_at' => now()->toIso8601String()]]);
        $this->mock(EngagementDocumentGenerator::class)->shouldReceive('renderHtml')->once()->andReturn('<html>Issued synthetic agreement</html>');
        $this->get($base.'/preview')->assertOk()->assertSee('Issued synthetic agreement');
    }

    public function test_emailed_written_agreement_link_previews_and_signs_and_portal_sees_same_signature(): void
    {
        [$lead, $user, $doc] = $this->fixture();
        $adviser = User::factory()->create(['name' => 'Synthetic Adviser', 'role' => 'immigration_adviser',
            'iaa_licence_number' => 'TEST123', 'iaa_licence_expiry' => now()->addYear()]);
        $token = str_repeat('s', 48);
        $doc->update(['engagement_signer_id' => $adviser->id, 'notes' => [
            'engagement_pack_id' => 'synthetic-email-pack', 'engagement_signing_token' => $token,
            'engagement_sent_at' => now()->toIso8601String(),
        ]]);
        $link = '/engagement/'.$token.'?pack=synthetic-email-pack';
        $this->get($link)->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('track/EngagementPack')->where('documents.0.id', $doc->id)
            ->where('documents.0.sign_url', '/engagement/'.$token.'/documents/'.$doc->id.'/sign'));
        $base = '/engagement/'.$token.'/documents/'.$doc->id;
        $this->get($base.'/preview')->assertOk()->assertSee('Synthetic');
        $this->get('/engagement/invalid-token/documents/'.$doc->id.'/preview')->assertNotFound();
        $this->from($link)->post($base.'/sign', ['signer_name' => 'Synthetic Client',
            'signature_data' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='])
            ->assertRedirect($link)->assertSessionHasNoErrors();
        $this->assertNotNull($doc->fresh()->client_signed_at);
        $path = $doc->fresh()->client_signature_path;
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($doc->file_path));
        $this->get($link)->assertInertia(fn (Assert $p) => $p->where('documents.0.signed', true));
        $this->actingAs($user)->get('/portal/lead/tracker')->assertInertia(fn (Assert $p) => $p->where('shared_documents.0.signed', true));
        $this->post('/portal/lead/documents/'.$doc->id.'/sign', ['acknowledged' => 1])->assertStatus(409);
        $this->post($base.'/sign', ['signer_name' => 'Synthetic Client'])->assertStatus(409);
        $this->assertSame($path, $doc->fresh()->client_signature_path);
        $this->assertDatabaseCount('lead_documents', 1);
        $this->get('/portal/lead/documents/'.$doc->id.'/download')->assertOk();
    }
}
