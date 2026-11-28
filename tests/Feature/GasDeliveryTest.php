<?php

namespace Tests\Feature;

use App\Models\AccommodationGasOrder;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as AssertInertia;
use Tests\TestCase;

class GasDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/portal/accommodation/gas-delivery';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function property(array $overrides = []): Property
    {
        return Property::create(array_replace([
            'name' => 'Gas House', 'rent_single' => 200, 'status' => 'available', 'is_active' => true,
            'code' => '#02', 'address' => '31 Vazey Way Hobsonville Auckland New Zealand',
            'uses_bottled_gas' => true, 'last_gas_purchase' => '2026-04-09',
        ], $overrides));
    }

    public function test_only_target_property_appears_and_its_legacy_date_is_preserved(): void
    {
        $target = $this->property();
        $this->property(['code' => '#08', 'address' => '89C Archibald Road']);
        $this->actingAs(User::factory()->create(['role' => 'accommodation']))->get(self::URL.'?month=2026-04')->assertOk()
            ->assertInertia(fn (AssertInertia $page) => $page->component('portal/accommodation/GasDelivery')
                ->where('property.address', $target->address)
                ->where('property.legacy_purchase_date', '2026-04-09')
                ->has('orders', 0)->where('total_paid_cents', 0));
    }

    public function test_orders_are_saved_once_with_exact_totals_and_month_boundaries(): void
    {
        $property = $this->property();
        $this->actingAs(User::factory()->create(['role' => 'accommodation']));
        $payload = ['ordered_on' => '2026-08-31', 'bottles' => 2, 'amount_paid' => '151.05',
            'property_id' => 999, 'submission_key' => (string) Str::uuid()];
        $this->post(self::URL, $payload)->assertSessionHasNoErrors()->assertRedirect(self::URL.'?month=2026-08');
        $this->post(self::URL, $payload)->assertSessionHasNoErrors()->assertRedirect();
        $this->postJson(self::URL, [...$payload, 'bottles' => 3])->assertUnprocessable()->assertJsonValidationErrors('submission_key');
        $this->post(self::URL, [...$payload, 'amount_paid' => '0.10', 'ordered_on' => '2026-08-01', 'submission_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post(self::URL, [...$payload, 'ordered_on' => '2026-09-01', 'submission_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('accommodation_gas_orders', 3);
        $this->assertSame([$property->id], AccommodationGasOrder::distinct()->pluck('property_id')->all());
        $this->get(self::URL.'?month=2026-08')->assertOk()->assertInertia(fn (AssertInertia $page) => $page
            ->has('orders', 2)->where('orders.0.ordered_on', '2026-08-31')->where('total_paid_cents', 15115));
        $this->assertSame('2026-04-09', $property->fresh()->last_gas_purchase->toDateString());
    }

    public function test_invalid_and_unauthorized_orders_are_rejected(): void
    {
        $this->property();
        $payload = ['ordered_on' => '2099-01-01', 'bottles' => 0, 'amount_paid' => '1.005', 'submission_key' => (string) Str::uuid()];
        $this->actingAs(User::factory()->create(['role' => 'sales']))->postJson(self::URL, $payload)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'accommodation']))->postJson(self::URL, $payload)
            ->assertUnprocessable()->assertJsonValidationErrors(['ordered_on', 'bottles', 'amount_paid']);
        $this->assertDatabaseCount('accommodation_gas_orders', 0);
    }

    public function test_inactive_or_ambiguous_property_cannot_receive_orders(): void
    {
        $target = $this->property(['is_active' => false]);
        $this->actingAs(User::factory()->create(['role' => 'accommodation']));
        $this->get(self::URL)->assertOk()->assertInertia(fn (AssertInertia $page) => $page->where('property', null));
        $this->postJson(self::URL)->assertNotFound();
        $target->update(['is_active' => true]);
        $this->property(['code' => '02']);
        $this->postJson(self::URL)->assertNotFound();
    }
}
