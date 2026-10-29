<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StaffDiscussionAccessTest extends TestCase
{
    use RefreshDatabase;

    public static function accessibleRecords(): array
    {
        $rows = [];
        foreach (['super_admin', 'admin', 'sales', 'education', 'immigration', 'immigration_manager', 'immigration_adviser'] as $role) {
            $rows["$role pipeline"] = [$role, [], [], '/admin'];
        }
        foreach (['education', 'immigration', 'immigration_manager', 'immigration_adviser'] as $role) {
            $rows["$role student"] = [$role, [], ['is_student' => true], '/admin'];
            $rows["$role legacy English register"] = [$role, [], ['stage' => 'English Pro'], '/admin'];
        }
        foreach (['immigration', 'immigration_manager', 'immigration_adviser'] as $role) {
            $rows["$role case"] = [$role, [], ['is_immigration_case' => true], '/admin'];
        }
        $rows['English learner'] = ['english', [], ['is_english_student' => true], '/admin'];
        $rows['Accommodation client'] = ['accommodation', [], ['is_accommodation_client' => true], '/admin'];
        $rows['Existing Education grant'] = ['accommodation', ['education', 'immigration'], [], '/admin'];
        $rows['Existing Immigration grant'] = ['accommodation', ['education', 'immigration'], ['is_immigration_case' => true], '/admin'];
        $rows['Finance with Education grant'] = ['finance', ['education'], ['is_student' => true], '/admin'];
        // These are the actual URLs used by the inline lead-list composers.
        $rows['Immigration inline note'] = ['immigration', [], [], '/portal/immigration'];
        $rows['Manager inline note'] = ['immigration_manager', [], [], '/portal/immigration'];
        $rows['Adviser inline note'] = ['immigration_adviser', [], [], '/portal/immigration-adviser'];

        return $rows;
    }

    #[DataProvider('accessibleRecords')]
    public function test_staff_can_post_and_reply_to_notes_on_records_in_their_existing_lists(string $role, array $grants, array $attributes, string $base): void
    {
        $user = User::factory()->create(['role' => $role, 'portal_access' => $grants]);
        $lead = Lead::create(array_merge(['first_name' => 'Discussion', 'last_name' => 'Test'], $attributes));
        $this->actingAs($user)->post("$base/leads/{$lead->id}/notes", ['body' => 'Team follow-up'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $root = $lead->notes()->firstOrFail();
        $this->post("$base/leads/{$lead->id}/notes", ['body' => 'Team reply', 'parent_id' => $root->id])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('lead_notes', ['lead_id' => $lead->id, 'parent_id' => $root->id, 'user_id' => $user->id, 'body' => 'Team reply']);
        $this->getJson("/admin/leads/{$lead->id}/notes")->assertOk()->assertJsonCount(2, 'notes');
    }

    #[DataProvider('accessibleRecords')]
    public function test_staff_can_read_and_send_client_conversation_on_records_in_their_existing_lists(string $role, array $grants, array $attributes): void
    {
        Mail::fake();
        $user = User::factory()->create(['role' => $role, 'portal_access' => $grants]);
        $lead = Lead::create(array_merge(['first_name' => 'Discussion', 'last_name' => 'Test', 'email' => 'discussion@example.test'], $attributes));
        $this->actingAs($user)->getJson("/admin/leads/{$lead->id}/communications")->assertOk();
        $this->getJson("/admin/leads/{$lead->id}/compose/templates")->assertOk();
        $this->postJson("/admin/leads/{$lead->id}/communications", ['subject' => 'Follow-up', 'body' => '<p>Hello</p>'])
            ->assertOk()->assertJsonPath('ok', true);
        $this->getJson("/admin/leads/{$lead->id}/communications")
            ->assertOk()->assertJsonPath('data.0.subject', 'Follow-up');
        $this->assertDatabaseHas('message_logs', ['recipient_id' => $lead->id, 'recipient_address' => $lead->email, 'triggered_by_user_id' => $user->id]);
    }

    public static function inaccessibleRecords(): array
    {
        return [
            'sales cannot discuss pure case' => ['sales', [], ['is_immigration_case' => true]],
            'education cannot discuss pure case' => ['education', [], ['is_immigration_case' => true]],
            'immigration cannot discuss accommodation' => ['immigration', [], ['is_accommodation_client' => true]],
            'English cannot discuss general pipeline' => ['english', [], []],
            'accommodation cannot discuss general pipeline' => ['accommodation', [], []],
            'finance needs an existing client portal grant' => ['finance', [], []],
            'client cannot use staff grant' => ['lead', ['education', 'immigration'], []],
            'revoked client cannot use staff grant' => ['revoked_lead', ['education'], []],
            'agent cannot use staff grant' => ['agent', ['education'], []],
            'sub agent cannot use staff grant' => ['sub_agent', ['education'], []],
        ];
    }

    #[DataProvider('inaccessibleRecords')]
    public function test_discussion_does_not_grant_access_to_unrelated_clients(string $role, array $grants, array $attributes): void
    {
        Mail::fake();
        $user = User::factory()->create(['role' => $role, 'portal_access' => $grants]);
        $lead = Lead::create(array_merge(['first_name' => 'Private', 'last_name' => 'Client', 'email' => 'private@example.test'], $attributes));
        $this->actingAs($user)->getJson("/admin/leads/{$lead->id}/notes")->assertForbidden();
        $this->post("/admin/leads/{$lead->id}/notes", ['body' => 'Unauthorized'])->assertForbidden();
        $this->getJson("/admin/leads/{$lead->id}/communications")->assertForbidden();
        $this->getJson("/admin/leads/{$lead->id}/compose/templates")->assertForbidden();
        $this->postJson("/admin/leads/{$lead->id}/communications", ['body' => 'Unauthorized'])->assertForbidden();
        $this->assertFalse($lead->notes()->exists());
        $this->assertDatabaseCount('message_logs', 0);
        Mail::assertNothingOutgoing();
    }
}
