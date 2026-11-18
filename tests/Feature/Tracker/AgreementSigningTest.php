<?php

namespace Tests\Feature\Tracker;

use App\Models\Agreement;
use App\Models\AgreementTemplate;
use App\Models\Lead;
use App\Models\User;
use App\Services\PdfGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Build 11.D Phase 3 — Tracker signing flow.
 *
 * Verifies the visibility / routing / status-flip behaviour of the
 * public signing endpoints. The signature-capture audit fields are
 * covered by SignatureCaptureTest. PdfGenerator is fake-bound to avoid
 * actually invoking dompdf when the signed PDF is regenerated.
 */
class AgreementSigningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(PdfGenerator::class, fn () => new class extends PdfGenerator {
            public function renderToFile(string $html, string $relativePath, string $paper = 'a4'): string
            {
                return $relativePath; // skip dompdf
            }
        });
    }

    private function makeCase(array $attrs = []): Lead
    {
        return Lead::create(array_merge([
            'first_name'          => 'Maria',
            'last_name'           => 'Cruz',
            'email'               => 'maria@example.test',
            'is_immigration_case' => true,
        ], $attrs));
    }

    private function makeAgreement(Lead $case, string $status = 'sent', array $extra = []): Agreement
    {
        $template = AgreementTemplate::firstOrCreate(
            ['name' => 'Test Template'],
            ['body' => 'Hello {{client_name}}', 'is_active' => true],
        );
        return Agreement::create(array_merge([
            'lead_id'               => $case->id,
            'agreement_template_id' => $template->id,
            'generated_by_user_id'  => User::factory()->create(['role' => 'immigration'])->id,
            'title'                 => 'Test Agreement',
            'content_rendered'      => 'Hello Maria',
            'status'                => $status,
            'tracker_signing_token' => str_repeat('a', 48),
            'sent_at'               => $status === 'draft' ? null : now(),
        ], $extra));
    }

    public function test_agreement_does_not_show_on_tracker_when_draft(): void
    {
        $case = $this->makeCase();
        $this->makeAgreement($case, 'draft', ['tracker_signing_token' => null, 'sent_at' => null]);

        $this->actingAs(User::factory()->create(['role' => 'lead', 'lead_id' => $case->id]))
            ->get('/portal/lead/tracker')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('agreements', 0));
    }

    public function test_agreement_shows_on_tracker_when_sent(): void
    {
        $case = $this->makeCase();
        $this->makeAgreement($case, 'sent');

        $this->actingAs(User::factory()->create(['role' => 'lead', 'lead_id' => $case->id]))
            ->get('/portal/lead/tracker')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('agreements', 1)
                ->where('agreements.0.status', 'sent')
                ->where('agreements.0.title', 'Test Agreement')
            );
    }

    public function test_agreement_does_not_show_when_voided(): void
    {
        $case = $this->makeCase();
        $this->makeAgreement($case, 'voided');

        $this->actingAs(User::factory()->create(['role' => 'lead', 'lead_id' => $case->id]))
            ->get('/portal/lead/tracker')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('agreements', 0));
    }

    public function test_invalid_token_returns_404(): void
    {
        $case = $this->makeCase();
        $this->makeAgreement($case, 'sent');

        $this->get("/track/{$case->tracking_code}/agreements/" . str_repeat('z', 48) . '/sign')
            ->assertNotFound();
    }

    public function test_client_cannot_select_another_cases_agreement_with_query_parameters(): void
    {
        $own = $this->makeCase();
        $other = $this->makeCase(['email' => 'other@example.test']);
        $this->makeAgreement($other, 'sent');

        $this->actingAs(User::factory()->create(['role' => 'lead', 'lead_id' => $own->id]))
            ->get("/portal/lead/tracker?lead_id={$other->id}&code={$other->tracking_code}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('lead.tracking_code', $own->tracking_code)->has('agreements', 0)
            );
    }

    public function test_token_for_different_lead_returns_404(): void
    {
        $caseA = $this->makeCase();
        $caseB = $this->makeCase(['email' => 'b@example.test']);
        $agreement = $this->makeAgreement($caseA, 'sent');

        // Use case B's code with case A's token — cross-lead URL guess.
        $this->get("/track/{$caseB->tracking_code}/agreements/{$agreement->tracker_signing_token}/sign")
            ->assertNotFound();
    }

    public function test_viewing_signing_page_marks_agreement_as_viewed(): void
    {
        $case = $this->makeCase();
        $agreement = $this->makeAgreement($case, 'sent');

        $this->get("/track/{$case->tracking_code}/agreements/{$agreement->tracker_signing_token}/sign")
            ->assertOk();

        $this->assertSame('viewed', $agreement->fresh()->status);
        $this->assertNotNull($agreement->fresh()->viewed_at);
    }

    public function test_viewing_again_does_not_reset_viewed_at(): void
    {
        $case = $this->makeCase();
        $agreement = $this->makeAgreement($case, 'viewed', ['viewed_at' => now()->subDay()]);
        $original = $agreement->viewed_at;

        $this->get("/track/{$case->tracking_code}/agreements/{$agreement->tracker_signing_token}/sign")
            ->assertOk();

        // Status stays 'viewed', viewed_at unchanged (set once on first view).
        $this->assertSame('viewed', $agreement->fresh()->status);
        $this->assertTrue($original->equalTo($agreement->fresh()->viewed_at));
    }

    public function test_client_can_sign_via_tracker(): void
    {
        $case = $this->makeCase();
        $agreement = $this->makeAgreement($case, 'sent');

        $response = $this->post(
            "/track/{$case->tracking_code}/agreements/{$agreement->tracker_signing_token}/sign",
            [
                'signer_name'    => 'Maria Cruz',
                'signature_data' => 'data:image/png;base64,iVBORw0KGgo=',
                'terms_accepted' => 1,
            ]
        );

        $response->assertRedirect("/track/{$case->tracking_code}/agreements/{$agreement->tracker_signing_token}/signed");
        $this->assertSame('signed', $agreement->fresh()->status);
    }

    public function test_signed_confirmation_page_renders(): void
    {
        $case = $this->makeCase();
        $agreement = $this->makeAgreement($case, 'signed', [
            'signed_at'   => now(),
            'signer_name' => 'Maria Cruz',
        ]);

        $this->get("/track/{$case->tracking_code}/agreements/{$agreement->tracker_signing_token}/signed")
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('track/AgreementSigned')
                ->where('agreement.signer_name', 'Maria Cruz')
            );
    }

    public function test_confirmation_returns_404_when_not_yet_signed(): void
    {
        $case = $this->makeCase();
        $agreement = $this->makeAgreement($case, 'sent');

        $this->get("/track/{$case->tracking_code}/agreements/{$agreement->tracker_signing_token}/signed")
            ->assertNotFound();
    }
}
