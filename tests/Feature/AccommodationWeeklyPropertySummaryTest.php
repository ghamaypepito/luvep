<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccommodationWeeklyPropertySummaryTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = '/portal/accommodation/finance/weekly-property-summary';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_summary_saves_rent_and_utilities_separately_by_nz_week_and_protects_stale_updates(): void
    {
        $property = Property::create(['name' => 'Test House', 'code' => '2', 'is_active' => true]);
        $this->actingAs(User::factory()->create(['role' => 'accommodation', 'email' => 'janille@janilles.me']));
        $payload = ['week' => '2026-11-27', 'category' => 'rent', 'revenue_nzd' => '1250.15', 'cost_nzd' => '900.10', 'version' => 0];
        $this->from(self::PATH)->patch(self::PATH.'/'.$property->id, $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->patch(self::PATH.'/'.$property->id, [...$payload, 'category' => 'utilities', 'revenue_nzd' => '200', 'cost_nzd' => '150'])->assertSessionHasNoErrors();
        $this->patch(self::PATH.'/'.$property->id, [...$payload, 'week' => '2026-11-30', 'revenue_nzd' => '100'])->assertSessionHasNoErrors();
        $this->patchJson(self::PATH.'/'.$property->id, [...$payload, 'revenue_nzd' => '0'])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->patchJson(self::PATH.'/'.$property->id, [...$payload, 'version' => 1, 'cost_nzd' => '-1'])->assertUnprocessable()->assertJsonValidationErrors('cost_nzd');
        $this->assertDatabaseCount('accommodation_weekly_property_finances', 3);

        $property->update(['is_active' => false]);
        $props = $this->get(self::PATH.'?week=2026-11-28&category=rent')->assertOk()->inertiaProps();
        $this->assertSame('2026-11-23', $props['week']['start']);
        $this->assertSame('2026-11-29', $props['week']['end']);
        $this->assertSame('Test House', $props['rows'][0]['name']);
        $this->assertEquals(1250.15, $props['rows'][0]['revenue_nzd']);
        $this->assertEquals(900.10, $props['rows'][0]['cost_nzd']);
        $this->assertEquals(200, $this->get(self::PATH.'?week=2026-11-23&category=utilities')->inertiaProps()['rows'][0]['revenue_nzd']);
        $this->assertEquals(100, $this->get(self::PATH.'?week=2026-11-30')->inertiaProps()['rows'][0]['revenue_nzd']);
        $this->delete('/portal/accommodation/properties/'.$property->id)->assertSessionHas('error', Property::WEEKLY_FINANCE_DELETE_ERROR);
        $this->assertModelExists($property);
    }

    public function test_finance_routes_are_restricted_to_the_confirmed_accounts(): void
    {
        $property = Property::create(['name' => 'Restricted House', 'is_active' => true]);
        $tenant = Tenant::create(['property_id' => $property->id, 'first_name' => 'Test', 'family_name' => 'Tenant']);
        foreach ([
            ['role' => 'accommodation'],
            ['role' => 'admin'],
            ['role' => 'super_admin'],
            ['role' => 'finance', 'module_permissions' => ['accommodation_finance']],
        ] as $attributes) {
            $this->actingAs(User::factory()->create($attributes));
            $this->get(self::PATH)->assertForbidden();
            $this->patch(self::PATH.'/'.$property->id, [])->assertForbidden();
            $this->get('/portal/accommodation/rent-utilities')->assertForbidden();
            $this->patch('/portal/accommodation/rent-utilities/tenants/'.$tenant->id.'/payment', [])->assertForbidden();
            $this->patch('/portal/accommodation/rent-utilities/tenants/'.$tenant->id.'/rent', [])->assertForbidden();
        }
        foreach (config('modules.accommodation_finance_accounts') as $email) {
            $this->actingAs(User::factory()->create(['role' => 'finance', 'email' => $email]));
            $this->get(self::PATH)->assertOk();
            $this->get('/portal/accommodation/rent-utilities')->assertOk();
        }
    }
}
