<?php

namespace Tests\Feature;

use App\Models\AccommodationAgreement;
use App\Models\AccommodationMoveOut;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AccommodationMoveOutTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/portal/accommodation/move-out';

    private function tenant(array $attributes = []): Tenant
    {
        $property = Property::create(['name' => 'Vazey Way', 'address' => '29 Vazey Way', 'rent_single' => 220, 'status' => 'available']);

        return Tenant::create($attributes + [
            'property_id' => $property->id, 'first_name' => 'Jamie', 'family_name' => 'Example',
            'email' => 'jamie@example.test', 'phone' => '0212345678', 'contract_end' => '2026-12-31',
            'contract_type' => 'fixed_term', 'current_status' => 'active', 'bond_paid_nzd' => '350.50',
        ]);
    }

    private function start(Tenant $tenant): AccommodationMoveOut
    {
        $this->post(self::URL, ['tenant_id' => $tenant->id])->assertRedirect()->assertSessionHasNoErrors();

        return AccommodationMoveOut::where('tenant_id', $tenant->id)->firstOrFail();
    }

    private function action(AccommodationMoveOut $record, string $action, array $data = [])
    {
        return $this->from(self::URL.'/'.$record->id)->post(self::URL.'/'.$record->id.'/advance', ['action' => $action] + $data);
    }

    public function test_access_search_and_reassignment_keep_a_single_owner_and_history(): void
    {
        $owners = config('accommodation_move_out.owners');
        $alaine = User::factory()->create(['role' => 'accommodation', 'email' => $owners['Alaine']]);
        $janille = User::factory()->create(['role' => 'accommodation', 'email' => $owners['Janille']]);
        $other = User::factory()->create(['role' => 'education']);
        $staff = User::factory()->create(['role' => 'accommodation']);
        $tenant = $this->tenant();

        $this->actingAs($other)->get(self::URL)->assertForbidden();
        $this->actingAs($staff)->patch(self::URL.'/automation', ['enabled' => true])->assertForbidden();
        $this->actingAs($janille)->get(self::URL.'/create')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->component('portal/accommodation/MoveOutStart')->where('tenants.0.id', $tenant->id)
        );
        $record = $this->start($tenant);
        $this->start($tenant);
        $this->assertSame(1, AccommodationMoveOut::count());
        $this->actingAs($other)->post(self::URL.'/'.$record->id.'/advance', ['action' => 'check_in'])->assertForbidden();

        $this->actingAs($janille)->patch(self::URL.'/'.$record->id.'/assignee', ['assignee_id' => $other->id])->assertInvalid(['assignee_id']);
        foreach ([$alaine, $janille] as $owner) {
            $this->patch(self::URL.'/'.$record->id.'/assignee', ['assignee_id' => $owner->id])->assertSessionHasNoErrors();
        }

        foreach (['Jamie Example', 'jamie@example.test', '0212345678'] as $search) {
            $this->get(self::URL.'?'.http_build_query(['search' => $search, 'assignee' => 'mine', 'view' => 'pipeline']))
                ->assertInertia(fn (AssertableInertia $page) => $page->component('portal/accommodation/MoveOutIndex')->has('records.data', 1)
                    ->where('records.data.0.assigneeId', $janille->id));
        }
        $this->get(self::URL.'?assignee=Alaine')->assertInertia(fn (AssertableInertia $page) => $page->has('records.data', 0));
        $this->get(self::URL.'/'.$record->id)->assertInertia(fn (AssertableInertia $page) => $page->has('events', 3)
            ->where('events.2.details.from_owner', 'Alaine')->where('events.2.details.to_owner', 'Janille')
            ->missing('record.passport_number'));
    }

    public function test_automation_honors_both_switches_and_skips_changed_or_manually_sent_records(): void
    {
        $this->travelTo(Carbon::parse('2026-11-30 09:00', 'Pacific/Auckland'));
        Event::fake([MessageSent::class]);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $due = $this->start($this->tenant());
        $manual = $this->start($this->tenant(['email' => 'manual@example.test']));
        $paused = $this->start($this->tenant(['email' => 'paused@example.test']));
        $changed = $this->start($this->tenant(['email' => 'changed@example.test']));
        $missed = $this->start($this->tenant(['contract_end' => '2026-12-29']));
        $this->assertSame('2026-11-30', $due->check_in_on->toDateString());

        $this->patch(self::URL.'/'.$paused->id.'/automation', ['enabled' => false])->assertSessionHasNoErrors();
        $this->artisan('accommodation:send-move-out-emails')->assertSuccessful();
        Event::assertNotDispatched(MessageSent::class);
        $this->action($manual, 'check_in')->assertSessionHasNoErrors();
        $this->patch(self::URL.'/automation', ['enabled' => true])->assertSessionHasNoErrors();
        $this->action($changed, 'confirm_move', ['move_out_on' => '2026-12-07', 'notes' => 'Tenant called.'])->assertSessionHasNoErrors();
        $this->action($changed, 'renew', ['notes' => 'Tenant renewed instead.'])->assertSessionHasNoErrors();

        $this->artisan('accommodation:send-move-out-emails')->assertSuccessful();
        $this->artisan('accommodation:send-move-out-emails')->assertSuccessful();

        Event::assertDispatchedTimes(MessageSent::class, 4);
        $this->assertSame(1, $due->events()->where('action', 'check_in')->count());
        $this->assertSame(1, $manual->events()->where('action', 'check_in')->count());
        foreach ([$paused, $missed, $changed] as $record) {
            $this->assertFalse($record->events()->whereIn('action', ['check_in', 'remind'])->exists());
        }
        $this->assertNull($changed->fresh()->reminder_on);
    }

    public function test_move_out_requires_resolution_confirmation_and_proof_and_emails_once(): void
    {
        Event::fake([MessageSent::class]);
        Storage::fake('tenant_private');
        $this->travelTo(Carbon::parse('2026-11-30 09:00', 'Pacific/Auckland'));
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $tenant = $this->tenant();
        $record = $this->start($tenant);

        $this->action($record, 'check_in')->assertSessionHasNoErrors();
        $this->action($record, 'check_in')->assertInvalid(['action']);
        $this->action($record, 'confirm_move', ['move_out_on' => '2026-12-15'])->assertSessionHasNoErrors();
        $this->action($record, 'remind')->assertInvalid(['action']);
        $this->patch(self::URL.'/automation', ['enabled' => true])->assertSessionHasNoErrors();
        $this->action($record, 'confirm_move', ['move_out_on' => '2026-12-16', 'notes' => 'Agreed new date.'])->assertSessionHasNoErrors();

        $this->travelTo(Carbon::parse('2026-12-08 09:00', 'Pacific/Auckland'));
        $this->artisan('accommodation:send-move-out-emails')->assertSuccessful();
        $this->assertSame('decision', $record->fresh()->stage);
        $this->travelTo(Carbon::parse('2026-12-09 09:00', 'Pacific/Auckland'));
        $this->artisan('accommodation:send-move-out-emails')->assertSuccessful();
        $this->artisan('accommodation:send-move-out-emails')->assertSuccessful();
        $this->assertSame('reminded', $record->fresh()->stage);
        $this->assertSame(1, $record->events()->where('action', 'remind')->count());

        $this->action($record, 'inspect', ['notes' => 'Check carpet.'])->assertInvalid(['action']);
        $this->travelTo(Carbon::parse('2026-12-16 12:00', 'Pacific/Auckland'));
        $this->post('/portal/accommodation/tenants/'.$tenant->id.'/vacate', ['vacate_date' => '2026-12-16'])->assertSessionHasNoErrors();
        $this->action($record, 'inspect', ['notes' => 'Check carpet.', 'files' => [UploadedFile::fake()->image('room.jpg')]])->assertSessionHasNoErrors();
        $this->action($record, 'feedback', ['notes' => 'Carpet needs cleaning.', 'work_needed' => true, 'proposed_charge' => '50.25'])->assertSessionHasNoErrors();
        $statement = ['bond' => '350.50', 'deductions' => [['item' => 'Carpet cleaning', 'amount' => '50.25']]];
        $this->action($record, 'statement', $statement)->assertInvalid(['action']);
        $this->action($record, 'resolve', ['response' => 'Agreed by email.', 'resolution' => 'Cleaning complete.'])->assertSessionHasNoErrors();
        $this->action($record, 'statement', ['bond' => '40.00'] + $statement)->assertInvalid(['action']);
        $this->action($record, 'statement', $statement)->assertSessionHasNoErrors();
        $this->assertSame(30025, $record->events()->where('action', 'statement')->firstOrFail()->details['refund_cents']);
        $this->action($record, 'refund', ['reference' => 'Test transfer'])->assertInvalid(['action']);
        $this->action($record, 'accept', ['response' => 'Confirmed the statement by email.'])->assertSessionHasNoErrors();
        $this->action($record, 'refund', ['reference' => 'Test transfer'])->assertInvalid(['action']);
        $this->action($record, 'refund', ['reference' => 'Test transfer', 'files' => [UploadedFile::fake()->create('proof.pdf', 1, 'application/pdf')]])
            ->assertSessionHasNoErrors();
        $refund = $record->events()->where('action', 'refund')->firstOrFail();
        $this->get(self::URL.'/'.$record->id.'/files/'.$refund->id.'/0')->assertDownload('proof.pdf');
        $differentRecord = $this->start($this->tenant());
        $this->get(self::URL.'/'.$differentRecord->id.'/files/'.$refund->id.'/0')->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'education']))->get(self::URL.'/'.$record->id.'/files/'.$refund->id.'/0')->assertForbidden();
        $this->actingAs($admin)->action($record, 'close_move')->assertSessionHasNoErrors();
        $this->action($record, 'close_move')->assertInvalid(['action']);
        $this->assertNotNull($record->fresh()->closed_at);
        $this->assertSame('350.50', $tenant->fresh()->bond_paid_nzd);
        Event::assertDispatchedTimes(MessageSent::class, 7);
        Event::assertDispatched(MessageSent::class, fn (MessageSent $event) => $event->message->getSubject() === 'Your Exalt move-out is complete'
            && $event->message->getAttachments()[0]->getFilename() === 'proof.pdf');
    }

    public function test_existing_property_delete_keeps_move_out_history_readable(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'accommodation']));
        $tenant = $this->tenant();
        $record = $this->start($tenant);
        $tenant->update(['current_status' => 'vacated']);

        $this->delete('/portal/accommodation/properties/'.$tenant->property_id)->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('accommodation_properties', ['id' => $tenant->property_id]);
        $this->get(self::URL.'/'.$record->id)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('record.tenant', 'Jamie Example')->where('record.property', '29 Vazey Way')->has('events', 1)->has('actions', 0));
    }

    public function test_renewal_closes_only_with_saved_extension_and_signed_evidence(): void
    {
        Event::fake([MessageSent::class]);
        Storage::fake('tenant_private');
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'accommodation']));
        $tenant = $this->tenant();
        Storage::disk('local')->put('signed/original.pdf', 'Test agreement fixture');
        $agreement = AccommodationAgreement::create([
            'agreement_type' => 'standard_flatmate', 'tenant_id' => $tenant->id, 'property_id' => $tenant->property_id,
            'client_name' => $tenant->display_name, 'property_address' => '29 Vazey Way',
            'status' => 'signed', 'signed_pdf_path' => 'signed/original.pdf',
            'data' => ['tenancy' => ['end_date' => '2026-12-31']],
        ]);
        $record = $this->start($tenant);
        $this->assertSame($agreement->id, $record->agreement_id);
        $this->action($record, 'renew', ['notes' => 'Tenant replied asking for another 12 months.'])->assertSessionHasNoErrors();
        $signed = fn () => ['reference' => 'Renewal 2027', 'files' => [UploadedFile::fake()->create('renewal.pdf', 1, 'application/pdf')]];
        $this->action($record, 'complete_renewal', $signed())->assertInvalid(['action']);
        $this->post('/portal/accommodation/tenants/'.$tenant->id.'/renew', [
            'new_contract_start' => '2027-01-01', 'new_contract_end' => '2027-12-31',
        ])->assertSessionHasNoErrors();
        $this->action($record, 'complete_renewal', $signed())->assertSessionHasNoErrors();
        $this->assertNotNull($record->fresh()->closed_at);
        $this->assertSame('350.50', $tenant->fresh()->bond_paid_nzd);
        Event::assertDispatchedTimes(MessageSent::class, 1);
    }
}
