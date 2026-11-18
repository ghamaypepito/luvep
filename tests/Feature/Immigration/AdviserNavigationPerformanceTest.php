<?php

namespace Tests\Feature\Immigration;

use App\Http\Controllers\Portal\ImmigrationAdviserController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\CaseAttestation;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdviserNavigationPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private function requestWithCases(): Request
    {
        $adviser = User::factory()->create(['role' => 'immigration_adviser']);
        $this->actingAs($adviser);
        $cases = collect();
        for ($i = 0; $i < 24; $i++) {
            $cases->push(Lead::create(['first_name' => 'Case', 'last_name' => (string) $i,
                'is_immigration_case' => true, 'current_owner_id' => $adviser->id]));
        }
        $attest = fn ($lead, $type) => CaseAttestation::create(['lead_id' => $lead->id, 'adviser_id' => $adviser->id,
            'type' => $type, 'verdict' => $type === CaseAttestation::TYPE_VERDICT ? 'good_to_go' : null]);
        $attest($cases[1], CaseAttestation::TYPE_VERDICT);
        $attest($cases[2], CaseAttestation::TYPE_LODGEMENT_SIGNOFF);
        $attest($cases[3], CaseAttestation::TYPE_VERDICT);
        $attest($cases[3], CaseAttestation::TYPE_VERDICT); // History must not count twice.
        $attest($cases[3], CaseAttestation::TYPE_LODGEMENT_SIGNOFF);
        $other = User::factory()->create(['role' => 'immigration_adviser']);
        $foreign = Lead::create(['first_name' => 'Other', 'last_name' => 'Owner', 'is_immigration_case' => true, 'current_owner_id' => $other->id]);
        $attest($foreign, CaseAttestation::TYPE_VERDICT);
        $attest($foreign, CaseAttestation::TYPE_LODGEMENT_SIGNOFF);

        $request = Request::create('https://luvep.com/portal/immigration-adviser/dashboard');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => $adviser);
        $request->headers->set('X-Inertia', 'true');

        return $request;
    }

    public function test_dashboard_batches_attestation_counts_and_keeps_owner_scope(): void
    {
        $request = $this->requestWithCases();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $response = app(ImmigrationAdviserController::class)->dashboard($request);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $props = $response->toResponse($request)->getData(true)['props'];

        $this->assertSame(24, $props['stats']['my_cases']);
        $this->assertSame(22, $props['stats']['awaiting_verdict']);
        $this->assertSame(22, $props['stats']['awaiting_lodgement']);
        $this->assertCount(8, $props['recentCases']);
        $this->assertLessThanOrEqual(6, $queries, "Adviser dashboard ran {$queries} queries for 24 cases.");
    }

    public function test_sidebar_batches_the_union_of_missing_verdicts_and_signoffs(): void
    {
        $request = $this->requestWithCases();
        $shared = app(HandleInertiaRequests::class)->share($request);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $badges = $shared['sidebarBadges']()['immigration-adviser'];
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(24, $badges['my_cases']);
        $this->assertSame(23, $badges['pending_signoff']);
        $this->assertLessThanOrEqual(6, $queries, "Adviser sidebar ran {$queries} queries for 24 cases.");
    }

    public function test_empty_adviser_book_does_not_include_foreign_attestations(): void
    {
        $request = $this->requestWithCases();
        $emptyAdviser = User::factory()->create(['role' => 'immigration_adviser']);
        $this->actingAs($emptyAdviser);
        $request->setUserResolver(fn () => $emptyAdviser);
        $props = app(ImmigrationAdviserController::class)->dashboard($request)->toResponse($request)->getData(true)['props'];
        $this->assertSame(['my_cases' => 0, 'awaiting_verdict' => 0, 'awaiting_lodgement' => 0, 'lodged' => 0], $props['stats']);
        $this->assertCount(0, $props['recentCases']);
        $shared = app(HandleInertiaRequests::class)->share($request);
        $this->assertSame(0, $shared['sidebarBadges']()['immigration-adviser']['pending_signoff']);
    }
}
