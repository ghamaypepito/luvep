<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
use App\Services\AgreementGenerator;
use App\Services\ConsultancyReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

class IssuedEducationAgreementTest extends TestCase
{
    use RefreshDatabase;

    public function test_signature_preserves_issued_price(): void
    {
        Storage::fake('local');
        Mail::fake();
        Notification::fake();
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Applicant']);
        $doc = app(AgreementGenerator::class)->consultancy($lead, 'std_100', ['school_enrolment_fee' => 121234, 'application_description' => 'Terms approved for the issued copy.']);
        $issuedPath = $doc->file_path;
        $issued = Storage::disk('local')->get($issuedPath);
        $hash = hash('sha256', $issued);
        $pages = (new Parser)->parseContent($issued)->getPages();
        $issuedText = implode(' ', array_map(fn ($page) => $page->getText(), $pages));
        $this->assertStringContainsString('121,234', $issuedText);
        $this->assertStringContainsString('Terms approved for the issued copy.', $issuedText);

        ConsultancyReviewService::submit($lead, 'consultancy_std_100', ['school_enrolment_fee' => 139876, 'application_description' => 'Only in the later review.'], null);
        $image = imagecreatetruecolor(100, 40);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imageline($image, 5, 30, 95, 5, imagecolorallocate($image, 0, 0, 0));
        ob_start();
        imagepng($image);
        $signature = ob_get_clean();
        $base = '/track/'.$lead->tracking_code.'/documents/'.$doc->id;
        $this->get($base.'/preview')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->withoutVite()->get('/track/'.$lead->tracking_code)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('shared_documents.0.issued_agreement_hash', $hash)
            ->where('shared_documents.0.preview_url', null));
        $request = [
            'acknowledged' => true, 'signature_data' => 'data:image/png;base64,'.base64_encode($signature),
            'issued_agreement_hash' => $hash,
        ];
        $this->post($base.'/sign', [...$request, 'issued_agreement_hash' => str_repeat('0', 64)])
            ->assertSessionHasErrors('issued_agreement_hash');
        $this->assertNull($doc->fresh()->client_signed_at);
        $staff = User::factory()->create(['role' => 'education']);
        $this->actingAs($staff)->post('/admin/leads/'.$lead->id.'/documents/'.$doc->id.'/regenerate')->assertStatus(409);
        $this->post($base.'/sign', $request)->assertRedirect()->assertSessionHasNoErrors();
        $doc->refresh();
        $this->assertNotNull($doc->client_signed_at);
        $this->assertSame($signature, Storage::disk('local')->get($doc->client_signature_path));
        $this->assertSame($hash, $doc->notes['issued_agreement']['sha256']);
        $this->assertSame($issued, Storage::disk('local')->get($issuedPath));
        $signed = (new Parser)->parseContent(Storage::disk('local')->get($doc->file_path))->getPages();
        $this->assertCount(count($pages) + 1, $signed);
        foreach ($pages as $i => $page) {
            $this->assertSame(trim($page->getText()), trim($signed[$i]->getText()));
        }
        $this->assertStringContainsString('Client signature confirmation', end($signed)->getText());
        $this->assertStringContainsString($hash, end($signed)->getText());
        $this->post($base.'/sign', $request)->assertStatus(409);
    }

    public function test_education_staff_can_propose_a_discount_but_only_an_authorized_admin_issues_the_agreement(): void
    {
        Storage::fake('local');
        Mail::fake();
        Notification::fake();
        $staff = User::factory()->create(['role' => 'education', 'is_active' => true, 'module_permissions' => ['program_verification.consultancy']]);
        $eireen = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email' => 'eireen@dimmigration.co']);
        $backup = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Applicant']);
        ConsultancyReviewService::submit($lead, 'consultancy_std_100', ['school_enrolment_fee' => 81234], $staff->id);

        $this->assertSame($eireen->id, $lead->consultancy_review['default_approver_id']);
        $this->withoutVite()->actingAs($staff)->get('/consultancy-verification')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('proposals.0.default_approver', $eireen->name)
            ->where('proposals.0.can_approve', false));

        $base = "/consultancy-verification/{$lead->id}";
        $this->post($base.'/verify')->assertRedirect();
        $this->post($base.'/approve', ['send_email' => false])->assertForbidden();
        $this->assertDatabaseCount('lead_documents', 0);

        $this->actingAs($backup)->post($base.'/approve', ['send_email' => false])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($backup->id, $lead->refresh()->consultancy_review['approved_by']);
        $document = $lead->documents()->sole();
        $text = (new Parser)->parseContent(Storage::disk('local')->get($document->file_path))->getText();
        $this->assertStringContainsString('81,234', $text);
        Mail::assertNothingOutgoing();
    }
}
