<?php

namespace Tests\Feature\Dtr;

use App\Models\AccommodationDtrTask;
use App\Models\DtrEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccommodationSharedTasksTest extends TestCase
{
    use RefreshDatabase;

    private const DTR = '/portal/accommodation/dtr';

    public function test_both_members_can_share_and_complete_tasks_without_mutating_personal_records(): void
    {
        $this->withoutVite();
        $janille = User::factory()->create(['role' => 'accommodation', 'name' => 'Janille']);
        $alaine = User::factory()->create(['role' => 'accommodation', 'name' => 'Alaine']);
        config(['accommodation.shared_dtr_members' => [$janille->id, $alaine->id]]);
        $path = self::DTR.'/shared-tasks';
        $payload = ['title' => 'Arrange viewing', 'assignee_id' => $janille->id, 'status' => 'pending'];

        $this->actingAs($janille)->from(self::DTR)->post($path, $payload)->assertSessionHasNoErrors()->assertRedirect(self::DTR);
        $task = AccommodationDtrTask::sole();
        $this->actingAs($alaine)->get(self::DTR)->assertOk()->assertInertia(fn ($page) => $page
            ->where('sharedAccommodationDtr.tasks.0.id', $task->id));
        $this->patchJson("$path/{$task->id}", [...$payload, 'assignee_id' => $alaine->id, 'status' => 'ongoing', 'version' => 0])
            ->assertRedirect();
        $this->postJson("$path/{$task->id}/complete", ['version' => 0])->assertUnprocessable()->assertJsonValidationErrors('version');

        $this->actingAs($janille)->post("$path/{$task->id}/complete", ['version' => 1, 'completed_by' => $alaine->id])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($alaine)->post("$path/{$task->id}/complete", ['version' => 1])->assertRedirect();
        $this->get(self::DTR)->assertOk()->assertInertia(fn ($page) => $page
            ->has('sharedAccommodationDtr.tasks', 0)
            ->where('sharedAccommodationDtr.completed.0.completed_by_name', 'Janille'));
        $this->assertSame($janille->id, $task->fresh()->completed_by);
        $this->assertSame($alaine->id, $task->fresh()->assignee_id);
        $this->patchJson("$path/{$task->id}", [...$payload, 'version' => 2])->assertUnprocessable();
        $this->post($path, [...$payload, 'assignee_id' => $alaine->id])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('accommodation_dtr_tasks', 2);
        $this->assertSame(0, DtrEntry::count());
    }

    public function test_unconfigured_and_unlisted_accounts_cannot_access_or_write_shared_tasks(): void
    {
        $this->withoutVite();
        $member = User::factory()->create(['role' => 'accommodation']);
        $partner = User::factory()->create(['role' => 'accommodation']);
        $outsider = User::factory()->create(['role' => 'accommodation']);
        $path = self::DTR.'/shared-tasks';

        $this->actingAs($member)->post($path)->assertForbidden();
        config(['accommodation.shared_dtr_members' => [$member->id, $partner->id]]);
        $this->actingAs($outsider)->get(self::DTR)->assertOk()->assertInertia(fn ($page) => $page->where('sharedAccommodationDtr', null));
        $this->post($path)->assertForbidden();
        $this->patch("$path/1")->assertForbidden();
        $this->post("$path/1/complete")->assertForbidden();
        $this->actingAs($member)->postJson($path, ['title' => 'Example', 'status' => 'pending', 'assignee_id' => $outsider->id])
            ->assertUnprocessable()->assertJsonValidationErrors('assignee_id');
        $this->get('/portal/education/dtr')->assertOk()->assertInertia(fn ($page) => $page->where('sharedAccommodationDtr', null));
        $this->assertDatabaseCount('accommodation_dtr_tasks', 0);
    }
}
