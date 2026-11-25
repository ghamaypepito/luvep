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
            ->where('socialPosts.0.version', 1)->where('socialPosts.0.can_move', true)
            ->has('undatedSocialPosts', 1)->where('canPublish', false)->where('canApproveSocialPosts', true)->where('canPublishSocialPosts', false));
        $admin->update(['module_permissions' => ['social_publishing']]);
        $this->get('/admin/content-calendar?month=2026-11')->assertOk()->assertInertia(fn (Assert $p) => $p->where('canPublish', false)->where('canApproveSocialPosts', true)->where('canPublishSocialPosts', true));
        Http::assertNothingSent();
    }

    public function test_marketing_can_move_a_post_in_review_without_changing_the_copy_or_manila_time(): void
    {
        Http::preventStrayRequests();
        $marketing = User::factory()->create(['email' => 'marketing@luvep.com', 'role' => 'admin']);
        $post = $this->calendarPost($marketing, ['status' => 'in_review', 'scheduled_at' => '2026-11-26 17:13:24']);
        $url = '/social/marketing/posts/'.$post->id.'/planned-date';
        $move = ['version' => 1, 'planned_date' => '2026-12-01'];

        $this->actingAs(User::factory()->create(['role' => 'education']))->patchJson($url, $move)->assertForbidden();
        $this->actingAs($marketing)->patchJson($url, $move)->assertOk()
            ->assertJsonPath('post.calendar_date', '2026-12-01')->assertJsonPath('post.calendar_time', '01:13')
            ->assertJsonPath('post.scheduled_at', '2026-11-30T17:13:24+00:00')->assertJsonPath('post.status', 'in_review')
            ->assertJsonPath('post.version', 2)->assertJsonPath('post.caption', 'Original caption');
        $this->patchJson($url, $move)->assertConflict();
        $this->patchJson($url, ['version' => 2, 'planned_date' => '2026-02-30'])->assertUnprocessable();
        $this->patchJson($url, ['version' => 2, 'planned_date' => '2026-12-01', 'caption' => 'Other copy'])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_calendar_moves_require_fresh_approval_and_keep_external_posts_locked(): void
    {
        Http::preventStrayRequests();
        $staff = User::factory()->create(['role' => 'education', 'module_permissions' => ['social_marketing']]);
        $this->actingAs($staff);
        $move = fn (MarketingPost $post, string $date = '2026-12-02') => $this->patchJson('/social/marketing/posts/'.$post->id.'/planned-date', ['version' => $post->version, 'planned_date' => $date]);
        $approved = $this->calendarPost($staff, ['status' => 'approved', 'scheduled_at' => '2026-12-01 01:00:00', 'approved_by' => $staff->id, 'approved_version' => 1]);
        $approved->update(['approval_fingerprint' => $approved->fingerprint()]);

        $move($approved, '2026-12-01')->assertOk()->assertJsonPath('post.status', 'approved')->assertJsonPath('post.version', 1);
        $this->assertTrue($approved->fresh()->hasCurrentApproval());
        $move($approved)->assertOk()->assertJsonPath('post.status', 'in_review')->assertJsonPath('post.approved_by', null);
        $this->assertFalse($approved->fresh()->hasCurrentApproval());

        $undated = $this->calendarPost($staff, ['status' => 'changes_requested', 'review_note' => 'Check the copy.']);
        $move($undated)->assertOk()->assertJsonPath('post.calendar_time', '09:00')->assertJsonPath('post.status', 'changes_requested')
            ->assertJsonPath('post.review_note', 'Check the copy.');
        foreach (['scheduling', 'scheduled', 'published', 'needs_reconciliation'] as $status) {
            $move($this->calendarPost($staff, ['status' => $status]))->assertConflict();
        }
        $move($this->calendarPost($staff, ['publish_now' => true]))->assertConflict();
        $move($this->calendarPost($staff, ['ghl_post_id' => 'remote-id']))->assertConflict();
        $move($this->calendarPost($staff, ['account_id' => 'other-page']))->assertNotFound();
        Http::assertNothingSent();
    }

    private function calendarPost(User $user, array $attributes = []): MarketingPost
    {
        return MarketingPost::create(array_replace([
            'location_id' => GhlConnection::LOCATION, 'account_id' => GhlSocialReader::ACCOUNT,
            'campaign' => 'Calendar post', 'service' => 'General', 'caption' => 'Original caption',
            'created_by' => $user->id, 'version' => 1,
        ], $attributes));
    }
}
