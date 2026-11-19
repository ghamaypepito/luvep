<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Consultancy Agreement Verification screen reviews the agreement as one
 * DOCUMENT (total amount + note + overall status), and fee-item labels must
 * always render as a friendly name — even when an older review snapshotted the
 * raw key (e.g. "school_enrolment").
 */
class ConsultancyVerificationDocumentViewTest extends TestCase
{
    use RefreshDatabase;

    private function pendingReviewLead(): Lead
    {
        return Lead::create([
            'first_name' => 'Angelika', 'last_name' => 'Libanan', 'email' => 'a@example.com',
            'consultancy_review' => [
                'status' => 'pending',
                'scenario' => 'consultancy_std_100',
                'scenario_label' => 'Standard — 100,000',
                'currency' => 'php',
                'item_keys' => ['school_enrolment'],
                'items' => [
                    // Stored with the RAW KEY as its label (legacy data).
                    'school_enrolment' => ['label' => 'school_enrolment', 'amount' => 100000, 'status' => 'needs_check'],
                ],
            ],
        ]);
    }

    public function test_raw_key_label_resolves_to_friendly_name_and_note_is_exposed(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        $lead = $this->pendingReviewLead();

        $this->get('/consultancy-verification')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('proposals.0.items.0.label', 'School Enrolment & Documentation')
                ->where('proposals.0.total_amount', 100000)
                ->has('proposals.0.note')
            );
    }

    public function test_document_level_note_saves(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        $lead = $this->pendingReviewLead();

        $this->post("/consultancy-verification/{$lead->id}/meta", [
            'review_note' => 'Checked the total — looks right.',
        ])->assertSessionHasNoErrors();

        $lead->refresh();
        $this->assertSame('Checked the total — looks right.', $lead->consultancy_review['note']);
    }

    public function test_english_verification_uses_only_review_fee_and_keeps_exam_usd_separate(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Client']);
        \App\Services\ConsultancyReviewService::submit($lead, 'english_engagement', ['english_fee' => 12500, 'pte_fee' => 240], null);
        $this->get('/consultancy-verification')->assertInertia(fn ($page) => $page
            ->where('proposals.0.total_amount', 12500)
            ->where('proposals.0.items.1.currency_symbol', 'US$')
            ->where('proposals.0.items.1.amount', 240));
        $this->assertSame(12500, \App\Services\ConsultancyReviewService::totalAmount($lead->fresh()->consultancy_review));
        $this->get('/admin/leads/proposals-agreements')->assertInertia(fn ($page) => $page
            ->where('agreements.0.documents.0.total_amount', 12500));
    }
}
