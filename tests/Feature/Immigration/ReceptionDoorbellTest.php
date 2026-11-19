<?php

namespace Tests\Feature\Immigration;

use App\Models\Setting;
use App\Models\User;
use App\Notifications\ReceptionDoorbellRang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReceptionDoorbellTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();
        Cache::store('array')->flush();
    }

    private function enable(): string
    {
        $token = str_repeat('a', 48);
        Setting::set('reception_doorbell_token', $token);

        return '/reception/'.$token;
    }

    public function test_only_active_managers_and_admins_can_create_the_reusable_qr_link(): void
    {
        Notification::fake();
        foreach (['immigration', 'immigration_adviser', 'agent', 'lead'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->post('/portal/immigration/reception')->assertForbidden();
        }
        foreach (['immigration_manager', 'super_admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->post('/portal/immigration/reception')->assertRedirect()->assertSessionHasNoErrors();
            $token = Setting::get('reception_doorbell_token');
            $this->assertSame(48, strlen($token));
            $this->post('/portal/immigration/reception')->assertRedirect();
            $this->assertSame($token, Setting::get('reception_doorbell_token'));
            $this->get('/portal/immigration/reception')->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('portal/immigration/Reception')
                    ->where('receptionUrl', url('/reception/'.$token)));
        }
        Notification::assertNothingSent();
    }

    public function test_opening_valid_qr_never_rings_and_invalid_links_are_rejected(): void
    {
        Notification::fake();
        $url = $this->enable();
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component('reception/Doorbell'));
        $this->get('/reception/'.str_repeat('b', 48))->assertNotFound();
        $this->postJson('/reception/'.str_repeat('b', 48))->assertNotFound();
        Notification::assertNothingSent();
    }

    public function test_explicit_ring_notifies_only_active_managers_in_app_and_cooldown_is_shared(): void
    {
        Notification::fake();
        $manager = User::factory()->create(['role' => 'immigration_manager']);
        $inactive = User::factory()->create(['role' => 'immigration_manager', 'is_active' => false]);
        $adviser = User::factory()->create(['role' => 'immigration_adviser']);
        $admin = User::factory()->create(['role' => 'super_admin']);
        $url = $this->enable();
        $this->postJson($url, ['name' => 'Ignored visitor data'])->assertOk()->assertExactJson(['notified' => true]);
        Notification::assertSentTo($manager, ReceptionDoorbellRang::class, fn ($n, $channels) => $channels === ['database'] && $n->toArray($manager)['title'] === 'Someone is at reception'
            && ! array_key_exists('name', $n->toArray($manager)));
        Notification::assertNotSentTo([$inactive, $adviser, $admin], ReceptionDoorbellRang::class);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.2'])->postJson($url)->assertStatus(429);
        Notification::assertCount(1);
        $this->travel(61)->seconds();
        $this->postJson($url)->assertOk();
        Notification::assertCount(2);
    }

    public function test_no_managers_returns_unavailable_without_consuming_the_cooldown(): void
    {
        Notification::fake();
        $url = $this->enable();
        $this->postJson($url)->assertStatus(503);
        User::factory()->create(['role' => 'immigration_manager']);
        $this->postJson($url)->assertOk();
        Notification::assertCount(1);
    }

    public function test_database_notification_payload_has_no_visitor_data_and_uses_existing_inbox(): void
    {
        $manager = User::factory()->create(['role' => 'immigration_manager']);
        $this->postJson($this->enable(), ['email' => 'unused@example.test'])->assertOk();
        $notification = $manager->notifications()->sole();
        $this->assertSame(ReceptionDoorbellRang::class, $notification->type);
        $this->assertSame(['title', 'body', 'link'], array_keys($notification->data));
        $this->assertSame('/portal/immigration/reception', $notification->data['link']);
    }

    public function test_failed_delivery_rolls_back_notifications_and_allows_a_retry(): void
    {
        $manager = User::factory()->create(['role' => 'immigration_manager']);
        $url = $this->enable();
        Notification::partialMock()->shouldReceive('send')->once()->andReturnUsing(function ($users, $notification) {
            $users->first()->notifyNow($notification, ['database']);
            throw new \RuntimeException('Synthetic delivery failure');
        });
        $this->postJson($url)->assertStatus(500);
        $this->assertSame(0, $manager->notifications()->count());
        $this->assertFalse(Cache::has('reception-doorbell:'.hash('sha256', str_repeat('a', 48))));
    }
}
