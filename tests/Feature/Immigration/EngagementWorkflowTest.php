<?php

namespace Tests\Feature\Immigration;

use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use App\Services\Immigration\EngagementDocumentGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EngagementWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $adviser;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        $this->staff = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->adviser = User::factory()->create([
            'name' => 'Test Adviser', 'role' => User::ROLE_IMMIGRATION_ADVISER,
            'iaa_licence_number' => 'TEST12345', 'iaa_licence_expiry' => now()->addYear(),
        ]);
        $this->lead = Lead::create([
            'first_name' => 'Fixture', 'middle_name' => 'Middle', 'last_name' => 'Client',
            'email' => 'fixture@example.test', 'phone' => '+6495550100',
            'dob' => '1990-02-03', 'passport_number' => 'FIXTURE123',
            'residence_address_line_1' => '10 Sample Street', 'residence_city' => 'Auckland',
            'is_immigration_case' => true,
        ]);
        $this->actingAs($this->staff);
    }

    private function settings(string $type = 'initial_consultation'): array
    {
        return [
            'engagement_type' => $type, 'signer_id' => $this->adviser->id,
            'types' => [$type, 'invoice'], 'consultation_date' => '2026-11-02',
            'consultation_time' => '10:30', 'consultation_timezone' => 'Pacific/Auckland',
            'meeting_link' => 'https://meet.google.com/abc-defg-hij',
        ];
    }

    private function prepare(string $type = 'initial_consultation'): array
    {
        return $this->postJson("/admin/leads/{$this->lead->id}/engagement/prepare", $this->settings($type))
            ->assertOk()->json();
    }

    private function save(string $type = 'initial_consultation'): array
    {
        $prepared = $this->prepare($type);

        return $this->postJson("/admin/leads/{$this->lead->id}/engagement/save", ['token' => $prepared['token']])
            ->assertOk()->json();
    }

    public function test_prepare_and_save_never_send_and_save_is_idempotent(): void
    {
        \App\Models\EmailAutomationMessage::create([
            'event_key' => 'immigration.invoice.sent', 'recipient' => 'client', 'template_key' => 'migration_agreement', 'enabled' => true,
        ]);
        \App\Models\EmailAutomationMessage::create([
            'event_key' => 'immigration.engagement.sent', 'recipient' => 'client', 'template_key' => 'migration_agreement', 'enabled' => true,
        ]);
        $p = $this->prepare();
        $this->assertDatabaseCount('lead_documents', 0);
        $this->assertSame('USD', $p['currency']);
        $this->assertEquals(250, $p['total']);
        $this->get($p['documents'][0]['preview_url'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $a = $this->postJson("/admin/leads/{$this->lead->id}/engagement/save", ['token' => $p['token']])->assertOk()->json();
        $b = $this->postJson("/admin/leads/{$this->lead->id}/engagement/save", ['token' => $p['token']])->assertOk()->json();
        $this->assertSame($a['pack_id'], $b['pack_id']);
        $this->assertDatabaseCount('lead_documents', 2);
        $this->assertNull($this->lead->fresh()->engagement_sent_at);
        Mail::assertNothingOutgoing();
    }

    public function test_oia_and_consultation_are_separate_packs_with_correct_invoice_totals(): void
    {
        $a = $this->save();
        $b = $this->save('oia');
        $this->assertNotSame($a['pack_id'], $b['pack_id']);
        $this->assertSame('NZD', $b['currency']);
        $this->assertEquals(575, $b['total']);
        $this->assertDatabaseCount('lead_documents', 4);
        Mail::assertNothingOutgoing();
    }

    public function test_templates_autofill_case_and_selected_adviser(): void
    {
        $generator = app(EngagementDocumentGenerator::class);
        $html = $generator->renderHtml($this->lead, 'oia', ['signer_id' => $this->adviser->id]);
        foreach (['Fixture Middle Client', 'FIXTURE123', '10 Sample Street', 'fixture@example.test', 'Test Adviser', 'TEST12345', '500.00'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('Hendry DAI', $html);
        $html = $generator->renderHtml($this->lead, 'initial_consultation', $this->settings());
        foreach (['Fixture Middle Client', 'Test Adviser', '10:30', 'Pacific/Auckland', 'https://meet.google.com/abc-defg-hij', '250.00', 'USD'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }

    public function test_missing_consultation_or_oia_fields_are_reported(): void
    {
        $data = $this->settings();
        unset($data['meeting_link']);
        $this->postJson("/admin/leads/{$this->lead->id}/engagement/prepare", $data)
            ->assertUnprocessable()->assertJsonValidationErrors('meeting_link');
        $this->lead->update(['passport_number' => null]);
        $this->postJson("/admin/leads/{$this->lead->id}/engagement/prepare", $this->settings('oia'))
            ->assertUnprocessable()->assertJsonValidationErrors('client_details');
        Mail::assertNothingOutgoing();
    }

    public function test_review_is_silent_and_only_explicit_send_sends_exact_pack_once(): void
    {
        $pack = $this->save();
        $this->save('oia');
        $review = $this->postJson("/admin/leads/{$this->lead->id}/engagement/review", ['pack_id' => $pack['pack_id']])
            ->assertOk()->json();
        $this->assertCount(2, $review['documents']);
        $this->assertStringContainsString('fixture@example.test', implode(', ', $review['to']));
        Mail::assertNothingOutgoing();
        $url = "/admin/leads/{$this->lead->id}/engagement/send-reviewed";
        $this->postJson($url, ['token' => $review['token']])->assertOk();
        $this->postJson($url, ['token' => $review['token']])->assertUnprocessable();
        Mail::assertSentCount(1);
        Mail::assertSent(\App\Mail\ReviewedEngagementMail::class, function ($mail) use ($review) {
            return count($mail->files) === 2 && $mail->htmlBody === $review['html'];
        });
    }

    public function test_changed_attachment_or_recipient_invalidates_review(): void
    {
        $pack = $this->save();
        $review = $this->postJson("/admin/leads/{$this->lead->id}/engagement/review", ['pack_id' => $pack['pack_id']])->assertOk()->json();
        $this->lead->update(['email' => 'changed@example.test']);
        $this->postJson("/admin/leads/{$this->lead->id}/engagement/send-reviewed", ['token' => $review['token']])->assertUnprocessable();
        $review = $this->postJson("/admin/leads/{$this->lead->id}/engagement/review", ['pack_id' => $pack['pack_id']])->assertOk()->json();
        Storage::disk('local')->put(LeadDocument::first()->file_path, 'changed');
        $this->postJson("/admin/leads/{$this->lead->id}/engagement/send-reviewed", ['token' => $review['token']])->assertUnprocessable();
        Mail::assertNothingOutgoing();
    }

    public function test_another_staff_member_cannot_use_preparation_or_review_token(): void
    {
        $p = $this->prepare();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
        $this->postJson("/admin/leads/{$this->lead->id}/engagement/save", ['token' => $p['token']])->assertNotFound();
        Mail::assertNothingOutgoing();
    }

    public function test_portal_lists_separate_packs_and_scoped_signing_uses_saved_details(): void
    {
        $first = $this->save();
        $second = $this->save('oia');
        $this->get('/portal/immigration/cases/engagement')
            ->assertOk()->assertInertia(fn ($page) => $page->has('generated', 2)->where('generated.0.engagement_type', 'oia')->where('generated.0.doc_count', 2));
        $doc = LeadDocument::where('source_variant', 'engagement:oia')->firstOrFail();
        $notes = $doc->notes;
        $notes['engagement_sent_at'] = now()->toIso8601String();
        foreach (LeadDocument::where('notes->engagement_pack_id', $second['pack_id'])->get() as $file) {
            $file->update(['notes' => array_merge($file->notes, ['engagement_sent_at' => $notes['engagement_sent_at']])]);
        }
        $token = $doc->notes['engagement_signing_token'];
        $this->get("/engagement/{$token}")
            ->assertOk()->assertInertia(fn ($page) => $page->has('documents', 2));
        $doc = LeadDocument::where('source_variant', 'engagement:oia')->firstOrFail();
        $this->lead->update(['passport_number' => 'UPDATEDPASSPORT']);
        $this->get("/engagement/{$token}/documents/{$doc->id}/preview")
            ->assertOk()->assertSee('FIXTURE123')->assertDontSee('UPDATEDPASSPORT');
        Mail::assertNothingOutgoing();
    }

    public function test_saved_pack_cannot_be_changed_to_a_different_engagement_type(): void
    {
        $pack = $this->save();
        $settings = $this->settings('oia') + ['pack_id' => $pack['pack_id']];
        $this->postJson("/admin/leads/{$this->lead->id}/engagement/prepare", $settings)->assertUnprocessable();
        $this->assertDatabaseCount('lead_documents', 2);
    }

    public function test_signed_documents_cannot_be_regenerated(): void
    {
        $pack = $this->save();
        LeadDocument::where('source_variant', 'engagement:initial_consultation')->update(['client_signed_at' => now()]);
        $this->postJson("/admin/leads/{$this->lead->id}/engagement/prepare", $this->settings() + ['pack_id' => $pack['pack_id']])
            ->assertUnprocessable();
        $this->assertDatabaseCount('lead_documents', 2);
    }

    public function test_expired_signer_cannot_prepare(): void
    {
        $this->adviser->update(['iaa_licence_expiry' => now()->subDay()]);
        $this->postJson("/admin/leads/{$this->lead->id}/engagement/prepare", $this->settings())->assertUnprocessable();
        $this->assertDatabaseCount('lead_documents', 0);
        Mail::assertNothingOutgoing();
    }

    public function test_review_uses_the_actual_editable_template_and_all_recipients(): void
    {
        \App\Models\MessageTemplate::updateOrCreate(['key' => 'oia_engagement', 'department' => ''], [
            'name' => 'Migration Agreement', 'channels' => ['email'], 'is_active' => true,
            'email_subject' => 'Agreement for {{first_name}}',
            'email_body' => '<p>Hello {{first_name}}</p><p><a href="{{engagement_url}}">Review agreement</a></p>',
            'to_extra' => 'extra@example.test', 'cc' => 'cc@example.test', 'bcc' => 'bcc@example.test',
        ]);
        $pack = $this->save('oia');
        $review = $this->postJson("/admin/leads/{$this->lead->id}/engagement/review", ['pack_id' => $pack['pack_id']])->assertOk()->json();
        $this->assertSame('Agreement for Fixture', $review['subject']);
        $this->assertContains('extra@example.test', $review['to']);
        $this->assertContains('cc@example.test', $review['cc']);
        $this->assertContains('bcc@example.test', $review['bcc']);
        $this->assertStringContainsString('?pack='.$pack['pack_id'], $review['html']);
        Mail::assertNothingOutgoing();
    }

    public function test_legacy_generation_with_notify_true_never_sends_or_advances_to_sent(): void
    {
        \App\Models\EmailAutomationMessage::create([
            'event_key' => 'immigration.invoice.sent', 'recipient' => 'client', 'template_key' => 'migration_agreement', 'enabled' => true,
        ]);
        $this->lead->update(['immigration_stage' => 'For Assessment']);
        Mail::fake();
        $this->post("/admin/leads/{$this->lead->id}/engagement/generate", [
            'types' => ['written_agreement', 'invoice'], 'signer_id' => $this->adviser->id, 'notify' => true,
        ])->assertSessionHasNoErrors();
        $this->assertSame('For Assessment', $this->lead->fresh()->immigration_stage);
        Mail::assertNothingOutgoing();
    }

    public function test_consultation_defaults_use_the_clients_saved_booking(): void
    {
        \App\Models\Booking::create([
            'lead_id' => $this->lead->id, 'first_name' => 'Fixture', 'last_name' => 'Client',
            'email' => $this->lead->email, 'service_type' => 'immigration', 'status' => 'confirmed', 'consultant_name' => 'Test Adviser',
            'appointment_at' => '2026-11-05 02:30:00', 'client_timezone' => 'Pacific/Auckland',
            'meet_link' => 'https://meet.google.com/saved-booking',
        ]);
        $this->getJson("/admin/leads/{$this->lead->id}/engagement/defaults")->assertOk()
            ->assertJsonPath('consultation_date', '2026-11-05')->assertJsonPath('consultation_time', '15:30')
            ->assertJsonPath('meeting_link', 'https://meet.google.com/saved-booking');
        Mail::assertNothingOutgoing();
    }

    public function test_a_sent_pack_token_cannot_expose_another_unsent_pack(): void
    {
        $one = $this->save();
        $two = $this->save('oia');
        $review = $this->postJson("/admin/leads/{$this->lead->id}/engagement/review", ['pack_id' => $one['pack_id']])->assertOk()->json();
        $this->postJson("/admin/leads/{$this->lead->id}/engagement/send-reviewed", ['token' => $review['token']])->assertOk();
        $consultation = LeadDocument::where('source_variant', 'engagement:initial_consultation')->firstOrFail();
        $oia = LeadDocument::where('source_variant', 'engagement:oia')->firstOrFail();
        $token = $consultation->notes['engagement_signing_token'];
        $this->get("/engagement/{$token}")->assertOk()->assertInertia(fn ($page) => $page->has('documents', 2));
        $this->get("/engagement/{$token}/documents/{$oia->id}/download")->assertNotFound();
        $this->get('/engagement/'.$oia->notes['engagement_signing_token'])->assertNotFound();
        $legacyToken = $this->lead->fresh()->ensureEngagementSigningToken();
        $this->get("/engagement/{$legacyToken}/documents/{$oia->id}/download")->assertNotFound();
    }

    public function test_expired_adviser_invalidates_a_previously_reviewed_email(): void
    {
        $pack = $this->save();
        $review = $this->postJson("/admin/leads/{$this->lead->id}/engagement/review", ['pack_id' => $pack['pack_id']])->assertOk()->json();
        $this->adviser->update(['iaa_licence_expiry' => now()->subDay()]);
        $this->postJson("/admin/leads/{$this->lead->id}/engagement/send-reviewed", ['token' => $review['token']])->assertUnprocessable();
        Mail::assertNothingOutgoing();
    }

    public function test_invoice_workspace_keeps_usd_and_nzd_totals_separate(): void
    {
        $this->save();
        $this->save('oia');
        $this->get('/portal/immigration/cases/invoice')->assertOk()->assertInertia(fn ($page) => $page
            ->has('generated', 2)->where('generated.0.currency', 'NZD')->where('generated.0.total_amount', 575)
            ->where('generated.1.currency', 'USD')->where('generated.1.total_amount', 250));
    }

    public function test_fallback_email_contains_an_exact_valid_signing_url(): void
    {
        \App\Models\MessageTemplate::where('key', 'migration_agreement')->delete();
        $pack = $this->save('oia');
        $review = $this->postJson("/admin/leads/{$this->lead->id}/engagement/review", ['pack_id' => $pack['pack_id']])->assertOk()->json();
        $doc = LeadDocument::where('source_variant', 'engagement:oia')->firstOrFail();
        $dom = new \DOMDocument;
        @$dom->loadHTML($review['html']);
        $urls = [];
        foreach ($dom->getElementsByTagName('a') as $anchor) {
            $urls[] = $anchor->getAttribute('href');
        }
        $this->assertContains(rtrim(config('app.url'), '/').'/engagement/'.$doc->notes['engagement_signing_token'].'?pack='.$pack['pack_id'], $urls);
    }

    public function test_working_prescreening_leads_keep_access_to_the_shared_builder(): void
    {
        $this->lead->update(['is_immigration_case' => false]);
        \App\Models\PreScreening::create(['lead_id' => $this->lead->id, 'subject_type' => Lead::class, 'subject_id' => $this->lead->id, 'status' => 'new']);
        $this->prepare();
        $this->assertDatabaseCount('lead_documents', 0);
        Mail::assertNothingOutgoing();
    }

    public function test_standard_immigration_pack_retains_its_three_supporting_documents(): void
    {
        \App\Models\VisaType::create(['code' => 'workflow_visitor', 'name' => 'Sample Visa', 'category' => 'Visitor', 'active' => true, 'professional_fees' => 900, 'inz_application_fee' => 441]);
        $this->lead->update(['inz_visa_type' => 'Sample Visa']);
        $data = $this->settings('immigration_advice');
        $data['types'] = ['written_agreement', 'invoice', 'professional_standards', 'code_of_conduct', 'complaints_procedure'];
        $p = $this->postJson("/admin/leads/{$this->lead->id}/engagement/prepare", $data)->assertOk()->json();
        $this->assertCount(5, $p['documents']);
        $this->assertEquals(1341, $p['total']);
        $this->postJson("/admin/leads/{$this->lead->id}/engagement/save", ['token' => $p['token']])->assertOk();
        $this->assertDatabaseCount('lead_documents', 5);
        Mail::assertNothingOutgoing();
    }
}
