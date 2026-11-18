<?php

namespace Tests\Feature\Immigration;

use App\Models\CaseView;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Build 12 phase 4 — attention (§5, narrowed). Passive case_views telemetry,
 * throttled; the board signal is drawn from LICENSED-user opens only; and
 * nothing about views ever reaches a client-facing payload. No durations are
 * rendered anywhere.
 */
class AttentionTest extends TestCase
{
    use RefreshDatabase;

    private function caseLead(?string $trackingCode = null): Lead
    {
        return Lead::create([
            'first_name' => 'Aroha', 'last_name' => 'Ngata', 'email' => 'aroha@example.test',
            'is_immigration_case' => true, 'inz_visa_type' => 'Student Visa',
            'tracking_code' => $trackingCode,
        ]);
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => 'immigration']);
    }

    private function adviser(bool $current = true): User
    {
        return User::factory()->create([
            'role' => User::ROLE_IMMIGRATION_ADVISER,
            'iaa_licence_number' => 'IAA-7',
            'iaa_licence_expiry' => $current ? now()->addYear() : now()->subDay(),
        ]);
    }

    // ── Throttle: repeated opens inside the window write one row ─────────────

    public function test_repeated_opens_inside_the_window_write_one_row(): void
    {
        $case = $this->caseLead();
        $staff = $this->staff();

        $this->actingAs($staff)->get("/portal/immigration/cases/{$case->id}/profile")->assertOk();
        $this->actingAs($staff)->get("/portal/immigration/cases/{$case->id}/profile")->assertOk();
        $this->actingAs($staff)->get("/portal/immigration/cases/{$case->id}/profile")->assertOk();

        $this->assertSame(1, CaseView::where('lead_id', $case->id)->where('user_id', $staff->id)->count());

        // Past the window, a fresh open records a new row.
        $this->travel(CaseView::THROTTLE_MINUTES + 1)->minutes();
        $this->actingAs($staff)->get("/portal/immigration/cases/{$case->id}/profile")->assertOk();
        $this->assertSame(2, CaseView::where('lead_id', $case->id)->where('user_id', $staff->id)->count());
    }

    public function test_record_open_is_idempotent_within_the_window(): void
    {
        $case = $this->caseLead();
        $staff = $this->staff();

        $this->assertNotNull(CaseView::recordOpen($case->id, $staff->id));
        $this->assertNull(CaseView::recordOpen($case->id, $staff->id)); // throttled
        $this->assertSame(1, CaseView::where('lead_id', $case->id)->count());
    }

    // ── The chip reflects the latest LICENSED view, ignoring unlicensed ──────

    public function test_chip_reflects_latest_licensed_view_and_ignores_unlicensed(): void
    {
        $case = $this->caseLead();
        $adviser = $this->adviser();
        $unlicensed = $this->staff();

        // Adviser looked an hour ago; an unlicensed staffer looked just now.
        CaseView::create(['lead_id' => $case->id, 'user_id' => $adviser->id, 'opened_at' => now()->subHour()]);
        CaseView::create(['lead_id' => $case->id, 'user_id' => $unlicensed->id, 'opened_at' => now()]);

        $map = CaseView::latestLicensedOpens([$case->id]);
        // The signal is the adviser's open — the unlicensed (later) one is ignored.
        $this->assertTrue($map->has($case->id));
        $this->assertEqualsWithDelta(now()->subHour()->timestamp, $map[$case->id]->timestamp, 5);
    }

    public function test_only_unlicensed_views_leave_the_case_not_opened(): void
    {
        $case = $this->caseLead();
        $unlicensed = $this->staff();
        $lapsed = $this->adviser(current: false);

        CaseView::create(['lead_id' => $case->id, 'user_id' => $unlicensed->id, 'opened_at' => now()]);
        CaseView::create(['lead_id' => $case->id, 'user_id' => $lapsed->id, 'opened_at' => now()]);

        // Neither an unlicensed nor a lapsed-licence open counts as "opened".
        $this->assertFalse(CaseView::latestLicensedOpens([$case->id])->has($case->id));
    }

    public function test_board_payload_carries_the_licensed_open(): void
    {
        $case = $this->caseLead();
        $adviser = $this->adviser();
        CaseView::create(['lead_id' => $case->id, 'user_id' => $adviser->id, 'opened_at' => now()->subDay()]);

        $this->actingAs($this->staff())
            ->get('/portal/immigration/cases')
            ->assertInertia(fn ($page) => $page->where(
                'cases',
                fn ($cases) => filled(collect($cases)->firstWhere('id', $case->id)['attention_opened_at'])
            ));
    }

    // ── Nothing about views reaches a client-facing payload ──────────────────

    public function test_views_never_reach_a_client_facing_payload(): void
    {
        $case = $this->caseLead(trackingCode: 'TRACK123');
        $adviser = $this->adviser();
        CaseView::create(['lead_id' => $case->id, 'user_id' => $adviser->id, 'opened_at' => now()]);

        // Cases use their own authenticated client portal, which must not leak
        // the staff's attention or viewing telemetry.
        $client = User::factory()->create(['role' => 'lead', 'lead_id' => $case->id]);
        $res = $this->actingAs($client)->get('/portal/lead/tracker')->assertOk();
        $res->assertDontSee('attention_opened_at', false);
        $res->assertDontSee('case_views', false);
        $res->assertDontSee('opened_at', false);
    }
}
