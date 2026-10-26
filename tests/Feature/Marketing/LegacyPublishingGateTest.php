<?php

namespace Tests\Feature\Marketing;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LegacyPublishingGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_paths_cannot_bypass_saved_approval_after_cutover(): void
    {
        Setting::set('ghl_connector.workflow_enabled', true, 'bool');
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        Http::preventStrayRequests();
        foreach (['quick-post', 'approve-variant', 'reschedule'] as $path) {
            $this->postJson('/webhook/social/'.$path, [])->assertStatus(409)->assertJsonPath('error', 'saved_approval_required');
        }
        Http::assertNothingSent();
    }
}
