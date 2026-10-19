<?php

namespace Tests\Feature\Hr;

use App\Models\HrEmployee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class HrSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hr.enabled' => true, 'hr.shared_session' => true, 'session.driver' => 'file', 'session.files' => storage_path('framework/testing/hr-sessions')]);
        if (! is_dir(config('session.files'))) {
            mkdir(config('session.files'), 0700, true);
        }
        app('session')->forgetDrivers();
        $this->withoutVite();
        Route::middleware('web')->get('/session-probe', fn () => response()->json(['marker' => session('marker'), 'cookie_name' => session()->getName()]));
    }

    public function test_session_cookie_is_secure_and_shared_only_on_allowed_hosts(): void
    {
        $response = $this->get('https://luvep.com/session-probe');
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'luvep_shared_session');
        $this->assertNotNull($cookie);
        $this->assertSame('luvep.com', ltrim($cookie->getDomain(), '.'));
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->get('https://staging.epathways.co.nz/session-probe')->assertCookieMissing('luvep_shared_session');
        $this->get('https://epathways.co.nz/session-probe')->assertCookieMissing('luvep_shared_session');
        $this->assertNull(config('session.domain'));
    }

    public function test_legacy_session_migrates_without_losing_identity_or_data(): void
    {
        $legacyName = config('session.cookie');
        $store = app('session')->driver();
        $store->start();
        $store->put('marker', 'retained');
        $store->save();
        $id = $store->getId();
        $response = $this->withCookie($legacyName, $id)->get('https://luvep.com/session-probe');
        $response->assertJsonPath('marker', 'retained')->assertJsonPath('cookie_name', 'luvep_shared_session');
        $expired = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === $legacyName);
        $this->assertNotNull($expired);
        $this->assertLessThan(time(), $expired->getExpiresTime());
        $this->assertNull($expired->getDomain());
    }

    public function test_new_cookie_wins_over_a_stale_legacy_cookie(): void
    {
        $store = app('session')->driver();
        $store->start();
        $store->put('marker', 'old');
        $store->save();
        $old = $store->getId();
        $store->migrate(false);
        $store->put('marker', 'current');
        $store->save();
        $current = $store->getId();
        $this->withCookies([config('session.cookie') => $old, 'luvep_shared_session' => $current])
            ->get('https://luvep.com/session-probe')->assertJsonPath('marker', 'current');
    }

    public function test_login_rejects_an_external_intended_redirect(): void
    {
        $user = User::factory()->create(['role' => 'sales']);
        $this->withSession(['url.intended' => 'https://evil.example/'])->post('https://luvep.com/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect($user->homeRoute());
    }

    public function test_login_preserves_an_allowed_hr_destination(): void
    {
        $user = User::factory()->create(['role' => 'sales']);
        HrEmployee::create(['user_id' => $user->id, 'status' => 'active']);
        $this->withSession(['url.intended' => 'https://hr.luvep.com/profile'])->post('https://luvep.com/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('https://hr.luvep.com/profile');
    }

    public function test_login_preserves_only_the_explicitly_configured_hr_origin_with_port(): void
    {
        config(['hr.url' => 'https://hr.luvep.com:8443']);
        $user = User::factory()->create(['role' => 'sales']);
        $this->withSession(['url.intended' => 'https://hr.luvep.com:8443/profile'])->post('https://luvep.com/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('https://hr.luvep.com:8443/profile');
    }

    public function test_password_change_invalidates_an_existing_hr_session(): void
    {
        $user = User::factory()->create(['role' => 'sales']);
        HrEmployee::create(['user_id' => $user->id, 'status' => 'active']);
        $this->actingAs($user)->withSession(['password_hash_web' => 'obsolete'])->get('https://hr.luvep.com/profile')->assertRedirect();
        $this->assertGuest();
    }

    public function test_inertia_login_to_hr_uses_a_full_browser_navigation(): void
    {
        $user = User::factory()->create(['role' => 'sales']);
        $this->withSession(['url.intended' => 'https://hr.luvep.com/profile'])->withHeader('X-Inertia', 'true')
            ->post('https://luvep.com/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(409)->assertHeader('X-Inertia-Location', 'https://hr.luvep.com/profile');
    }

    public function test_inertia_hr_logout_returns_to_main_login_and_invalidates_session(): void
    {
        $user = User::factory()->create(['role' => 'sales']);
        $this->actingAs($user)->withHeader('X-Inertia', 'true')->post('https://hr.luvep.com/logout')
            ->assertStatus(409)->assertHeader('X-Inertia-Location', 'https://luvep.com/login');
        $this->assertGuest();
    }
}
