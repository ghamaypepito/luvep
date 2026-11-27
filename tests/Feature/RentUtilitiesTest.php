<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\RentPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\RentRoll;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as AssertInertia;
use Tests\TestCase;

class RentUtilitiesTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create(['role' => 'accommodation', 'module_permissions' => ['accommodation_finance']]);
    }

    private function property(array $o = []): Property
    {
        return Property::create(array_merge([
            'name' => 'Test House', 'rent_single' => 200, 'status' => 'available',
            'is_active' => true, 'total_rooms' => 4, 'code' => '1', 'address' => '1 Test St',
        ], $o));
    }

    private function tenant(Property $p, array $o = []): Tenant
    {
        return Tenant::create(array_merge([
            'property_id' => $p->id, 'first_name' => 'Jane', 'family_name' => 'Doe',
            'contract_type' => 'fixed_term', 'contract_start' => '2026-01-01', 'current_status' => 'active',
            'weekly_rent_nzd' => 350, 'weekly_utilities_nzd' => 40,
        ], $o));
    }

    public function test_index_groups_active_tenants_with_flat_totals_and_status(): void
    {
        $p = $this->property(['address' => '21 Vazey Way', 'code' => '1']);
        $t = $this->tenant($p, ['first_name' => 'Michaella', 'family_name' => 'Lee']);

        $start = RentRoll::snapToMonday(Carbon::parse('2026-05-25'));
        foreach (range(0, 2) as $i) {
            RentPayment::create([
                'tenant_id' => $t->id,
                'week_start' => $start->copy()->addWeeks($i)->toDateString(),
                'amount_nzd' => 390,
            ]);
        }

        $this->actingAs($this->staff())
            ->get('/portal/accommodation/rent-utilities?start='.$start->toDateString().'&weeks=18')
            ->assertInertia(fn (AssertInertia $page) => $page
                ->component('portal/accommodation/RentUtilities', false)
                ->has('weeks', 18)
                ->where('window.weeks', 18)
                ->has('groups', 1)
                ->where('groups.0.property_address', '21 Vazey Way')
                ->has('groups.0.tenants', 1)
                ->where('groups.0.tenants.0.display_name', 'Michaella Lee')
                ->where('groups.0.tenants.0.total_due', fn ($v) => $v == 7020.0)
                ->where('groups.0.tenants.0.total_paid', fn ($v) => $v == 1170.0)
                ->where('groups.0.tenants.0.balance', fn ($v) => $v == -5850.0)
                ->where('groups.0.tenants.0.status', 'Underpaid — short $5,850.00')
            );
    }

    public function test_index_falls_back_to_property_name_when_address_missing(): void
    {
        // A listing with a name but no street address / code (e.g. "Mt Roskill
        // Student House") must show the name, not a dash.
        $p = Property::create([
            'name' => 'Mt Roskill Student House', 'rent_single' => 200, 'status' => 'available',
            'is_active' => true, 'total_rooms' => 4,
        ]);
        $this->tenant($p);

        $this->actingAs($this->staff())->get('/portal/accommodation/rent-utilities')
            ->assertInertia(fn (AssertInertia $page) => $page
                ->where('groups.0.property_address', 'Mt Roskill Student House'));
    }

    public function test_rent_roll_uses_the_properties_default_order(): void
    {
        $this->tenant($this->property(['code' => '10', 'address' => 'A Street']));
        $this->tenant($this->property(['code' => '2', 'address' => 'Z Street']));
        $this->actingAs($this->staff())->get('/portal/accommodation/rent-utilities')->assertInertia(fn (AssertInertia $page) => $page
            ->where('groups.0.property_address', 'Z Street')
            ->where('groups.1.property_address', 'A Street'));
    }

    public function test_index_status_prompts_when_rent_and_utilities_unset(): void
    {
        $p = $this->property();
        $this->tenant($p, ['weekly_rent_nzd' => 0, 'weekly_utilities_nzd' => 0]);

        $this->actingAs($this->staff())->get('/portal/accommodation/rent-utilities')
            ->assertInertia(fn (AssertInertia $page) => $page
                ->where('groups.0.tenants.0.status', 'Set weekly rent & utilities'));
    }

    public function test_save_rent_utilities_updates_tenant(): void
    {
        $p = $this->property();
        $t = $this->tenant($p, ['weekly_rent_nzd' => 0, 'weekly_utilities_nzd' => 0]);

        $this->actingAs($this->staff())
            ->from('/portal/accommodation/rent-utilities')
            ->patch("/portal/accommodation/rent-utilities/tenants/{$t->id}/rent", [
                'weekly_rent_nzd' => 200, 'weekly_utilities_nzd' => 50,
            ])->assertRedirect();

        $fresh = $t->fresh();
        $this->assertEquals('200.00', $fresh->weekly_rent_nzd);
        $this->assertEquals('50.00', $fresh->weekly_utilities_nzd);
        $this->assertEquals('250.00', $fresh->weekly_total_due);
    }

    public function test_save_rent_utilities_rejects_negative(): void
    {
        $p = $this->property();
        $t = $this->tenant($p);

        $this->actingAs($this->staff())
            ->from('/portal/accommodation/rent-utilities')
            ->patch("/portal/accommodation/rent-utilities/tenants/{$t->id}/rent", ['weekly_rent_nzd' => -5])
            ->assertSessionHasErrors(['weekly_rent_nzd']);
    }

    public function test_index_excludes_vacated_tenants(): void
    {
        $p = $this->property();
        $this->tenant($p, ['first_name' => 'Active', 'current_status' => 'active']);
        $this->tenant($p, ['first_name' => 'Gone', 'current_status' => 'vacated']);

        $this->actingAs($this->staff())->get('/portal/accommodation/rent-utilities')
            ->assertInertia(fn (AssertInertia $page) => $page
                ->has('groups.0.tenants', 1)
                ->where('groups.0.tenants.0.display_name', 'Active Doe'));
    }

    public function test_totals_only_count_payments_inside_window(): void
    {
        $p = $this->property();
        $t = $this->tenant($p, ['weekly_rent_nzd' => 150, 'weekly_utilities_nzd' => 0]);
        $start = RentRoll::snapToMonday(Carbon::parse('2026-05-25'));
        RentPayment::create(['tenant_id' => $t->id, 'week_start' => $start->toDateString(), 'amount_nzd' => 150]);
        RentPayment::create(['tenant_id' => $t->id, 'week_start' => $start->copy()->subWeeks(5)->toDateString(), 'amount_nzd' => 999]);

        $this->actingAs($this->staff())
            ->get('/portal/accommodation/rent-utilities?start='.$start->toDateString().'&weeks=4')
            ->assertInertia(fn (AssertInertia $page) => $page
                ->where('groups.0.tenants.0.total_paid', fn ($v) => $v == 150.0)
                ->where('groups.0.tenants.0.total_due', fn ($v) => $v == 600.0));
    }

    public function test_weeks_param_is_clamped(): void
    {
        $this->property();
        $this->actingAs($this->staff())->get('/portal/accommodation/rent-utilities?weeks=999')
            ->assertInertia(fn (AssertInertia $page) => $page->where('window.weeks', 30)->has('weeks', 30));
        $this->actingAs($this->staff())->get('/portal/accommodation/rent-utilities?weeks=0')
            ->assertInertia(fn (AssertInertia $page) => $page->where('window.weeks', 1));
    }

    public function test_save_payment_upserts_and_updates(): void
    {
        $p = $this->property();
        $t = $this->tenant($p);
        $monday = RentRoll::snapToMonday(Carbon::parse('2026-06-17'))->toDateString();

        $this->actingAs($this->staff())
            ->from('/portal/accommodation/rent-utilities')
            ->patch("/portal/accommodation/rent-utilities/tenants/{$t->id}/payment", ['week_start' => '2026-06-17', 'amount' => 250])
            ->assertRedirect();
        // Assert through the model so the date check is DB-agnostic: MySQL's DATE
        // column drops the time, but SQLite (test DB) keeps "00:00:00", which would
        // break a literal assertDatabaseHas on the bare date string.
        $payment = RentPayment::where('tenant_id', $t->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame($monday, $payment->week_start->toDateString());
        $this->assertSame(250.0, (float) $payment->amount_nzd);

        $this->actingAs($this->staff())
            ->patch("/portal/accommodation/rent-utilities/tenants/{$t->id}/payment", ['week_start' => '2026-06-17', 'amount' => 300]);
        $this->assertDatabaseCount('accommodation_rent_payments', 1);
        $this->assertSame(300.0, (float) RentPayment::where('tenant_id', $t->id)->first()->amount_nzd);
    }

    public function test_save_payment_zero_or_blank_deletes(): void
    {
        $p = $this->property();
        $t = $this->tenant($p);
        $monday = RentRoll::snapToMonday(Carbon::parse('2026-06-17'))->toDateString();
        RentPayment::create(['tenant_id' => $t->id, 'week_start' => $monday, 'amount_nzd' => 250]);

        $this->actingAs($this->staff())
            ->patch("/portal/accommodation/rent-utilities/tenants/{$t->id}/payment", ['week_start' => '2026-06-17', 'amount' => 0]);
        $this->assertDatabaseMissing('accommodation_rent_payments', ['tenant_id' => $t->id, 'week_start' => $monday]);
    }

    public function test_save_payment_rejects_negative(): void
    {
        $p = $this->property();
        $t = $this->tenant($p);

        $this->actingAs($this->staff())
            ->from('/portal/accommodation/rent-utilities')
            ->patch("/portal/accommodation/rent-utilities/tenants/{$t->id}/payment", ['week_start' => '2026-06-17', 'amount' => -5])
            ->assertSessionHasErrors(['amount']);
    }
}
