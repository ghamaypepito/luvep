<?php

namespace Tests\Feature;

use App\Models\AccommodationAgreement;
use App\Models\ActivityLog;
use App\Models\EoiSubmission;
use App\Models\PreTenancyForm;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccommodationAgreementTenantLinkTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->withoutVite();
        Storage::fake('local');
        Mail::fake();
        Notification::fake();
        $this->actingAs(User::factory()->create(['role' => 'accommodation', 'is_active' => true]));
        $property = Property::create(['name' => 'Synthetic home', 'address' => 'Synthetic address', 'is_active' => true]);
        $source = PreTenancyForm::create(['full_legal_name' => 'Synthetic First Family', 'email' => 'source@example.test', 'submitted_at' => now(), 'data' => ['documents' => []]]);
        $agreement = AccommodationAgreement::create([
            'agreement_type' => 'standard_flatmate', 'pre_tenancy_form_id' => $source->id,
            'client_name' => 'Synthetic First Family', 'status' => 'signed', 'signed_at' => now(),
            'signing_token' => 'synthetic-token', 'signed_pdf_path' => 'signed/fixture.pdf',
            'data' => ['subtenants' => [['first_name' => 'Synthetic First', 'last_name' => 'Family']],
                'tenancy' => ['commence_date' => '2026-11-01', 'end_date' => '2027-10-31', 'weekly_rent' => '300.00']],
        ]);
        Storage::disk('local')->put('signed/fixture.pdf', '%PDF-original-immutable');

        return [$property, $source, $agreement];
    }

    private function payload($property, $agreement, ?Tenant $tenant = null): array
    {
        $response = $this->get('/portal/accommodation/forms/agreements/'.$agreement->id.'/tenant-link')->assertOk();

        return ['property_id' => $property->id, 'version' => $response->viewData('page')['props']['version'],
            'mode' => $tenant ? 'existing' : 'create', 'tenant_id' => $tenant?->id, 'tenant_version' => $tenant?->fresh()->move_in_version,
            'confirmed' => true, 'reason' => 'Synthetic source and all signatures reviewed; checked existing tenant records.'];
    }

    private function submit($agreement, array $data)
    {
        return $this->post('/portal/accommodation/forms/agreements/'.$agreement->id.'/tenant-link', $data);
    }

    public function test_legacy_link_creates_searchable_pending_tenant_preserving_signed_evidence_and_payment_controls(): void
    {
        [$property, $source, $agreement] = $this->fixture();
        $original = $agreement->getRawOriginal();
        $data = $this->payload($property, $agreement);
        $this->submit($agreement, $data)->assertRedirect()->assertSessionHasNoErrors();
        $tenant = Tenant::findOrFail($agreement->fresh()->tenant_id);
        $this->assertSame('pending_payment', $tenant->current_status);
        $this->assertNull($tenant->bond_paid_nzd);
        $this->assertNull($tenant->advance_paid_nzd);
        $this->assertSame(0, Tenant::active()->count());
        foreach (['data', 'signed_at', 'signed_pdf_path', 'status', 'signing_token'] as $field) {
            $this->assertSame($original[$field], $agreement->fresh()->getRawOriginal($field));
        }
        $this->assertSame('%PDF-original-immutable', Storage::disk('local')->get('signed/fixture.pdf'));
        $this->get('/portal/accommodation/tenants?search=Synthetic%20First%20Family')->assertInertia(fn (Assert $p) => $p->has('tenants.data', 1));
        $this->get('/portal/accommodation/tenants/'.$tenant->id)->assertInertia(fn (Assert $p) => $p
            ->has('signedAgreements', 1)->has('preTenancySources', 1)->where('canActivate', true)
            ->where('preTenancySources.0.url', route('portal.accommodation.forms.pre-tenancy.show', $source)));
        $this->submit($agreement, $data)->assertSessionHasErrors('version');
        $this->assertSame(1, Tenant::count());
        $this->post('/portal/accommodation/tenants/'.$tenant->id.'/activate', [])->assertSessionHasErrors('payment_confirmed');
        $this->assertSame('pending_payment', $tenant->fresh()->current_status);
        $this->post('/portal/accommodation/tenants/'.$tenant->id.'/activate', ['payment_confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('active', $tenant->fresh()->current_status);
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
        $this->assertDatabaseHas('activity_logs', ['action' => 'accommodation.agreement.tenant_linked', 'entity_id' => $agreement->id]);
    }

    public function test_explicit_existing_record_retains_identity_status_payments_and_searches_issued_name(): void
    {
        [$property, , $agreement] = $this->fixture();
        $tenant = Tenant::create(['property_id' => $property->id, 'first_name' => 'Verified', 'family_name' => 'Identity', 'current_status' => 'active', 'bond_paid_nzd' => 123]);
        $before = $tenant->fresh()->getRawOriginal();
        $this->submit($agreement, $this->payload($property, $agreement, $tenant))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($before, $tenant->fresh()->getRawOriginal());
        $this->assertSame($tenant->id, $agreement->fresh()->tenant_id);
        $this->get('/portal/accommodation/tenants?search=Synthetic%20First%20Family')->assertInertia(fn (Assert $p) => $p->has('tenants.data', 1));
        $this->get('/portal/accommodation/tenants?search=Synthetic&status=pending_payment')->assertInertia(fn (Assert $p) => $p->has('tenants.data', 0));
    }

    public function test_viewed_entry_and_missing_signed_pdf_cannot_create_or_link(): void
    {
        [$property, , $agreement] = $this->fixture();
        $data = $this->payload($property, $agreement);
        $agreement->update(['status' => 'viewed']);
        $this->submit($agreement, $data)->assertUnprocessable();
        $agreement->update(['status' => 'signed']);
        Storage::disk('local')->delete('signed/fixture.pdf');
        $this->submit($agreement, $data)->assertUnprocessable();
        $this->assertSame(0, Tenant::count());
    }

    public function test_conflicting_source_and_duplicate_creation_are_rejected(): void
    {
        [$property, $source, $agreement] = $this->fixture();
        $tenant = Tenant::create(['property_id' => $property->id, 'first_name' => 'Existing', 'family_name' => 'Synthetic', 'current_status' => 'active']);
        $prior = $agreement->replicate();
        $prior->tenant_id = $tenant->id;
        $prior->signing_token = 'synthetic-prior-token';
        $prior->save();
        $this->submit($agreement, $this->payload($property, $agreement))->assertSessionHasErrors('agreement');
        $this->assertNull($agreement->fresh()->tenant_id);
        $this->submit($agreement, $this->payload($property, $agreement, $tenant))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, Tenant::count());
        $this->assertSame($tenant->id, $agreement->fresh()->tenant_id);
    }

    public function test_changed_source_tenant_wrong_property_and_missing_confirmation_are_rejected(): void
    {
        [$property, $source, $agreement] = $this->fixture();
        $data = $this->payload($property, $agreement);
        $source->update(['mobile' => 'synthetic-change']);
        $this->submit($agreement, $data)->assertSessionHasErrors('version');
        $data = $this->payload($property, $agreement);
        $data['confirmed'] = false;
        $this->submit($agreement, $data)->assertSessionHasErrors('confirmed');
        $tenant = Tenant::create(['property_id' => $property->id, 'first_name' => 'Existing', 'family_name' => 'Synthetic']);
        $data = $this->payload($property, $agreement, $tenant);
        $tenant->update(['unit' => 'New label']);
        $this->submit($agreement, $data)->assertSessionHasErrors('tenant_id');
        $other = Property::create(['name' => 'Other', 'is_active' => true]);
        $tenant->update(['property_id' => $other->id]);
        $this->submit($agreement, $this->payload($property, $agreement, $tenant->fresh()))->assertSessionHasErrors('agreement');
        $this->assertNull($agreement->fresh()->tenant_id);
    }

    public function test_incomplete_legacy_terms_fail_without_guessing_dates_or_rewriting_pdf(): void
    {
        [$property, , $agreement] = $this->fixture();
        $snapshot = $agreement->data;
        unset($snapshot['tenancy']['end_date']);
        $agreement->update(['data' => $snapshot]);
        $this->submit($agreement, $this->payload($property, $agreement))->assertSessionHasErrors('contract_end');
        $this->assertSame(0, Tenant::count());
        $this->assertSame('%PDF-original-immutable', Storage::disk('local')->get('signed/fixture.pdf'));
    }

    public function test_onboarding_links_pending_without_faking_payment_or_advancing_source_status(): void
    {
        [$property, , $agreement] = $this->fixture();
        $source = EoiSubmission::create(['full_legal_name' => 'Synthetic Applicant', 'email' => 'source@example.test', 'status' => 'agreement_signed', 'form_type' => 'hot',
            'mobile' => '000', 'id_number' => 'synthetic', 'visa_status' => 'Work Visa', 'nationality' => 'Synthetic',
            'preferred_name' => 'Synthetic', 'age' => 30, 'room_type_interest' => 'single',
            'tenancy_start_date' => '2026-11-18', 'stay_duration' => '1 month', 'occupants' => '1',
            'occupant_ages' => '30', 'employment_status' => 'Employed', 'current_address' => 'Synthetic',
            'current_address_duration' => '1 year', 'living_situation' => 'Renting', 'reason_for_moving' => 'Synthetic',
            'drinks_alcohol' => 'No', 'work_hours' => 'Day', 'flatmate_description' => 'Synthetic', 'preferred_viewing_time' => 'Any']);
        $agreement->update(['pre_tenancy_form_id' => null, 'eoi_submission_id' => $source->id]);
        $this->submit($agreement, $this->payload($property, $agreement))->assertRedirect()->assertSessionHasNoErrors();
        $tenant = Tenant::findOrFail($agreement->fresh()->tenant_id);
        $this->assertSame($tenant->id, $source->fresh()->converted_to_tenant_id);
        $this->assertSame('agreement_signed', $source->fresh()->status);
        $this->assertNull($source->fresh()->payment_confirmed_at);
        $this->assertSame('pending_payment', $tenant->current_status);
        $this->get('/portal/accommodation/tenants/'.$tenant->id)->assertInertia(fn (Assert $p) => $p->where('canActivate', false)->has('preTenancySources', 1));
    }

    public function test_unauthorized_and_preview_sessions_cannot_reconcile(): void
    {
        [$property, , $agreement] = $this->fixture();
        $data = $this->payload($property, $agreement);
        foreach (['education', 'finance', 'immigration_manager', 'lead'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->submit($agreement, $data)->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'accommodation', 'is_active' => false]));
        $this->submit($agreement, $data)->assertRedirect('/login');
        $this->assertSame(0, Tenant::count());
    }

    public function test_audit_failure_rolls_back_tenant_and_links(): void
    {
        [$property, , $agreement] = $this->fixture();
        $data = $this->payload($property, $agreement);
        ActivityLog::creating(function ($log) {
            if ($log->action === 'accommodation.agreement.tenant_linked') {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        $this->submit($agreement, $data)->assertServerError();
        $this->assertSame(0, Tenant::count());
        $this->assertNull($agreement->fresh()->tenant_id);
        ActivityLog::flushEventListeners();
    }

    public function test_full_name_search_spans_fields_and_preserves_other_filters(): void
    {
        [$property] = $this->fixture();
        Tenant::create(['property_id' => $property->id, 'first_name' => 'Another Multi', 'family_name' => 'Part Name', 'current_status' => 'pending_payment']);
        $this->get('/portal/accommodation/tenants?search=Another%20Multi%20Part%20Name')
            ->assertInertia(fn (Assert $p) => $p->has('tenants.data', 1));
        $this->get('/portal/accommodation/tenants?search=Another%20Multi%20Part%20Name&status=active')
            ->assertInertia(fn (Assert $p) => $p->has('tenants.data', 0));
    }

    public function test_view_as_cannot_open_reconciliation_even_with_an_accommodation_target(): void
    {
        [, , $agreement] = $this->fixture();
        $request = \Illuminate\Http\Request::create('/synthetic');
        $request->setUserResolver(fn () => auth()->user());
        $request->attributes->set('user_view_actor', User::factory()->create(['role' => 'super_admin']));
        try {
            app(\App\Http\Controllers\Portal\Accommodation\AgreementTenantLinkController::class)->show($request, $agreement);
            $this->fail('Preview session must be denied.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertNull($agreement->fresh()->tenant_id);
    }
}
