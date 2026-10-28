<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EducationProfileNotesTest extends TestCase
{
    use RefreshDatabase;

    public static function educationRecords(): array
    {
        return [
            'pipeline lead' => [[]],
            'education student' => [['is_student' => true]],
            'English learner' => [['is_english_student' => true, 'english_stage' => 'Enrolled']],
            'legacy English learner' => [['is_english_student' => true, 'stage' => 'English Pro']],
            'student handed to immigration' => [['is_student' => true, 'is_immigration_case' => true]],
        ];
    }

    #[DataProvider('educationRecords')]
    public function test_education_can_post_and_reply_on_records_in_its_lists(array $attributes): void
    {
        $emma = User::factory()->create(['role' => 'education', 'module_permissions' => []]);
        $lead = Lead::create(array_merge(['first_name' => 'Test', 'last_name' => 'Education'], $attributes));
        $this->assertTrue(Lead::whereKey($lead->id)->where(fn ($query) => $query
            ->where(fn ($pipeline) => $pipeline->inLeadPipeline())
            ->orWhere(fn ($students) => $students->inStudentsRegister()))->exists());

        $this->actingAs($emma)->post("/admin/leads/{$lead->id}/notes", ['body' => 'Education follow-up'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $root = $lead->notes()->firstOrFail();
        $this->assertSame($emma->id, $root->user_id);

        $this->post("/portal/education/leads/{$lead->id}/notes", ['body' => 'Follow-up reply', 'parent_id' => $root->id])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('lead_notes', ['lead_id' => $lead->id, 'parent_id' => $root->id, 'user_id' => $emma->id, 'body' => 'Follow-up reply']);
        $this->getJson("/admin/leads/{$lead->id}/notes")->assertOk()->assertJsonCount(2, 'notes');
        $this->post("/admin/leads/{$lead->id}/notes/{$root->id}", ['body' => 'Updated follow-up'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Updated follow-up', $root->fresh()->body);
    }

    public function test_education_cannot_read_post_or_reply_on_unrelated_department_records(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'education']));
        foreach (['is_immigration_case', 'is_accommodation_client'] as $flag) {
            $lead = Lead::create(['first_name' => 'Other', 'last_name' => 'Department', $flag => true]);
            $parent = LeadNote::create(['lead_id' => $lead->id, 'body' => 'Private department note']);
            $this->getJson("/admin/leads/{$lead->id}/notes")->assertForbidden();
            foreach ([null, $parent->id] as $parentId) {
                $this->post("/admin/leads/{$lead->id}/notes", ['body' => 'Blocked', 'parent_id' => $parentId])->assertForbidden();
            }
            $this->assertSame(1, $lead->notes()->count());
        }
    }

    public function test_education_cannot_reply_to_a_note_from_a_different_profile(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'education']));
        $lead = Lead::create(['first_name' => 'Test', 'last_name' => 'One']);
        $other = Lead::create(['first_name' => 'Test', 'last_name' => 'Two']);
        $parent = LeadNote::create(['lead_id' => $other->id, 'body' => 'Different profile']);
        $this->post("/admin/leads/{$lead->id}/notes", ['body' => 'Wrong parent', 'parent_id' => $parent->id])
            ->assertSessionHasErrors('parent_id');
        $this->assertFalse($lead->notes()->exists());
    }
}
