<?php

namespace Tests\Feature\Marketing;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GhlIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_superadmin_can_choose_a_verified_ep_location_user(): void
    {
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson('/social/marketing/identity', ['user_id' => 'abc12345'])->assertForbidden();
        Http::assertNothingSent();
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        Setting::set('ghl_connector.token', Crypt::encryptString('private-key'));
        Http::fake(['https://services.leadconnectorhq.com/users/*' => Http::response(['users' => [['id' => 'abc12345', 'name' => 'EP Creator', 'email' => 'private-email', 'roles' => ['locationIds' => ['EPwLVKAvmJdF8IvwBLwO']]]]])]);
        $this->postJson('/social/marketing/identity', ['user_id' => 'wrong-user'])->assertUnprocessable();
        $this->postJson('/social/marketing/identity', ['user_id' => 'abc12345'])->assertOk()->assertDontSee('private-email')->assertDontSee('private-key');
        $this->assertSame('abc12345', Setting::get('ghl_connector.user_id'));
    }
}
