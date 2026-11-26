<?php

namespace Tests\Feature\Agents;

use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReferralCaseAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        Notification::fake();
        Storage::fake('local');
    }

    private function agent(array $extra = []): User
    {
        return User::factory()->create($extra + ['role' => 'agent', 'module_permissions' => ['referral_cases']]);
    }

    private function makeCase(User $agent, string $name): Lead
    {
        return Lead::create(['first_name' => $name, 'last_name' => 'Synthetic', 'agent_id' => $agent->id, 'is_immigration_case' => true, 'immigration_stage' => 'For Assessment']);
    }

    public function test_agent_cases_module_lists_only_own_referrals_and_allows_own_profile(): void
    {
        $agent = $this->agent();
        $own = $this->makeCase($agent, 'Own');
        $other = $this->makeCase($this->agent(), 'Unrelated');
        $this->actingAs($agent)->get('/portal/agent/cases')->assertOk()->assertInertia(fn ($p) => $p->component('portal/agent/Cases')->has('cases', 1)->where('cases.0.id', $own->id));
        $this->get("/portal/agent/cases/{$own->id}/profile")->assertOk();
        $this->get("/portal/agent/cases/{$other->id}/profile")->assertNotFound();
    }

    public function test_module_contains_permitted_writes_and_is_not_a_read_only_grant(): void
    {
        $agent = $this->agent();
        $own = $this->makeCase($agent, 'Own');
        $other = $this->makeCase($this->agent(), 'Unrelated');
        $this->actingAs($agent)->post("/portal/agent/cases/{$own->id}/priority", ['immigration_priority' => 'high'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('high', $own->fresh()->immigration_priority);
        $this->post("/portal/agent/cases/{$other->id}/priority", ['immigration_priority' => 'high'])->assertNotFound();
        $this->assertNotSame('high', $other->fresh()->immigration_priority);
    }

    public function test_no_module_cannot_open_or_write_cases_and_cannot_self_grant(): void
    {
        $agent = $this->agent(['module_permissions' => []]);
        $own = $this->makeCase($agent, 'Own');
        $this->actingAs($agent)->get('/portal/agent/cases')->assertForbidden();
        $this->get("/portal/agent/cases/{$own->id}/profile")->assertForbidden();
        $this->post("/portal/agent/cases/{$own->id}/priority", ['immigration_priority' => 'high'])->assertForbidden();
        $this->post("/admin/module-management/{$agent->id}", ['modules' => ['referral_cases']])->assertForbidden();
        $this->assertSame([], $agent->fresh()->module_permissions);
        $this->get('/portal/immigration/cases')->assertForbidden();
    }

    public function test_super_admin_can_grant_the_existing_module_without_changing_role_or_portals(): void
    {
        $super = User::factory()->create(['role' => 'super_admin']);
        $agent = $this->agent(['module_permissions' => []]);
        $own = $this->makeCase($agent, 'Own');
        $this->actingAs($super)->post("/admin/module-management/{$agent->id}", ['modules' => ['referral_cases']])->assertRedirect()->assertSessionHasNoErrors();
        $agent->refresh();
        $this->assertSame('agent', $agent->role);
        $this->assertEmpty($agent->portal_access);
        $this->actingAs($agent)->get("/portal/agent/cases/{$own->id}/profile")->assertOk();
        $this->get('/portal/immigration/cases')->assertForbidden();
    }

    public function test_private_document_download_is_limited_to_the_referring_agent(): void
    {
        $agent = $this->agent();
        $own = $this->makeCase($agent, 'Own');
        $other = $this->makeCase($this->agent(), 'Unrelated');
        Storage::disk('local')->put('synthetic/own.txt', 'Own synthetic file');
        Storage::disk('local')->put('synthetic/other.txt', 'Unrelated synthetic file');
        $doc = LeadDocument::create(['lead_id' => $own->id, 'original_name' => 'Own.txt', 'file_path' => 'synthetic/own.txt', 'status' => 'Approved']);
        $otherDoc = LeadDocument::create(['lead_id' => $other->id, 'original_name' => 'Other.txt', 'file_path' => 'synthetic/other.txt', 'status' => 'Approved']);
        $this->actingAs($agent)->get("/portal/agent/documents/{$doc->id}/download")->assertOk();
        $this->get("/portal/agent/documents/{$otherDoc->id}/download")->assertNotFound();
    }
}
