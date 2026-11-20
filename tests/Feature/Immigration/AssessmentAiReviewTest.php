<?php

namespace Tests\Feature\Immigration;

use App\Models\AssessmentAiReview;
use App\Models\User;
use App\Models\WorkIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AI completeness/consistency review of a visa-assessment intake. Internal and
 * indicative — observations for the adviser, never eligibility advice. Verifies
 * the endpoint runs the model, stores an auditable row, returns the observation
 * shape, and degrades gracefully with no key.
 */
class AssessmentAiReviewTest extends TestCase
{
    use RefreshDatabase;

    private function intake(): WorkIntake
    {
        return WorkIntake::create([
            'intake_id' => 'WRK-TEST-1', 'first_name' => 'Ravi', 'family_name' => 'Sharma',
            'dob' => '1992-04-10', 'email' => 'ravi@example.test', 'phone' => '+64 21 555 0000',
        ]);
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => 'immigration']);
    }

    public function test_it_runs_stores_and_returns_observations(): void
    {
        config(['services.openrouter.api_key' => 'test-key', 'services.openrouter.model' => 'test/model']);

        // Fake the OpenRouter chat-completions response with a full adviser pack.
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'summary' => 'A 33-year-old software developer.',
                            'observations' => [
                                ['severity' => 'check', 'field' => 'Passport number', 'note' => 'Not provided.'],
                                ['severity' => 'bogus', 'field' => 'Dob', 'note' => 'Looks fine.'], // coerced to info
                                ['field' => 'x'], // no note → dropped
                            ],
                            'risks' => [
                                ['severity' => 'check', 'area' => 'Employment', 'note' => 'Verify continuity of employment.'],
                            ],
                            'checklist' => [
                                ['document' => 'Passport', 'required' => true, 'note' => 'Bio page.'],
                                ['note' => 'no document name → dropped'],
                            ],
                            'adviser_note' => 'Draft note for the adviser to edit.',
                            'client_email' => ['subject' => 'Assessment received', 'body' => 'Thank you — an adviser will review.'],
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $intake = $this->intake();

        $this->actingAs($this->staff())
            ->postJson("/portal/immigration/assessments/work/{$intake->id}/ai-review")
            ->assertOk()
            ->assertJsonPath('review.summary', 'A 33-year-old software developer.')
            ->assertJsonPath('review.observations.0.severity', 'check')
            ->assertJsonPath('review.observations.1.severity', 'info') // coerced
            ->assertJsonPath('review.risks.0.area', 'Employment')
            ->assertJsonPath('review.checklist.0.document', 'Passport')
            ->assertJsonPath('review.adviser_note', 'Draft note for the adviser to edit.')
            ->assertJsonPath('review.client_email.subject', 'Assessment received');

        // Stored + auditable, invalid rows filtered from each section.
        $review = AssessmentAiReview::firstOrFail();
        $this->assertSame(WorkIntake::class, $review->intakeable_type);
        $this->assertSame($intake->id, $review->intakeable_id);
        $this->assertNotNull($review->reviewed_by);
        $this->assertSame('test/model', $review->model);
        $this->assertCount(2, $review->observations); // 3rd (no note) dropped
        $this->assertCount(1, $review->risks);
        $this->assertCount(1, $review->checklist); // 2nd (no document) dropped
    }

    public function test_resident_private_staff_reason_is_not_sent_to_the_ai_provider(): void
    {
        config(['services.openrouter.api_key' => 'test-key', 'services.openrouter.model' => 'test/model']);
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => '{"summary":"Synthetic review"}']]],
        ])]);
        $intake = \App\Models\ResidentIntake::create([
            'intake_id' => 'RI-AI-STAFF', 'first_name' => 'Synthetic', 'last_name' => 'Applicant',
            'email' => 'synthetic@example.test', 'english_evidence' => 'Not Required',
            'english_evidence_exception' => ['reason' => 'PRIVATE_STAFF_REASON', 'actor_name' => 'PRIVATE_STAFF_ACTOR'],
        ]);
        $this->actingAs($this->staff())->postJson("/portal/immigration/assessments/resident/{$intake->id}/ai-review")->assertOk();
        Http::assertSent(function ($request) {
            $payload = json_encode($request->data());

            return ! str_contains($payload, 'PRIVATE_STAFF_REASON') && ! str_contains($payload, 'PRIVATE_STAFF_ACTOR');
        });
        $this->assertSame('Not Required', $intake->fresh()->english_evidence);
    }

    public function test_show_returns_the_latest_stored_review(): void
    {
        $intake = $this->intake();
        AssessmentAiReview::create([
            'intakeable_type' => WorkIntake::class, 'intakeable_id' => $intake->id,
            'provider' => 'openrouter', 'model' => 'test/model',
            'observations' => [['severity' => 'info', 'field' => 'x', 'note' => 'ok']],
            'summary' => 'stored',
        ]);

        $this->actingAs($this->staff())
            ->getJson("/portal/immigration/assessments/work/{$intake->id}/ai-review")
            ->assertOk()
            ->assertJsonPath('review.summary', 'stored');
    }

    public function test_it_degrades_gracefully_without_a_key(): void
    {
        config(['services.openrouter.api_key' => '']);
        $intake = $this->intake();

        $this->actingAs($this->staff())
            ->postJson("/portal/immigration/assessments/work/{$intake->id}/ai-review")
            ->assertStatus(422);

        $this->assertSame(0, AssessmentAiReview::count());
    }

    public function test_unknown_type_is_rejected(): void
    {
        $this->actingAs($this->staff())
            ->postJson('/portal/immigration/assessments/spouse/1/ai-review')
            ->assertNotFound();
    }
}
