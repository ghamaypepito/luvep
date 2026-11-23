<?php

namespace Tests\Feature\Immigration;

use App\Models\FinanceReceivable;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EngagementDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function pack(Lead $lead, User $creator, string $pack, bool $sent = false): array
    {
        $documents = [];
        foreach (['engagement:written_agreement', 'invoice'] as $variant) {
            $path = 'synthetic/'.$pack.str_replace(':', '-', $variant).'.pdf';
            Storage::disk('local')->put($path, '%PDF synthetic document');
            $documents[] = LeadDocument::create([
                'lead_id' => $lead->id, 'uploaded_by' => $creator->id,
                'original_name' => basename($path), 'file_path' => $path, 'mime' => 'application/pdf', 'size' => 23,
                'source' => 'generated', 'source_variant' => $variant, 'status' => 'Submitted',
                'notes' => ['engagement_pack_id' => $pack, 'engagement_sent_at' => $sent ? now()->toIso8601String() : null],
            ]);
        }

        return $documents;
    }

    public function test_manager_can_delete_a_colleagues_unlinked_pack_without_touching_other_packs(): void
    {
        Storage::fake('local');
        $manager = User::factory()->create(['role' => 'immigration_manager']);
        $creator = User::factory()->create(['role' => 'admin']);
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Engagement', 'is_immigration_case' => true]);
        $removed = $this->pack($lead, $creator, 'selected-pack');
        $kept = $this->pack($lead, $creator, 'other-pack');
        $this->actingAs($manager)->from('/portal/immigration/cases/engagement')
            ->delete('/admin/leads/'.$lead->id.'/engagement/documents?pack_id=selected-pack')->assertRedirect()->assertSessionHasNoErrors();
        foreach ($removed as $doc) {
            $this->assertModelMissing($doc);
            Storage::disk('local')->assertMissing($doc->file_path);
        }
        foreach ($kept as $doc) {
            $this->assertModelExists($doc);
            Storage::disk('local')->assertExists($doc->file_path);
        }
    }

    public function test_sent_pack_linked_to_finance_reports_the_actual_reason_and_preserves_all_evidence(): void
    {
        Storage::fake('local');
        $creator = User::factory()->create(['role' => 'admin']);
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Issued', 'is_immigration_case' => true]);
        $docs = $this->pack($lead, $creator, 'sent-pack', true);
        FinanceReceivable::create(['invoice_document_id' => $docs[1]->id, 'lead_id' => $lead->id,
            'invoice_no' => 'SYNTHETIC-ISSUED', 'client_name' => 'Synthetic Issued', 'amount' => 100, 'currency' => 'NZD', 'status' => 'sent',
            'issue_date' => now()->toDateString(), 'due_date' => now()->addDays(7)->toDateString()]);
        $this->actingAs(User::factory()->create(['role' => 'immigration_manager']))
            ->deleteJson('/admin/leads/'.$lead->id.'/engagement/documents?pack_id=sent-pack')
            ->assertUnprocessable()->assertJsonValidationErrors('document')
            ->assertJsonPath('errors.document.0', 'This issued invoice is linked to Finance. Ask Finance to void it if it was issued in error.');
        foreach ($docs as $doc) {
            $this->assertModelExists($doc);
            Storage::disk('local')->assertExists($doc->file_path);
        }
    }

    public function test_sent_unlinked_pack_can_be_deleted_by_another_team_member(): void
    {
        Storage::fake('local');
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Sent', 'is_immigration_case' => true]);
        $docs = $this->pack($lead, User::factory()->create(['role' => 'admin']), 'unlinked-sent', true);
        $this->actingAs(User::factory()->create(['role' => 'immigration_manager']))
            ->delete('/admin/leads/'.$lead->id.'/engagement/documents?pack_id=unlinked-sent')->assertRedirect()->assertSessionHasNoErrors();
        foreach ($docs as $doc) {
            $this->assertModelMissing($doc);
        }
    }

    public function test_client_and_agent_cannot_delete_engagement_documents(): void
    {
        Storage::fake('local');
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Protected', 'is_immigration_case' => true]);
        $docs = $this->pack($lead, User::factory()->create(['role' => 'admin']), 'private-pack');
        foreach (['lead', 'agent'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->deleteJson('/admin/leads/'.$lead->id.'/engagement/documents?pack_id=private-pack')->assertForbidden();
        }
        foreach ($docs as $doc) {
            $this->assertModelExists($doc);
            Storage::disk('local')->assertExists($doc->file_path);
        }
    }
}
