<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingPost;
use App\Models\User;
use App\Services\Ghl\GhlConnection;
use App\Services\Ghl\GhlSocialReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CalendarSocialPostTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_uses_manila_month_boundaries_and_returns_only_the_fixed_destination_summary(): void
    {
        $this->withoutVite();
        Http::preventStrayRequests();
        $admin = User::factory()->create(['role' => 'admin']);
        $base = ['location_id' => GhlConnection::LOCATION, 'account_id' => GhlSocialReader::ACCOUNT, 'campaign' => 'Calendar social brief', 'service' => 'General', 'caption' => 'PRIVATE_CAPTION_NOT_FOR_CALENDAR', 'created_by' => $admin->id];
        MarketingPost::create([...$base, 'scheduled_at' => '2026-10-31 17:00:00']);
        MarketingPost::create([...$base, 'scheduled_at' => '2026-11-30 17:00:00']);
        MarketingPost::create([...$base, 'location_id' => 'foreign-location', 'scheduled_at' => '2026-11-15 00:00:00']);
        MarketingPost::create([...$base, 'account_id' => 'foreign-account', 'scheduled_at' => '2026-11-15 00:00:00']);
        MarketingPost::create([...$base, 'scheduled_at' => null]);
        $this->actingAs($admin)->get('/admin/content-calendar?month=2026-11')->assertOk()->assertDontSee('PRIVATE_CAPTION_NOT_FOR_CALENDAR')->assertInertia(fn (Assert $p) => $p
            ->has('socialPosts', 1)->where('socialPosts.0.planned_date', '2026-11-01')->where('socialPosts.0.planned_time', '01:00')->where('socialPosts.0.post_type', 'post')
            ->has('undatedSocialPosts', 1)->where('canPublish', false));
        Http::assertNothingSent();
    }
}
