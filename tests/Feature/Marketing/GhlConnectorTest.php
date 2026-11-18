<?php

namespace Tests\Feature\Marketing;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GhlConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'pit-test-ep-server-secret-123456789';

    private const LOCATION = 'EPwLVKAvmJdF8IvwBLwO';

    private function admin(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
    }

    private function save(): void
    {
        $this->admin();
        $this->postJson('/admin/marketing-connectors', ['ghl_api_token' => self::TOKEN])->assertOk();
    }

    public function test_only_super_admin_can_manage_the_connection(): void
    {
        foreach (['admin', 'education', 'sales', 'lead'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->getJson('/admin/marketing-connectors')->assertForbidden();
            $this->postJson('/admin/marketing-connectors', ['ghl_api_token' => self::TOKEN])->assertForbidden();
            $this->postJson('/admin/marketing-connectors/test')->assertForbidden();
        }
    }

    public function test_token_is_encrypted_redacted_and_excluded_from_generic_settings(): void
    {
        Http::preventStrayRequests();
        $this->save();
        $stored = Setting::where('key', 'ghl_connector.token')->value('value');
        $this->assertSame(self::TOKEN, Crypt::decryptString($stored));
        $res = $this->getJson('/admin/marketing-connectors')->assertOk()->assertJsonPath('connection.configured', true)->assertJsonPath('connection.location_id', self::LOCATION);
        $this->assertStringNotContainsString(self::TOKEN, $res->getContent());
        $this->get('/admin/settings', ['X-Inertia' => 'true'])->assertDontSee($stored);
        $this->postJson('/admin/settings', ['values' => ['ghl_connector.token' => 'tampered']]);
        $this->assertSame($stored, Setting::where('key', 'ghl_connector.token')->value('value'));
        Http::assertNothingSent();
    }

    public function test_blank_token_keeps_current_credential_and_removal_clears_verification(): void
    {
        $this->save();
        $this->postJson('/admin/marketing-connectors', ['ghl_api_token' => ''])->assertOk();
        $this->assertSame(self::TOKEN, Crypt::decryptString(Setting::get('ghl_connector.token')));
        $this->postJson('/admin/marketing-connectors', ['remove_saved_token' => true])->assertOk()->assertJsonPath('connection.configured', false)->assertJsonPath('connection.verified', false);
    }

    public function test_connection_test_reads_ep_accounts_from_fixed_destination(): void
    {
        $this->save();
        Http::fake(['services.leadconnectorhq.com/*' => Http::response(['success' => true, 'results' => ['accounts' => [['id' => 'ep-fb', 'name' => 'EP Pathways', 'platform' => 'facebook', 'locationId' => self::LOCATION, 'active' => true]]]])]);
        $this->postJson('/admin/marketing-connectors/test')->assertOk()->assertJsonPath('connection.verified', true)->assertJsonPath('accounts.0.name', 'EP Pathways');
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->url() === 'https://services.leadconnectorhq.com/social-media-posting/'.self::LOCATION.'/accounts' && $r->hasHeader('Version', 'v3') && $r->hasHeader('Authorization', 'Bearer '.self::TOKEN));
    }

    public function test_wrong_location_accounts_are_rejected_without_disclosing_them(): void
    {
        $this->save();
        Http::fake(['*' => Http::response(['success' => true, 'results' => ['accounts' => [['id' => 'wrong', 'name' => 'Other tenant private name', 'locationId' => 'wrong-location']]]])]);
        $this->postJson('/admin/marketing-connectors/test')->assertStatus(502)->assertJsonPath('connection.verified', false)->assertDontSee('Other tenant private name');
    }

    public function test_unconfigured_test_does_not_contact_provider(): void
    {
        $this->admin();
        Http::preventStrayRequests();
        $this->postJson('/admin/marketing-connectors/test')->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_scope_failures_are_safe_and_clear_previous_verification(): void
    {
        $this->save();
        Setting::set('ghl_connector.verified_at', now()->toIso8601String());
        Http::fake(['*' => Http::response(['message' => 'sensitive provider body '.self::TOKEN], 401)]);
        $this->postJson('/admin/marketing-connectors/test')->assertStatus(502)->assertJsonPath('connection.verified', false)->assertDontSee('sensitive provider body')->assertDontSee(self::TOKEN);
    }

    public function test_unexpected_success_shape_is_not_reported_as_connected(): void
    {
        $this->save();
        Http::fake(['*' => Http::response(['message' => 'not the accounts schema'])]);
        $this->postJson('/admin/marketing-connectors/test')->assertStatus(502)->assertJsonPath('connection.verified', false);
    }

    public function test_location_override_cannot_be_saved(): void
    {
        $this->admin();
        $this->postJson('/admin/marketing-connectors', ['ghl_api_token' => self::TOKEN, 'location_id' => 'wrong-location'])->assertUnprocessable();
    }

    public function test_invalid_token_is_not_flashed_back_to_the_session(): void
    {
        $this->admin();
        $this->from('/admin/marketing-connectors')->post('/admin/marketing-connectors', ['ghl_api_token' => 'pit invalid secret value 123456'])->assertRedirect('/admin/marketing-connectors')->assertSessionHasErrors('ghl_api_token');
        $this->assertArrayNotHasKey('ghl_api_token', session()->getOldInput());
    }

    public function test_token_replacement_invalidates_a_successful_check(): void
    {
        $this->save();
        Http::fake(['*' => Http::response(['success' => true, 'results' => ['accounts' => []]])]);
        $this->postJson('/admin/marketing-connectors/test')->assertOk()->assertJsonPath('connection.verified', true);
        $this->postJson('/admin/marketing-connectors', ['ghl_api_token' => 'pit-replacement-secret-123456789'])->assertOk()->assertJsonPath('connection.verified', false);
    }

    public function test_network_failure_does_not_expose_details(): void
    {
        $this->save();
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('provider transport secret'));
        $this->postJson('/admin/marketing-connectors/test')->assertStatus(502)->assertDontSee('provider transport secret')->assertJsonPath('connection.verified', false);
    }

    public function test_generic_editor_cannot_write_mixed_case_managed_keys(): void
    {
        $this->admin();
        foreach (['GHL_CONNECTOR.TOKEN', 'CLAUDE_CONNECTOR.API_KEY'] as $key) {
            $row = Setting::create(['key' => $key, 'value' => 'encrypted-fixture', 'type' => 'string']);
            $this->post('/admin/settings', ['values' => [$key => 'tampered']]);
            $this->assertSame('encrypted-fixture', $row->fresh()->value);
        }
    }
}
