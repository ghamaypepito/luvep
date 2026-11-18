<?php

namespace Tests\Feature\Education;

use App\Models\EducationRecipientList;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RecipientListsTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_save_filter_rules_and_manual_members_without_sending_mail(): void
    {
        Queue::fake();
        $staff = User::factory()->create(['role' => 'education']);
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Client', 'email' => 'synthetic@example.test']);
        $lead->forceFill(['created_at' => '2026-11-16 16:00:00'])->save();
        $this->actingAs($staff)->post('/portal/education/bulk-email/recipient-lists', [
            'name' => 'November follow-ups', 'mode' => 'smart',
            'filters' => ['registered_from' => '2026-11-17', 'registered_to' => '2026-11-17'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $smart = EducationRecipientList::sole();
        $this->assertSame('2026-11-17', $smart->filters['registered_from']);
        $this->assertSame([], $smart->lead_ids);
        $this->actingAs($staff)->post('/portal/education/bulk-email/recipient-lists', [
            'name' => 'Selected clients', 'mode' => 'custom', 'filters' => [], 'lead_ids' => [(string) $lead->id],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([$lead->id], EducationRecipientList::where('mode', 'custom')->sole()->lead_ids);
        $this->get('/portal/education/bulk-email')->assertOk()->assertInertia(fn ($page) => $page
            ->has('recipientLists', 2)->where('recipients.0.registered_on', '2026-11-17'));
        Queue::assertNothingPushed();
    }

    public function test_lists_are_private_and_only_the_owner_can_update_them(): void
    {
        $owner = User::factory()->create(['role' => 'education']);
        $other = User::factory()->create(['role' => 'education']);
        $list = EducationRecipientList::create(['created_by' => $owner->id, 'name' => 'Private selection', 'mode' => 'smart', 'filters' => [], 'lead_ids' => []]);
        $this->actingAs($other)->get('/portal/education/bulk-email')->assertInertia(fn ($page) => $page->has('recipientLists', 0));
        $this->post('/portal/education/bulk-email/recipient-lists', [
            'list_id' => $list->id, 'name' => 'Overwrite', 'mode' => 'smart', 'filters' => [],
        ])->assertNotFound();
        $this->assertSame('Private selection', $list->fresh()->name);
        $this->actingAs($owner)->post('/portal/education/bulk-email/recipient-lists', [
            'list_id' => $list->id, 'name' => 'Updated selection', 'mode' => 'smart', 'filters' => ['search' => 'synthetic'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['search' => 'synthetic'], $list->fresh()->filters);
        $this->assertDatabaseCount('education_recipient_lists', 1);
    }

    public function test_invalid_filters_members_and_other_portal_writes_are_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'education']))
            ->post('/portal/education/bulk-email/recipient-lists', [
                'name' => 'Invalid', 'mode' => 'custom', 'lead_ids' => [999999],
                'filters' => ['registered_from' => '2026-11-18', 'registered_to' => '2026-11-17'],
            ])->assertSessionHasErrors(['lead_ids', 'filters.registered_to']);
        $this->post('/portal/education/bulk-email/recipient-lists', ['list_id' => ['invalid']])->assertSessionHasErrors('list_id');
        $this->actingAs(User::factory()->create(['role' => 'sales']))
            ->post('/portal/education/bulk-email/recipient-lists', ['name' => 'Invalid', 'mode' => 'smart', 'filters' => []])->assertForbidden();
        $this->assertDatabaseCount('education_recipient_lists', 0);
    }
}
