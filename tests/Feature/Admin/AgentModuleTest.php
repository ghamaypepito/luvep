<?php

namespace Tests\Feature\Admin;

use App\Models\AgentAgreement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AgentModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_agents_index(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']))
            ->get('/admin/agents')
            ->assertOk();
    }

    public function test_admin_has_default_access_and_staff_grants_do_not_allow_affiliate_management(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/agents')->assertOk();
        $this->actingAs(User::factory()->create(['role' => 'sales', 'module_permissions' => ['agents'], 'portal_access' => ['admin']]))
            ->get('/admin/agents')->assertForbidden();
    }

    public function test_granted_admin_can_view_agents(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'module_permissions' => ['agents']]);

        $this->actingAs($admin)->get('/admin/agents')->assertOk();
    }

    public function test_generate_agreement_creates_a_record_and_file(): void
    {
        Storage::fake('local');
        $super = User::factory()->create(['role' => 'super_admin']);
        $agent = User::factory()->create(['role' => 'agent', 'name' => 'Lillian Ejorango']);

        $this->actingAs($super)
            ->post("/admin/agents/{$agent->id}/agreement/generate", [
                'agent_passport' => 'AK341265',
                'agent_citizenship' => 'Canada',
                'nz_6plus_amount' => 'PhP 35,000',
            ])
            ->assertRedirect();

        $agreement = AgentAgreement::where('agent_id', $agent->id)->first();
        $this->assertNotNull($agreement);
        $this->assertSame('AK341265', $agreement->fields['agent_passport']);
        $this->assertSame('PhP 35,000', $agreement->fields['nz_6plus_amount']);
        Storage::disk('local')->assertExists($agreement->file_path);
    }

    public function test_regenerating_retains_the_previous_agreement(): void
    {
        Storage::fake('local');
        $super = User::factory()->create(['role' => 'super_admin']);
        $agent = User::factory()->create(['role' => 'agent']);

        $this->actingAs($super)->post("/admin/agents/{$agent->id}/agreement/generate", ['agent_passport' => 'A1']);
        $this->actingAs($super)->post("/admin/agents/{$agent->id}/agreement/generate", ['agent_passport' => 'B2']);

        $this->assertSame(2, AgentAgreement::where('agent_id', $agent->id)->count());
        $this->assertSame('A1', AgentAgreement::where('agent_id', $agent->id)->orderBy('version')->first()->fields['agent_passport']);
        $this->assertSame('B2', AgentAgreement::where('agent_id', $agent->id)->orderByDesc('version')->first()->fields['agent_passport']);
        foreach (AgentAgreement::where('agent_id', $agent->id)->get() as $version) {
            Storage::disk('local')->assertExists($version->file_path);
            Storage::disk('local')->assertExists($version->issued_file_path);
        }
    }

    public function test_show_404s_for_a_non_agent_user(): void
    {
        $super = User::factory()->create(['role' => 'super_admin']);
        $notAgent = User::factory()->create(['role' => 'sales']);

        $this->actingAs($super)->get("/admin/agents/{$notAgent->id}")->assertNotFound();
    }

    public function test_agent_sees_and_downloads_their_own_agreement_in_the_portal(): void
    {
        Storage::fake('local');
        $super = User::factory()->create(['role' => 'super_admin']);
        $agent = User::factory()->create(['role' => 'agent']);

        // Staff generate it from the Agents module.
        $this->actingAs($super)->post("/admin/agents/{$agent->id}/agreement/generate", ['agent_passport' => 'AK1']);

        // The agent views + downloads their own from the Agent portal.
        $this->actingAs($agent)->get('/portal/agent/agreement')->assertOk();
        $this->actingAs($agent)->get('/portal/agent/agreement/download')->assertOk();
    }

    public function test_agent_with_no_agreement_sees_page_but_download_404s(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);

        $this->actingAs($agent)->get('/portal/agent/agreement')->assertOk();
        $this->actingAs($agent)->get('/portal/agent/agreement/download')->assertNotFound();
    }

    public function test_agent_can_sign_their_own_agreement(): void
    {
        Storage::fake('local');
        $super = User::factory()->create(['role' => 'super_admin']);
        $agent = User::factory()->create(['role' => 'agent']);

        $this->actingAs($super)->post("/admin/agents/{$agent->id}/agreement/generate", ['agent_full_name' => 'Lillian']);

        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
        $this->actingAs($agent)
            ->post('/portal/agent/agreement/sign', [
                'signer_name' => 'Lillian Ejorango',
                'signature_data' => $png,
                'terms_accepted' => 1,
            ])
            ->assertRedirect();

        $agreement = AgentAgreement::where('agent_id', $agent->id)->first();
        $this->assertTrue($agreement->isSignedByAgent());
        $this->assertSame('Lillian Ejorango', $agreement->agent_signer_name);
        $this->assertNotNull($agreement->agent_signed_at);
        $this->assertNotNull($agreement->agent_signed_ip);
    }

    public function test_signing_requires_name_signature_and_terms(): void
    {
        Storage::fake('local');
        $super = User::factory()->create(['role' => 'super_admin']);
        $agent = User::factory()->create(['role' => 'agent']);
        $this->actingAs($super)->post("/admin/agents/{$agent->id}/agreement/generate", ['agent_full_name' => 'X']);

        $this->actingAs($agent)
            ->post('/portal/agent/agreement/sign', ['signer_name' => '', 'signature_data' => '', 'terms_accepted' => 0])
            ->assertSessionHasErrors(['signer_name', 'signature_data', 'terms_accepted']);
    }

    public function test_already_signed_agreement_cannot_be_re_signed(): void
    {
        Storage::fake('local');
        $super = User::factory()->create(['role' => 'super_admin']);
        $agent = User::factory()->create(['role' => 'agent']);
        $this->actingAs($super)->post("/admin/agents/{$agent->id}/agreement/generate", ['agent_full_name' => 'X']);

        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
        $payload = ['signer_name' => 'A', 'signature_data' => $png, 'terms_accepted' => 1];

        $this->actingAs($agent)->post('/portal/agent/agreement/sign', $payload)->assertRedirect();
        $this->actingAs($agent)->post('/portal/agent/agreement/sign', $payload)->assertStatus(422);
    }

    public function test_agent_provides_bank_details_when_signing(): void
    {
        Storage::fake('local');
        $super = User::factory()->create(['role' => 'super_admin']);
        $agent = User::factory()->create(['role' => 'agent']);
        $this->actingAs($super)->post("/admin/agents/{$agent->id}/agreement/generate", ['agent_full_name' => 'Lillian']);

        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
        $this->actingAs($agent)
            ->post('/portal/agent/agreement/sign', [
                'signer_name' => 'Lillian Ejorango',
                'signature_data' => $png,
                'terms_accepted' => 1,
                'bank_name' => 'RCBC',
                'account_number' => '9045440503',
                'affiliate_email' => 'lil@example.com',
            ])
            ->assertRedirect();

        $agreement = AgentAgreement::where('agent_id', $agent->id)->first();
        $this->assertTrue($agreement->isSignedByAgent());
        $this->assertSame('RCBC', $agreement->fields['bank_name']);
        $this->assertSame('9045440503', $agreement->fields['account_number']);
        $this->assertSame('lil@example.com', $agreement->fields['affiliate_email']);
    }

    public function test_agent_can_save_their_bank_details_separately(): void
    {
        Storage::fake('local');
        $super = User::factory()->create(['role' => 'super_admin']);
        $agent = User::factory()->create(['role' => 'agent']);
        $this->actingAs($super)->post("/admin/agents/{$agent->id}/agreement/generate", ['agent_full_name' => 'Lil']);

        $this->actingAs($agent)
            ->post('/portal/agent/agreement/details', [
                'bank_name' => 'RCBC',
                'account_holder' => 'Lillian Ejorango',
                'account_number' => '9045440503',
                'swift_bic' => 'RCBCPHMM',
            ])
            ->assertRedirect();

        $agreement = AgentAgreement::where('agent_id', $agent->id)->first();
        $this->assertSame('RCBC', $agreement->fields['bank_name']);
        $this->assertSame('RCBCPHMM', $agreement->fields['swift_bic']);
    }

    public function test_commission_counts_started_course_students_and_picks_tier(): void
    {
        Storage::fake('local');
        $super = User::factory()->create(['role' => 'super_admin']);
        $agent = User::factory()->create(['role' => 'agent']);

        // 3 started-course + 2 other leads → 3 qualifying (1–5 tier).
        for ($i = 0; $i < 3; $i++) {
            \App\Models\Lead::create(['first_name' => "S$i", 'last_name' => 'T', 'agent_id' => $agent->id, 'education_stage' => 'Started Course']);
        }
        \App\Models\Lead::create(['first_name' => 'X', 'last_name' => 'T', 'agent_id' => $agent->id, 'education_stage' => 'Conditional Offer']);

        $this->actingAs($super)->post("/admin/agents/{$agent->id}/agreement/generate", [
            'nz_1_5_amount' => '20,000',
            'nz_6plus_amount' => '30,000',
            'currency' => 'Philippine Peso (PhP)',
        ]);

        $this->actingAs($super)->get("/admin/agents/{$agent->id}")
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('commission.qualifying', 3)
                ->where('commission.tier', '1_5')
                ->where('commission.per_student_amount', 20000)
                ->where('commission.total', 60000)
            );
    }

    public function test_commission_moves_to_6plus_tier_at_six_students(): void
    {
        Storage::fake('local');
        $super = User::factory()->create(['role' => 'super_admin']);
        $agent = User::factory()->create(['role' => 'agent']);

        for ($i = 0; $i < 6; $i++) {
            \App\Models\Lead::create(['first_name' => "S$i", 'last_name' => 'T', 'agent_id' => $agent->id, 'education_stage' => 'Started Course']);
        }
        $this->actingAs($super)->post("/admin/agents/{$agent->id}/agreement/generate", [
            'nz_1_5_amount' => '20,000', 'nz_6plus_amount' => '30,000',
        ]);

        $this->actingAs($super)->get("/admin/agents/{$agent->id}")
            ->assertInertia(fn ($p) => $p
                ->where('commission.qualifying', 6)
                ->where('commission.tier', '6plus')
                ->where('commission.total', 180000)
            );
    }

    public function test_staff_can_sign_the_company_side_and_view_inline(): void
    {
        Storage::fake('local');
        $super = User::factory()->create(['role' => 'super_admin']);
        $agent = User::factory()->create(['role' => 'agent']);
        $this->actingAs($super)->post("/admin/agents/{$agent->id}/agreement/generate", ['agent_full_name' => 'X']);

        // Inline view works.
        $this->actingAs($super)->get("/admin/agents/{$agent->id}/agreement/view")->assertOk();

        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
        $this->actingAs($super)
            ->post("/admin/agents/{$agent->id}/agreement/sign", ['signer_name' => 'Dinah Suarin', 'signature_data' => $png])
            ->assertRedirect();

        $agreement = AgentAgreement::where('agent_id', $agent->id)->first();
        $this->assertTrue($agreement->isSignedByCompany());
        $this->assertSame('Dinah Suarin', $agreement->company_signer_name);
    }
}
