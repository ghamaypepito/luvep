<?php

namespace Tests\Feature;

use App\Models\AccommodationAgreement;
use App\Models\ActivityLog;
use App\Models\Property;
use App\Models\PropertyRoomVerification;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accommodation\RoomInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccommodationRoomInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $this->actingAs(User::factory()->create(['role' => 'accommodation']));
    }

    private function property(int $capacity = 5, array $extra = []): Property
    {
        return Property::create(array_replace(['name' => 'Synthetic Property '.Property::count(), 'is_active' => true, 'total_rooms' => $capacity,
            'rental_mode' => 'per_room', 'room_type' => 'single', 'bed_type' => 'single', 'bathroom_type' => 'shared', 'rent_single' => 200,
            'status' => 'available'], $extra));
    }

    private function tenant(Property $property, array $extra = []): Tenant
    {
        return Tenant::create(array_replace(['property_id' => $property->id, 'first_name' => 'Synthetic', 'family_name' => 'Household',
            'contract_type' => 'not_yet_defined', 'current_status' => 'active', 'weekly_rent_nzd' => 200], $extra));
    }

    private function payload(Property $property, int $occupied, int $vacant, int $unavailable = 0): array
    {
        return ['version' => RoomInventory::summarize($property->fresh())['version'], 'occupied' => $occupied, 'vacant' => $vacant,
            'unavailable' => $unavailable, 'source_ref' => 'Synthetic approved register', 'observed_on' => now('Pacific/Auckland')->toDateString(), 'confirm' => true];
    }

    private function verify(Property $property, int $occupied, int $vacant, int $unavailable = 0): void
    {
        $this->post('/portal/accommodation/properties/'.$property->id.'/room-occupancy', $this->payload($property, $occupied, $vacant, $unavailable))
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_reference_totals_reconcile_from_records_without_counting_households_as_rooms(): void
    {
        $capacities = [5, 6, 4, 4, 4, 4, 4, 3, 1, 5, 3];
        $households = [5, 4, 3, 2, 1, 3, 4, 1, 1, 7, 4];
        foreach ($capacities as $index => $capacity) {
            $property = $this->property($capacity);
            for ($n = 0; $n < $households[$index]; $n++) {
                $this->tenant($property);
            }
            $vacant = $index === 1 ? 1 : ($index === 3 ? 2 : 0);
            $this->verify($property, $capacity - $vacant, $vacant);
        }
        $archived = $this->property(50, ['is_active' => false]);
        $this->tenant($archived);
        $stats = $this->get('/portal/accommodation/dashboard')->assertOk()->inertiaProps()['inventoryStats'];
        foreach (['properties' => 11, 'rooms' => 43, 'households' => 35, 'occupied' => 40, 'vacant' => 3,
            'available_to_let' => 3, 'unavailable' => 0, 'unverified_rooms' => 0] as $key => $value) {
            $this->assertSame($value, $stats[$key], $key);
        }
        $this->assertCount(11, $stats['rows']);
        $this->assertSame(43, $stats['occupied'] + $stats['vacant'] + $stats['unavailable']);
        $this->assertCount(11, ActivityLog::where('action', 'accommodation.rooms_verified')->get());
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
    }

    public function test_missing_capacity_and_unlabelled_tenancies_are_not_assumed_vacant(): void
    {
        $known = $this->property(5);
        $this->tenant($known);
        $this->property(5, ['total_rooms' => null]);
        $stats = RoomInventory::dashboard();
        $this->assertSame(5, $stats['unverified_rooms']);
        $this->assertSame(2, $stats['unverified_properties']);
        $this->assertSame(1, $stats['missing_capacity']);
        $this->assertSame(1, $stats['unlabelled_households']);
        $this->assertNull($stats['rows'][0]['vacant']);
        $this->assertNull($stats['rows'][0]['occupied']);
    }

    public function test_whole_property_bedrooms_are_not_advertised_as_individual_available_rooms(): void
    {
        $whole = $this->property(5, ['rental_mode' => 'whole_property']);
        $this->tenant($whole);
        $this->verify($whole, 5, 0);
        $this->assertSame(5, RoomInventory::summarize($whole)['occupied']);
        $empty = $this->property(4, ['rental_mode' => 'whole_property']);
        $this->verify($empty, 0, 4);
        $stats = RoomInventory::dashboard();
        $this->assertSame(4, $stats['vacant']);
        $this->assertSame(0, $stats['available_to_let']);
    }

    public function test_shared_rooms_do_not_get_one_occupied_room_per_agreement(): void
    {
        $property = $this->property(2);
        $this->tenant($property, ['unit' => 'Confirmed shared room']);
        $this->tenant($property, ['unit' => 'Confirmed shared room']);
        $this->verify($property, 1, 1);
        $summary = RoomInventory::summarize($property);
        $this->assertSame(2, $summary['households']);
        $this->assertSame(1, $summary['occupied']);
        $this->assertSame(1, $summary['vacant']);
    }

    public function test_notice_vacating_and_expired_contracts_in_residence_count_but_pending_vacated_deleted_and_future_do_not(): void
    {
        $property = $this->property();
        foreach (['active', 'notice_given', 'vacating'] as $status) {
            $this->tenant($property, ['current_status' => $status, 'contract_start' => '2020-01-01', 'contract_end' => '2020-12-31']);
        }
        $this->tenant($property, ['current_status' => 'pending_payment']);
        $this->tenant($property, ['current_status' => 'vacated']);
        $this->tenant($property, ['contract_start' => now('Pacific/Auckland')->addMonth()->toDateString()]);
        $deleted = $this->tenant($property);
        $deleted->delete();
        $this->assertSame(3, RoomInventory::summarize($property)['households']);
    }

    public function test_vacating_or_relabelling_changes_mark_the_prior_room_check_stale_and_preserve_tenant_financials(): void
    {
        $property = $this->property();
        $tenant = $this->tenant($property);
        $before = $tenant->fresh()->getRawOriginal();
        $this->verify($property, 5, 0);
        $this->assertSame($before, $tenant->fresh()->getRawOriginal());
        $old = $this->payload($property, 5, 0);
        $tenant->update(['current_status' => 'vacated']);
        $summary = RoomInventory::summarize($property);
        $this->assertSame('needs_review', $summary['state']);
        $this->assertNull($summary['occupied']);
        $this->assertSame(0, $summary['households']);
        $this->postJson('/portal/accommodation/properties/'.$property->id.'/room-occupancy', $old)->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->assertSame(5, PropertyRoomVerification::sole()->occupied);
        $this->verify($property, 0, 5);
        $property->update(['total_rooms' => 6]);
        $this->assertSame('needs_review', RoomInventory::summarize($property)['state']);
    }

    public function test_current_contract_start_boundary_invalidates_earlier_counts_when_the_tenancy_begins(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-24 12:00', 'Pacific/Auckland'));
        $property = $this->property();
        $this->tenant($property, ['contract_start' => '2026-11-25']);
        $this->verify($property, 0, 5);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-25 12:00', 'Pacific/Auckland'));
        $summary = RoomInventory::summarize($property);
        $this->assertSame(1, $summary['households']);
        $this->assertSame('needs_review', $summary['state']);
    }

    public function test_totals_confirmation_dates_and_scope_are_validated_before_writes(): void
    {
        $property = $this->property();
        $url = '/portal/accommodation/properties/'.$property->id.'/room-occupancy';
        foreach ([['occupied' => -1], ['vacant' => 7], ['confirm' => false], ['observed_on' => '2999-01-01']] as $change) {
            $this->postJson($url, array_replace($this->payload($property, 4, 1), $change))->assertUnprocessable();
        }
        $this->assertSame(0, PropertyRoomVerification::count());
        $other = $this->property();
        $this->postJson('/portal/accommodation/properties/'.$other->id.'/room-occupancy', $this->payload($property, 4, 1))->assertUnprocessable();
    }

    public function test_unrelated_roles_portal_grants_and_view_as_do_not_gain_verification_authority(): void
    {
        $property = $this->property();
        $url = '/portal/accommodation/properties/'.$property->id.'/room-occupancy';
        foreach (['education', 'finance', 'agent', 'lead'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'portal_access' => ['accommodation']]))->postJson($url, $this->payload($property, 4, 1))->assertForbidden();
        }
        $target = User::factory()->create(['role' => 'accommodation']);
        $this->actingAs(User::factory()->create(['role' => 'super_admin']))->postJson('/admin/user-view/'.$target->id)->assertOk();
        $token = session('user_view.token');
        $this->withHeader('X-Luvep-User-View', $token)->postJson($url, $this->payload($property, 4, 1))->assertForbidden();
        $this->assertSame(0, PropertyRoomVerification::count());
    }

    public function test_failed_audit_rolls_back_verification(): void
    {
        $property = $this->property();
        ActivityLog::creating(function ($log) {
            if ($log->action === 'accommodation.rooms_verified') {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        $this->post('/portal/accommodation/properties/'.$property->id.'/room-occupancy', $this->payload($property, 4, 1))->assertStatus(500);
        $this->assertSame(0, PropertyRoomVerification::count());
    }

    public function test_inconsistent_stored_counts_are_not_presented_as_verified(): void
    {
        $property = $this->property();
        $this->verify($property, 4, 1);
        PropertyRoomVerification::sole()->update(['occupied' => 5]);
        $summary = RoomInventory::summarize($property);
        $this->assertSame('needs_review', $summary['state']);
        $this->assertNull($summary['occupied']);
        $this->assertNull($summary['vacant']);
    }

    public function test_existing_pages_remain_available_before_the_optional_verification_table_is_installed(): void
    {
        $property = $this->property();
        \Illuminate\Support\Facades\Schema::drop('property_room_verifications');
        try {
            $this->get('/portal/accommodation/dashboard')->assertOk();
            $this->get('/portal/accommodation/properties')->assertOk();
            $this->get('/portal/accommodation/properties/'.$property->id)->assertOk();
            $this->assertSame('unverified', RoomInventory::summarize($property)['state']);
        } finally {
            (require database_path('migrations/2026_11_24_030000_create_property_room_verifications.php'))->up();
        }
    }

    public function test_room_register_totals_and_linked_signed_agreement_totals_are_independent(): void
    {
        $capacities = [5, 6, 4, 4, 4, 4, 4, 3, 1, 5, 3];
        $households = [5, 4, 3, 2, 1, 3, 4, 1, 1, 7, 4];
        $agreementCount = 0;
        foreach ($capacities as $index => $capacity) {
            $property = $this->property($capacity);
            $rooms = $this->rooms($property);
            $occupied = array_slice($rooms, 0, $capacity - ([1 => 1, 3 => 2][$index] ?? 0));
            for ($i = 0; $i < $households[$index]; $i++) {
                $ids = [];
                for ($offset = $i; $offset < count($occupied); $offset += $households[$index]) {
                    $ids[] = $occupied[$offset]['id'];
                }
                $tenant = $this->tenant($property, ['assigned_room_ids' => $ids ?: [$occupied[0]['id']]]);
                $this->agreement($tenant, 'signed');
                $agreementCount++;
                if ($index < 3 || ($index === 3 && $i === 0)) {
                    $this->agreement($tenant, 'signed');
                    $agreementCount++;
                }
            }
        }

        $this->agreement($tenant, 'draft');
        $this->agreement($tenant, 'signed', ['property_id' => Property::first()->id]);
        $this->agreement($tenant, 'signed', ['tenant_id' => null]);
        $this->agreement($tenant, 'signed', ['data' => ['tenancy' => ['commence_date' => now('Pacific/Auckland')->addDay()->toDateString()]]]);
        $this->agreement($tenant, 'signed', ['data' => ['tenancy' => ['end_date' => now('Pacific/Auckland')->subDay()->toDateString()]]]);
        $future = $this->tenant($property, ['contract_start' => now('Pacific/Auckland')->addDay()->toDateString()]);
        $this->agreement($future, 'signed');
        $past = $this->tenant($property, ['current_status' => 'vacated']);
        $this->agreement($past, 'signed');

        $stats = $this->get('/portal/accommodation/dashboard')->assertOk()->inertiaProps()['inventoryStats'];
        $this->assertSame(48, $agreementCount);
        foreach (['properties' => 11, 'rooms' => 43, 'occupied' => 40, 'vacant' => 3, 'available_to_let' => 3,
            'households' => 35, 'active_agreements' => 48, 'unverified_properties' => 0] as $metric => $count) {
            $this->assertSame($count, $stats[$metric], $metric);
        }
    }

    public function test_move_reassignment_and_vacating_update_distinct_room_counts(): void
    {
        $property = $this->property(3);
        $destination = $this->property(2);
        [$first, $second, $third] = array_column($this->rooms($property), 'id');
        [$destinationRoom] = array_column($this->rooms($destination), 'id');
        $shared = $this->tenant($property, ['assigned_room_ids' => [$first]]);
        $payload = ['property_id' => $property->id, 'first_name' => 'Room', 'family_name' => 'Tester',
            'contract_type' => 'periodic', 'assigned_room_ids' => [$first, $second]];

        $this->post('/portal/accommodation/tenants', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $tenant = Tenant::where('first_name', 'Room')->sole();
        $this->assertSame(2, RoomInventory::summarize($property)['occupied']);
        $this->putJson('/portal/accommodation/tenants/'.$tenant->id, array_replace($payload, [
            'assigned_room_ids' => [$destinationRoom], 'room_assignment_version' => $tenant->move_in_version,
        ]))->assertUnprocessable()->assertJsonValidationErrors('assigned_room_ids');

        $reassigned = array_replace($payload, ['assigned_room_ids' => [$third], 'room_assignment_version' => $tenant->move_in_version]);
        $this->put('/portal/accommodation/tenants/'.$tenant->id, $reassigned)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([$third], $tenant->fresh()->assigned_room_ids);
        $this->assertSame('Room 2', RoomInventory::summarize($property)['vacancy_notes']);
        $this->putJson('/portal/accommodation/tenants/'.$tenant->id, $payload + ['room_assignment_version' => $tenant->move_in_version])
            ->assertUnprocessable()->assertJsonValidationErrors('assigned_room_ids');

        $move = ['new_property_id' => $destination->id, 'move_date' => today()->toDateString(), 'assigned_room_ids' => [$destinationRoom]];
        $this->post('/portal/accommodation/tenants/'.$tenant->id.'/move', $move)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('vacated', $tenant->fresh()->current_status);
        $this->assertSame([$third], $tenant->fresh()->assigned_room_ids);
        $this->assertSame(1, RoomInventory::summarize($destination)['occupied']);
        $this->assertSame(1, RoomInventory::summarize($property)['occupied']);
        $this->postJson('/portal/accommodation/tenants/'.$tenant->id.'/move', $move)->assertUnprocessable();
        $this->assertSame(1, $destination->tenants()->count());
        $this->post('/portal/accommodation/tenants/'.$shared->id.'/vacate', ['vacate_date' => today()->toDateString()])->assertRedirect();
        $this->assertSame(3, RoomInventory::summarize($property)['vacant']);

        $this->tenant($property);
        $this->assertNull(RoomInventory::summarize($property)['vacant']);
    }

    public function test_register_setup_preserves_room_ids_and_rejects_destructive_or_stale_edits(): void
    {
        $property = $this->property(2);
        $url = '/portal/accommodation/properties/'.$property->id;
        $version = $this->get($url.'/edit')->assertOk()->inertiaProps()['room_register_version'];
        $payload = $property->only(['name', 'total_rooms', 'rental_mode', 'room_type', 'bed_type', 'bathroom_type', 'rent_single', 'status']);
        $payload['room_register_version'] = $version;
        $payload['room_register'] = [['name' => 'Single, shared bathroom', 'hold' => false], ['name' => 'Ensuite', 'hold' => true]];
        $this->put($url, $payload)->assertRedirect()->assertSessionHasNoErrors();
        $property->refresh();
        $this->assertSame(1, RoomInventory::summarize($property)['vacant']);
        $this->assertSame(1, RoomInventory::summarize($property)['unavailable']);
        $this->assertArrayNotHasKey('room_register', $property->toArray());
        $this->putJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('room_register');

        [$single, $ensuite] = $property->room_register;
        $this->tenant($property, ['assigned_room_ids' => [$single['id']]]);
        $payload['room_register_version'] = $this->get($url.'/edit')->inertiaProps()['room_register_version'];
        $this->putJson($url, array_replace($payload, ['room_register' => [$ensuite]]))->assertUnprocessable()->assertJsonValidationErrors('room_register');
        $payload['room_register'] = [array_replace($single, ['name' => 'Room A']), $ensuite];
        $this->put($url, $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($single['id'], $property->fresh()->room_register[0]['id']);
        $this->assertSame(1, RoomInventory::summarize($property->fresh())['occupied']);
    }

    private function rooms(Property $property): array
    {
        $rooms = [];
        for ($number = 1; $number <= $property->total_rooms; $number++) {
            $rooms[] = ['id' => (string) Str::uuid(), 'name' => 'Room '.$number, 'hold' => false];
        }
        $property->forceFill(['room_register' => $rooms])->save();

        return $rooms;
    }

    private function agreement(Tenant $tenant, string $status, array $extra = []): void
    {
        AccommodationAgreement::create(array_replace([
            'tenant_id' => $tenant->id, 'property_id' => $tenant->property_id,
            'client_name' => 'Synthetic agreement', 'property_address' => 'Synthetic address',
            'agreement_type' => 'standard_flatmate', 'data' => [], 'status' => $status,
        ], $extra));
    }
}
