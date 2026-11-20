<?php

namespace Tests\Feature;

use App\Mail\TemplatedMessage;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\AgreementGenerator;
use App\Services\EnglishAgreementAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EnglishAgreementClientAccessTest extends TestCase
{
    use RefreshDatabase;

    private function agreement(): array
    {
        $this->withoutVite();
        Storage::fake('local');
        Mail::fake();
        $staff = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($staff);
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'English Client',
            'email' => 'english@example.test', 'is_immigration_case' => true, 'passport_number' => 'PRIVATE-TEST-PASSPORT']);
        $doc = app(AgreementGenerator::class)->englishOffshore($lead);

        return [$lead, $doc, $staff];
    }

    private function signature(): string
    {
        $image = imagecreatetruecolor(200, 60);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagestring($image, 4, 10, 20, 'SYNTHETIC TEST', imagecolorallocate($image, 0, 0, 0));
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode($bytes);
    }

    public function test_notifying_an_english_agreement_for_a_case_sends_a_document_link_not_the_blocked_tracker(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        Mail::fake();
        $staff = User::factory()->create(['role' => 'admin']);
        $this->actingAs($staff);
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'English Client',
            'email' => 'english@example.test', 'is_immigration_case' => true]);
        $doc = app(AgreementGenerator::class)->englishOffshore($lead);
        MessageTemplate::where('key', 'consultancy_agreement')->delete();
        MessageTemplate::create(['key' => 'consultancy_agreement', 'name' => 'Synthetic agreement',
            'channels' => ['email'], 'is_active' => true, 'email_subject' => 'Agreement',
            'email_body' => '<a href="{{tracker_url}}">View agreement</a>']);
        $this->actingAs($staff)->post('/admin/leads/'.$lead->id.'/notify-document-ready', [
            'kind' => 'agreement', 'document_id' => $doc->id,
        ])->assertRedirect()->assertSessionHasNoErrors();
        Mail::assertQueued(TemplatedMessage::class, fn ($mail) => str_contains($mail->markdownBody, '/english-agreements/'));
    }

    public function test_signed_link_opens_only_the_issued_agreement_without_any_client_account(): void
    {
        [$lead, $doc] = $this->agreement();
        $url = EnglishAgreementAccess::link($doc);
        auth()->logout();
        $response = $this->get($url)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertInertia(fn (Assert $p) => $p->component('track/EnglishAgreement')
                ->where('document.id', $doc->id)->where('document.signed', false)
                ->missing('lead')->missing('info')->missing('visa')->missing('documents'));
        $props = $response->viewData('page')['props']['document'];
        $this->get($props['view_url'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(0, User::where('lead_id', $lead->id)->count());
        $this->get('/track/'.$lead->tracking_code)->assertNotFound();
        $this->assertTrue($lead->fresh()->is_immigration_case);
        $this->assertSame('PRIVATE-TEST-PASSPORT', $lead->fresh()->passport_number);
    }

    public function test_client_signs_real_pdf_preserving_issued_terms_and_cannot_sign_twice(): void
    {
        [$lead, $doc] = $this->agreement();
        $originalPath = $doc->file_path;
        $original = Storage::disk('local')->get($originalPath);
        $url = EnglishAgreementAccess::link($doc);
        $signature = $this->signature();
        auth()->logout();
        $props = $this->get($url)->assertOk()->viewData('page')['props']['document'];
        $this->from($url)->post($props['sign_url'], ['acknowledged' => true, 'signature_data' => $signature,
            'issued_agreement_hash' => str_repeat('0', 64)])->assertSessionHasErrors('issued_agreement_hash');
        $this->assertNull($doc->fresh()->client_signed_at);
        $this->post($props['sign_url'], ['acknowledged' => true, 'signature_data' => $signature,
            'issued_agreement_hash' => $props['issued_agreement_hash']])->assertRedirect($url)->assertSessionHasNoErrors();
        $this->assertNotNull($doc->fresh()->client_signed_at);
        $this->assertSame($original, Storage::disk('local')->get($originalPath));
        $parser = new \Smalot\PdfParser\Parser;
        $before = $parser->parseContent($original)->getPages();
        $after = $parser->parseContent(Storage::disk('local')->get($doc->fresh()->file_path))->getPages();
        $this->assertCount(count($before) + 1, $after);
        foreach ($before as $index => $page) {
            $this->assertSame(trim($page->getText()), trim($after[$index]->getText()));
        }
        $path = $doc->fresh()->client_signature_path;
        $this->post($props['sign_url'], ['acknowledged' => true])->assertStatus(409);
        $this->assertSame($path, $doc->fresh()->client_signature_path);
        $this->get($url)->assertInertia(fn (Assert $p) => $p->where('document.signed', true)->where('document.sign_url', null));
        $this->assertTrue($lead->fresh()->is_immigration_case);
    }

    public function test_unsigned_tampered_expired_hidden_and_non_english_links_are_denied(): void
    {
        [$lead, $doc] = $this->agreement();
        $url = EnglishAgreementAccess::link($doc);
        $other = app(AgreementGenerator::class)->englishEngagement($lead);
        $nonEnglish = app(AgreementGenerator::class)->consultancy($lead, 'std_100');
        auth()->logout();
        $this->get('/english-agreements/'.$doc->id)->assertForbidden();
        $this->get(str_replace('/'.$doc->id.'?', '/'.$other->id.'?', $url))->assertForbidden();
        $this->get(URL::temporarySignedRoute('english-agreement.show', now()->addDay(), ['document' => $nonEnglish->id]))->assertNotFound();
        $lead->update(['hidden_track_documents' => ['agree.engagement_english']]);
        $this->get($url)->assertNotFound();
        $lead->update(['hidden_track_documents' => []]);
        $doc->update(['status' => \App\Models\LeadDocument::STATUS_REJECTED]);
        $this->get($url)->assertNotFound();
        $doc->update(['status' => \App\Models\LeadDocument::STATUS_SUBMITTED]);
        $this->travel(8)->days();
        $this->get($url)->assertForbidden();
    }

    public function test_approval_automation_and_fallback_use_the_exact_newly_issued_english_document(): void
    {
        [$lead, $oldDoc, $staff] = $this->agreement();
        MessageTemplate::where('key', 'synthetic_english_approved')->delete();
        MessageTemplate::create(['key' => 'synthetic_english_approved', 'department' => 'education', 'name' => 'Synthetic approval',
            'channels' => ['email'], 'is_active' => true, 'email_subject' => 'Approved', 'email_body' => '<a href="{{tracker_url}}">Review</a>']);
        \App\Models\EmailAutomationMessage::create(['event_key' => 'education.consultancy.approved', 'recipient' => 'client',
            'template_key' => 'synthetic_english_approved', 'enabled' => true]);
        \App\Services\ConsultancyReviewService::submit($lead, 'english_offshore', [], $staff->id);
        $this->post('/consultancy-verification/'.$lead->id.'/approve', ['verify_all' => true])->assertRedirect()->assertSessionHasNoErrors();
        $issued = $lead->documents()->where('checklist_key', 'agree.engagement_english')->orderByDesc('id')->firstOrFail();
        $this->assertNotSame($oldDoc->id, $issued->id);
        Mail::assertQueued(TemplatedMessage::class, fn ($mail) => str_contains($mail->markdownBody, '/english-agreements/'.$issued->id.'?'));
        Mail::assertQueuedCount(1);
    }

    public function test_resend_fallback_uses_the_existing_pdf_and_does_not_generate_another_document(): void
    {
        [$lead, $doc] = $this->agreement();
        MessageTemplate::where('key', 'consultancy_agreement')->update(['is_active' => false]);
        $count = $lead->documents()->count();
        $this->post('/admin/leads/'.$lead->id.'/notify-document-ready', ['kind' => 'agreement', 'document_id' => $doc->id])
            ->assertRedirect()->assertSessionHasNoErrors();
        Mail::assertQueued(\App\Mail\DocumentReadyNotification::class, fn ($mail) => str_contains($mail->trackUrl, '/english-agreements/'.$doc->id.'?') && $mail->documentOnly);
        $this->assertSame($count, $lead->documents()->count());
        $this->assertNull($doc->fresh()->client_signed_at);
    }

    public function test_staff_cannot_notify_another_records_document_and_proposal_mail_stays_unchanged(): void
    {
        [$lead, $doc] = $this->agreement();
        $other = Lead::create(['first_name' => 'Other', 'last_name' => 'Synthetic', 'email' => 'other@example.test']);
        $this->post('/admin/leads/'.$other->id.'/notify-document-ready', ['kind' => 'agreement', 'document_id' => $doc->id])
            ->assertSessionHasErrors();
        Mail::assertNothingOutgoing();
        $proposal = new \App\Mail\DocumentReadyNotification($lead, 'proposal');
        $this->assertSame(url('/track/'.$lead->tracking_code), $proposal->trackUrl);
        $this->assertFalse($proposal->documentOnly);
        $this->assertStringContainsString('Open my tracker', $proposal->render());
    }

    public function test_consent_invalid_signature_and_render_failure_do_not_change_the_issued_pdf(): void
    {
        [, $doc] = $this->agreement();
        $original = Storage::disk('local')->get($doc->file_path);
        $url = EnglishAgreementAccess::link($doc);
        $signature = $this->signature();
        auth()->logout();
        $props = $this->get($url)->assertOk()->viewData('page')['props']['document'];
        $this->from($url)->post($props['sign_url'], ['signature_data' => $signature,
            'issued_agreement_hash' => $props['issued_agreement_hash']])->assertSessionHasErrors('acknowledged');
        $this->post($props['sign_url'], ['acknowledged' => true, 'signature_data' => 'invalid',
            'issued_agreement_hash' => $props['issued_agreement_hash']])->assertSessionHasErrors('signature_data');
        \Barryvdh\DomPDF\Facade\Pdf::shouldReceive('loadView')->once()->andThrow(new \RuntimeException('Synthetic render failure'));
        $this->post($props['sign_url'], ['acknowledged' => true, 'signature_data' => $signature,
            'issued_agreement_hash' => $props['issued_agreement_hash']])->assertSessionHasErrors('signature_data');
        $this->assertNull($doc->fresh()->client_signed_at);
        $this->assertSame($original, Storage::disk('local')->get($doc->file_path));
    }
}
