<?php
namespace Tests\Feature\Ai;
use App\Models\{User, Lead};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class AssistantPanelTest extends TestCase
{
    use RefreshDatabase;
    public function test_panel_context_uses_existing_record_access_boundary(): void
    {
        $lead = Lead::create(['first_name'=>'Private','last_name'=>'Record','is_immigration_case'=>true]);
        $this->actingAs(User::factory()->create(['role'=>'sales']))->getJson('/assistant?subject_id='.$lead->id)
            ->assertOk()->assertJsonPath('subject',null)->assertJsonStructure(['aiEnabled']);
        $this->actingAs(User::factory()->create(['role'=>'admin']))->getJson('/assistant?subject_id='.$lead->id)
            ->assertOk()->assertJsonPath('subject.id',$lead->id);
    }
    public function test_clients_cannot_fetch_panel_context(): void
    {
        $this->actingAs(User::factory()->create(['role'=>'lead']))->getJson('/assistant')->assertForbidden();
    }
}
