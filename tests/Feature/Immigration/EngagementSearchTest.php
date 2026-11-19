<?php

namespace Tests\Feature\Immigration;

use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EngagementSearchTest extends TestCase
{
    use RefreshDatabase;

    private function document(Lead $lead, string $variant = 'engagement:written_agreement'): LeadDocument
    {
        return LeadDocument::create(['lead_id' => $lead->id, 'original_name' => 'Agreement.pdf', 'file_path' => 'test/fixture.pdf', 'mime' => 'application/pdf', 'size' => 1, 'source_variant' => $variant, 'status' => 'Submitted']);
    }

    public function test_search_stays_in_engagement_and_finds_older_records_before_the_limit(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => 'immigration_manager']));
        $target = Lead::create(['lead_id' => 'REG-SEARCH-42', 'first_name' => 'Nova', 'middle_name' => 'Sample', 'last_name' => 'Target', 'email' => 'findme@example.test', 'phone' => '+6495550199', 'is_immigration_case' => true]);
        $this->document($target);
        $other = Lead::create(['first_name' => 'Unrelated', 'last_name' => 'Recent', 'is_immigration_case' => true]);
        $row = $this->document($other)->getAttributes();
        unset($row['id']);
        LeadDocument::insert(array_fill(0, 301, $row));
        $ordinary = Lead::create(['first_name' => 'Nova', 'last_name' => 'Target']);
        $this->document($ordinary, 'upload');
        foreach (['Nova Target', 'findme@example.test', '5550199', $target->lead_id] as $term) {
            $this->get('/portal/immigration/cases/engagement?q='.urlencode($term))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('portal/immigration/Engagement')->where('search', $term)->has('generated', 1)->where('generated.0.case_id', $target->id));
        }
        $this->get('/portal/immigration/cases/engagement?q=NotFound')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('generated', 0)->where('search', 'NotFound'));
        $this->get('/portal/immigration/cases/engagement')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('search', '')->has('generated', 1)->where('generated.0.case_id', $other->id));
    }

    public function test_search_validation_and_existing_role_boundary_are_retained(): void
    {
        $this->withoutVite();
        $url = '/portal/immigration/cases/engagement';
        $this->get($url.'?q=anything')->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['role' => 'lead']))->get($url.'?q=anything')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'immigration_manager']))
            ->getJson($url.'?q='.str_repeat('a', 151))->assertUnprocessable()->assertJsonValidationErrors('q');
    }
}
