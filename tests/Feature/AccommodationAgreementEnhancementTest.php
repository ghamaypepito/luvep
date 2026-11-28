<?php

namespace Tests\Feature;

use App\Mail\AccommodationSignedCopy;
use App\Models\AccommodationAgreement;
use App\Models\EoiSubmission;
use App\Models\PreTenancyForm;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accommodation\AgreementTerms;
use App\Services\PdfGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccommodationAgreementEnhancementTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a6mQAAAAASUVORK5CYII=';

    private function setupAgreement(): array
    {
        Storage::fake('local');
        Mail::fake();
        $this->withoutVite();
        $staff = User::factory()->create(['role' => 'accommodation']);
        $alane = User::factory()->create(['role' => 'accommodation', 'name' => 'Alane', 'email' => AgreementTerms::SIGNATORY_EMAIL, 'signature_path' => 'signatures/alane.png']);
        $image = imagecreatetruecolor(500, 80);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagestring($image, 5, 20, 25, 'SYNTHETIC TEST SIGNATURE', imagecolorallocate($image, 0, 0, 0));
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        Storage::disk('local')->put($alane->signature_path, $bytes);
        $property = Property::create(['name' => 'Synthetic House', 'address' => 'Synthetic address', 'is_active' => true, 'total_rooms' => 2]);
        $source = PreTenancyForm::create(['full_legal_name' => 'Synthetic Tenant', 'email' => 'source@example.test', 'submitted_at' => now(), 'data' => []]);
        $data = [
            'property_id' => $property->id, 'notes' => "Special <script>text</script>\nSecond line",
            'subtenants' => [['first_name' => 'Synthetic', 'last_name' => 'Tenant', 'email' => 'tenant@example.test']],
            'tenancy' => ['commence_date' => '2026-11-18', 'move_in_date' => '2026-11-18', 'duration' => 1, 'duration_unit' => 'months', 'weekly_rent' => '700', 'utility_option' => '1', 'utility_amount' => '70'],
            'costs' => ['bond' => '700', 'deposit' => '700', 'advance_weeks' => 1, 'total_move_in' => '1.00'],
        ];
        $this->actingAs($staff);

        return [$staff, $alane, $property, $source, $data];
    }

    private function generate($source, array $data, string $type = 'standard_flatmate'): AccommodationAgreement
    {
        $this->post('/portal/accommodation/forms/agreements/generate', [
            'source' => 'general', 'source_id' => $source->id, 'agreement_type' => $type, 'data' => $data,
        ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');

        return AccommodationAgreement::latest('id')->firstOrFail();
    }

    private function fakeSignedPdf(): void
    {
        $this->mock(PdfGenerator::class, function ($mock) {
            $mock->shouldReceive('renderToFile')->andReturnUsing(function ($html, $path) {
                $this->assertStringContainsString('Notes / Special Conditions', $html);
                $this->assertStringContainsString(AgreementTerms::SIGNATORY, $html);
                preg_match('/Declared at(.*?)Before me/s', $html, $declaration);
                $this->assertStringNotContainsString('<img', $declaration[1]);
                Storage::disk('local')->put($path, '%PDF-synthetic-signed');

                return $path;
            });
        });
    }

    private function sign(AccommodationAgreement $agreement)
    {
        return $this->post('/agreement/'.$agreement->signing_token.'/sign', [
            'signer_name' => 'Synthetic Tenant', 'signature_data' => self::PNG, 'terms_accepted' => true,
        ]);
    }

    public function test_preview_generate_and_signed_copy_share_canonical_terms(): void
    {
        [$staff, $alane, $property, $source, $data] = $this->setupAgreement();
        foreach (array_keys(AccommodationAgreement::TYPES) as $type) {
            $preview = $this->postJson('/portal/accommodation/forms/agreements/preview', ['agreement_type' => $type, 'data' => $data])->assertOk();
            $preview->assertJsonPath('data.costs.rent_advance_from', '2026-11-23')
                ->assertJsonPath('data.costs.rent_advance_to', '2026-11-29')
                ->assertJsonPath('data.costs.prorated_days', 5)->assertJsonPath('data.costs.prorated_rent', '500.00')
                ->assertJsonPath('data.costs.total_move_in', '2600.00')->assertJsonPath('data.tenancy.end_date', '2026-12-17');
            $this->assertStringNotContainsString('<script>text</script>', $preview->json('html'));
            $agreement = $this->generate($source, $data, $type);
            $this->assertSame($preview->json('data.costs'), $agreement->data['costs']);
            $this->assertSame($alane->id, $agreement->data['admin_user_id']);
            $this->assertSame(AgreementTerms::SIGNATORY, $agreement->data['admin_name']);
            $this->assertNotSame($staff->id, $agreement->data['admin_user_id']);
            $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($agreement->pdf_path));
        }
        $this->fakeSignedPdf();
        $this->sign($agreement)->assertRedirect();
        $agreement->refresh();
        $tenant = Tenant::findOrFail($agreement->tenant_id);
        $this->assertSame('pending_payment', $tenant->current_status);
        $this->assertNull($tenant->bond_paid_nzd);
        $this->assertNull($tenant->advance_paid_nzd);
        $this->assertSame(0, $property->fresh()->rooms_occupied);
        $this->assertSame(0, Tenant::active()->count());
        $this->post('/portal/accommodation/tenants/'.$tenant->id.'/notice', ['reason' => 'Synthetic'])->assertUnprocessable();
        $this->assertSame('pending_payment', $tenant->fresh()->current_status);
        Mail::assertQueued(AccommodationSignedCopy::class, fn ($mail) => $mail->hasTo('source@example.test') && $mail->pdfPath === $agreement->signed_pdf_path);
        $copy = new AccommodationSignedCopy('Synthetic Tenant', $agreement->signed_pdf_path);
        $copy->assertSeeInHtml('Exalt Property Management');
        $copy->assertHasAttachmentFromStorageDisk('local', $agreement->signed_pdf_path, 'signed-exalt-agreement.pdf', ['mime' => 'application/pdf']);
        $this->get('/portal/accommodation/tenants/'.$tenant->id)->assertInertia(fn (Assert $p) => $p->has('signedAgreements', 1));
        $this->get('/portal/accommodation/tenants?search=Synthetic&status=pending_payment')->assertInertia(fn (Assert $p) => $p->has('tenants.data', 1));
        $this->get('/portal/accommodation/rent-utilities')->assertInertia(fn (Assert $p) => $p->has('groups', 0));
        $this->sign($agreement)->assertStatus(409);
        $this->assertSame(1, Tenant::count());
        Mail::assertQueued(AccommodationSignedCopy::class, 1);
        $this->post('/portal/accommodation/forms/agreements/'.$agreement->id.'/resend')->assertSessionHas('success');
        Mail::assertQueued(AccommodationSignedCopy::class, 2);
        $this->delete('/portal/accommodation/forms/agreements/'.$agreement->id)->assertSessionHas('error');
        $this->assertDatabaseHas('accommodation_agreements', ['id' => $agreement->id]);
        $this->delete('/portal/accommodation/properties/'.$property->id)->assertSessionHas('error');
        $this->get('/agreement/'.$agreement->signing_token.'/signed-pdf')->assertDownload();
        $this->get('/agreement/not-a-real-token/signed-pdf')->assertNotFound();
    }

    public function test_same_source_reuses_its_tenant_without_overwriting_identity_or_status(): void
    {
        [, , , $source, $data] = $this->setupAgreement();
        $this->fakeSignedPdf();
        $first = $this->generate($source, $data);
        $this->sign($first)->assertRedirect();
        $tenant = Tenant::findOrFail($first->fresh()->tenant_id);
        $tenant->update(['first_name' => 'Verified name', 'current_status' => 'active']);
        $second = $this->generate($source, $data);
        $this->sign($second)->assertRedirect();
        $this->assertSame($tenant->id, $second->fresh()->tenant_id);
        $this->assertSame('Verified name', $tenant->fresh()->first_name);
        $this->assertSame('active', $tenant->fresh()->current_status);
        $this->assertSame(1, Tenant::count());
    }

    public function test_failed_pdf_does_not_mark_signed_create_a_tenant_or_send_copy(): void
    {
        [, , , $source, $data] = $this->setupAgreement();
        $agreement = $this->generate($source, $data);
        $this->mock(PdfGenerator::class, fn ($m) => $m->shouldReceive('renderToFile')->andThrow(new \RuntimeException('Synthetic PDF failure')));
        $this->sign($agreement)->assertStatus(500);
        $this->assertSame('sent', $agreement->fresh()->status);
        $this->assertNull($agreement->fresh()->signed_pdf_path);
        $this->assertSame(0, Tenant::count());
        Mail::assertNotQueued(AccommodationSignedCopy::class);
    }

    public function test_missing_or_ambiguous_signatory_and_invalid_inputs_cannot_issue(): void
    {
        [, $alane, , $source, $data] = $this->setupAgreement();
        $alane->update(['signature_path' => null]);
        $this->post('/portal/accommodation/forms/agreements/generate', ['source' => 'general', 'source_id' => $source->id, 'agreement_type' => 'standard_flatmate', 'data' => $data])->assertSessionHasErrors('agreement');
        $this->assertSame(0, AccommodationAgreement::count());
        User::factory()->create(['role' => 'accommodation', 'name' => AgreementTerms::SIGNATORY, 'signature_path' => 'signatures/alane.png']);
        $this->assertNull(app(AgreementTerms::class)->signatory(), 'An unrelated same-name account must not substitute for the approved signer');
        $data['costs']['bond'] = '-1';
        $this->postJson('/portal/accommodation/forms/agreements/preview', ['agreement_type' => 'standard_flatmate', 'data' => $data])->assertUnprocessable()->assertJsonValidationErrors('data.costs.bond');
        $data['costs']['bond'] = '0';
        $data['tenancy']['move_in_date'] = '2027-01-01';
        $this->postJson('/portal/accommodation/forms/agreements/preview', ['agreement_type' => 'standard_flatmate', 'data' => $data])->assertUnprocessable()->assertJsonValidationErrors('data.tenancy.move_in_date');
    }

    public function test_signature_removed_after_lookup_cannot_issue_a_blank_agreement(): void
    {
        [, $alane, , $source, $data] = $this->setupAgreement();
        $this->partialMock(AgreementTerms::class, function ($mock) use ($alane) {
            $mock->shouldReceive('signatory')->andReturnUsing(function () use ($alane) {
                Storage::disk('local')->delete($alane->signature_path);

                return $alane;
            });
        });
        $this->post('/portal/accommodation/forms/agreements/generate', ['source' => 'general', 'source_id' => $source->id, 'agreement_type' => 'standard_flatmate', 'data' => $data])
            ->assertSessionHasErrors('agreement');
        $this->assertSame(0, AccommodationAgreement::count());
        Mail::assertNothingOutgoing();
    }

    public function test_monday_short_stay_month_end_and_dst_dates_have_explicit_results(): void
    {
        [, , , , $data] = $this->setupAgreement();
        $terms = app(AgreementTerms::class);
        $data['tenancy'] = ['commence_date' => '2026-09-28', 'move_in_date' => '2026-09-28', 'duration' => 1, 'duration_unit' => 'weeks', 'weekly_rent' => '700'];
        $calculated = $terms->calculate($data, true);
        $this->assertSame(0, $calculated['costs']['prorated_days']);
        $this->assertSame('2026-10-04', $calculated['tenancy']['end_date']);
        $this->assertSame('700.00', $calculated['costs']['rent_advance']);
        $data['tenancy'] = ['commence_date' => '2026-09-27', 'move_in_date' => '2026-09-27', 'duration' => 1, 'duration_unit' => 'days', 'weekly_rent' => '700'];
        $calculated = $terms->calculate($data, true);
        $this->assertSame(1, $calculated['costs']['prorated_days']);
        $this->assertSame('', $calculated['costs']['rent_advance_from']);
        $this->assertSame('100.00', $calculated['costs']['prorated_rent']);
        $data['tenancy'] = ['commence_date' => '2028-01-31', 'move_in_date' => '2028-01-31', 'duration' => 1, 'duration_unit' => 'months', 'weekly_rent' => '100.01'];
        $this->assertSame('2028-02-28', $terms->calculate($data, true)['tenancy']['end_date']);
        $data['tenancy'] = ['commence_date' => '2026-11-18', 'move_in_date' => '2026-11-18', 'duration' => 1, 'duration_unit' => 'months', 'weekly_rent' => '100.01'];
        $data['costs']['bond'] = '1.23';
        $data['costs']['deposit'] = '2.34';
        $calculated = $terms->calculate($data, true);
        $this->assertSame('71.44', $calculated['costs']['prorated_rent']);
        $this->assertSame('175.02', $calculated['costs']['total_move_in']);
    }

    public function test_onboarding_payment_activates_the_linked_tenant_without_duplicate(): void
    {
        [, , $property, , $data] = $this->setupAgreement();
        $data['tenancy']['duration'] = 1;
        $data['tenancy']['duration_unit'] = 'days';
        $source = EoiSubmission::create([
            'full_legal_name' => 'Synthetic Tenant', 'email' => 'onboarding@example.test', 'mobile' => '000',
            'id_number' => 'synthetic', 'visa_status' => 'Work Visa', 'nationality' => 'Synthetic',
            'preferred_name' => 'Synthetic', 'age' => 30, 'room_type_interest' => 'single',
            'tenancy_start_date' => '2026-11-18', 'stay_duration' => '1 month', 'occupants' => '1',
            'occupant_ages' => '30', 'employment_status' => 'Employed', 'current_address' => 'Synthetic',
            'current_address_duration' => '1 year', 'living_situation' => 'Renting', 'reason_for_moving' => 'Synthetic',
            'drinks_alcohol' => 'No', 'work_hours' => 'Day', 'flatmate_description' => 'Synthetic',
            'preferred_viewing_time' => 'Any', 'status' => 'pre_tenancy_form_completed',
            'pre_tenancy_form_data' => ['main' => ['full_legal_name' => 'Synthetic Tenant']], 'property_id' => $property->id,
        ]);
        $this->post('/portal/accommodation/forms/agreements/generate', ['source' => 'onboarding', 'source_id' => $source->id, 'agreement_type' => 'standard_flatmate', 'data' => $data])->assertSessionHasNoErrors();
        $agreement = AccommodationAgreement::latest('id')->firstOrFail();
        $this->fakeSignedPdf();
        $this->sign($agreement)->assertRedirect();
        $tenantId = $agreement->fresh()->tenant_id;
        $this->assertSame($tenantId, $source->fresh()->converted_to_tenant_id);
        $this->assertSame('agreement_signed', $source->fresh()->status);
        $payload = ['expected_tenant_version' => Tenant::findOrFail($tenantId)->move_in_version, 'property_id' => $property->id, 'first_name' => 'Synthetic', 'family_name' => 'Tenant', 'contract_type' => 'fixed_term', 'contract_start' => '2026-11-18', 'contract_end' => '2026-11-18'];
        $url = '/portal/accommodation/applications/'.$source->id;
        $this->post($url.'/convert', $payload)->assertSessionHas('error');
        $this->assertSame('pending_payment', Tenant::find($tenantId)->current_status);
        app(\App\Services\OnboardingTransitionService::class)->transition($source->fresh(), 'invoice_sent', ['invoice_amount_nzd' => 1500]);
        $this->patch($url.'/status', ['status' => 'payment_confirmed', 'verified_move_in_amount_nzd' => 1500])->assertSessionHasNoErrors();
        $roomId = (string) \Illuminate\Support\Str::uuid();
        $property->forceFill(['total_rooms' => 1, 'room_register' => [['id' => $roomId, 'name' => 'Room A', 'hold' => false]]])->save();
        $payload['assigned_room_ids'] = [$roomId];
        $this->post($url.'/convert', $payload)->assertRedirect('/portal/accommodation/tenants/'.$tenantId)->assertSessionHasNoErrors();
        $this->assertSame('active', Tenant::find($tenantId)->current_status);
        $this->assertSame([$roomId], Tenant::find($tenantId)->assigned_room_ids);
        $this->assertSame('moved_in', $source->fresh()->status);
        $this->assertSame(1, Tenant::count());
        $this->assertSame(1, $property->fresh()->rooms_occupied);
        $this->post($url.'/convert', $payload)->assertSessionHas('error');
        $this->assertSame(1, Tenant::count());
    }

    public function test_other_portal_users_cannot_read_or_issue_even_with_portal_grants(): void
    {
        [, , , $source, $data] = $this->setupAgreement();
        $agreement = $this->generate($source, $data);
        $user = User::factory()->create(['role' => 'education', 'portal_access' => ['accommodation']]);
        $this->actingAs($user)->get('/portal/accommodation/forms/agreements')->assertForbidden();
        $this->postJson('/portal/accommodation/forms/agreements/preview', ['agreement_type' => 'standard_flatmate', 'data' => $data])->assertForbidden();
        $this->get('/portal/accommodation/forms/agreements/'.$agreement->id.'/download')->assertForbidden();
        $this->post('/portal/accommodation/forms/agreements/generate', ['source' => 'general', 'source_id' => $source->id, 'agreement_type' => 'standard_flatmate', 'data' => $data])->assertForbidden();
        $this->assertSame(1, AccommodationAgreement::count());
    }

    public function test_legacy_issued_agreements_are_not_recalculated_or_auto_converted(): void
    {
        [, , , $source, $data] = $this->setupAgreement();
        $legacy = AccommodationAgreement::create([
            'agreement_type' => 'standard_flatmate', 'pre_tenancy_form_id' => $source->id,
            'client_name' => 'Legacy', 'data' => $data, 'signing_token' => 'legacy-token', 'status' => 'sent',
        ]);
        $this->get('/agreement/legacy-token/document')->assertOk()->assertSee('1.00');
        $this->mock(PdfGenerator::class, function ($mock) {
            $mock->shouldReceive('renderToFile')->andReturnUsing(function ($html, $path) {
                Storage::disk('local')->put($path, '%PDF-legacy');

                return $path;
            });
        });
        $this->sign($legacy)->assertRedirect();
        $this->assertNull($legacy->fresh()->tenant_id);
        $this->assertSame('1.00', $legacy->fresh()->data['costs']['total_move_in']);
        $this->assertSame(0, Tenant::count());
        Mail::assertNotQueued(AccommodationSignedCopy::class);
    }

    public function test_actual_signed_pdf_preserves_notes_dates_and_signatory_snapshot(): void
    {
        [, $alane, , $source, $data] = $this->setupAgreement();
        $data['notes'] = "Synthetic special conditions for review.\nSecond line retained.";
        $agreement = $this->generate($source, $data);
        $snapshot = $agreement->data['admin_signature'];
        $alane->update(['signature_path' => null]);
        $this->post('/agreement/'.$agreement->signing_token.'/sign', [
            'signer_name' => 'Synthetic Tenant', 'signature_data' => $snapshot, 'terms_accepted' => true,
        ])->assertRedirect();
        $agreement->refresh();
        $this->assertSame($snapshot, $agreement->data['admin_signature']);
        $bytes = Storage::disk('local')->get($agreement->signed_pdf_path);
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertGreaterThan(5000, strlen($bytes));
        if ($qaDir = getenv('GJBKFO_QA_DIR')) {
            if (! is_dir($qaDir)) {
                mkdir($qaDir, 0777, true);
            }
            file_put_contents($qaDir.'/synthetic-signed-agreement.pdf', $bytes);
        }
    }

    public function test_each_listed_tenant_must_sign_before_completion(): void
    {
        [, , , $source, $data] = $this->setupAgreement();
        $data['subtenants'][] = ['first_name' => 'Second', 'last_name' => 'Tenant'];
        $data['tenant_signatures'] = [1 => ['name' => 'Forged evidence']];
        $agreement = $this->generate($source, $data);
        $this->assertSame([], $agreement->data['tenant_signatures']);
        $this->fakeSignedPdf();
        $this->sign($agreement)->assertRedirect('/agreement/'.$agreement->signing_token.'/sign');
        $this->assertSame('viewed', $agreement->fresh()->status);
        $this->assertSame(0, Tenant::count());
        Mail::assertNotQueued(AccommodationSignedCopy::class);
        $this->sign($agreement)->assertStatus(409);
        $this->post('/agreement/'.$agreement->signing_token.'/sign', [
            'signer_name' => 'Second Tenant', 'signer_index' => 1,
            'signature_data' => self::PNG, 'terms_accepted' => true,
        ])->assertRedirect('/agreement/'.$agreement->signing_token.'/signed');
        $this->assertSame('signed', $agreement->fresh()->status);
        $this->assertCount(2, $agreement->fresh()->data['tenant_signatures']);
        $this->assertSame(1, Tenant::count());
        Mail::assertQueued(AccommodationSignedCopy::class, 1);
    }

    public function test_transient_inclusive_rent_does_not_add_separate_utilities(): void
    {
        [, , , $source, $data] = $this->setupAgreement();
        $agreement = $this->generate($source, $data, 'transient_flatmate');
        $this->fakeSignedPdf();
        $this->sign($agreement)->assertRedirect();
        $tenant = Tenant::findOrFail($agreement->fresh()->tenant_id);
        $this->assertSame('700.00', $tenant->weekly_total_due);
        $this->assertNull($tenant->weekly_utilities_nzd);
    }

    public function test_partial_signatures_and_freshly_completed_agreements_cannot_be_deleted(): void
    {
        [, , , $source, $data] = $this->setupAgreement();
        $data['subtenants'][] = ['first_name' => 'Second', 'last_name' => 'Tenant'];
        $agreement = $this->generate($source, $data);
        $stale = $agreement->fresh();
        $this->sign($agreement)->assertRedirect();
        $url = '/portal/accommodation/forms/agreements/'.$agreement->id;
        $this->delete($url)->assertSessionHas('error');
        $this->assertCount(1, $agreement->fresh()->data['tenant_signatures']);
        Storage::disk('local')->assertExists($agreement->pdf_path);
        $this->fakeSignedPdf();
        $this->post('/agreement/'.$agreement->signing_token.'/sign', ['signer_name' => 'Second Tenant', 'signer_index' => 1, 'signature_data' => self::PNG, 'terms_accepted' => true])->assertRedirect();
        // Route binding may have happened before the signature committed.
        app(\App\Http\Controllers\Portal\AccommodationAgreementController::class)->destroy($stale);
        $this->assertNotNull($agreement->fresh());
        Storage::disk('local')->assertExists($agreement->pdf_path);
        Storage::disk('local')->assertExists($agreement->fresh()->signed_pdf_path);
    }

    public function test_standalone_payment_review_activates_once_and_generic_edits_cannot_activate(): void
    {
        [, , $property, $source, $data] = $this->setupAgreement();
        $agreement = $this->generate($source, $data);
        $this->fakeSignedPdf();
        $this->sign($agreement)->assertRedirect();
        $tenant = Tenant::findOrFail($agreement->fresh()->tenant_id);
        $url = '/portal/accommodation/tenants/'.$tenant->id;
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('canActivate', true));
        $this->put($url, ['property_id' => $property->id, 'first_name' => 'Synthetic', 'family_name' => 'Tenant', 'contract_type' => 'fixed_term', 'current_status' => 'active'])->assertSessionHasErrors('current_status');
        $this->post($url.'/activate', [])->assertSessionHasErrors('payment_confirmed');
        $this->assertSame(0, $property->fresh()->rooms_occupied);
        $this->post($url.'/activate', ['payment_confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('active', $tenant->fresh()->current_status);
        $this->assertSame(1, $property->fresh()->rooms_occupied);
        $this->post($url.'/activate', ['payment_confirmed' => true])->assertStatus(422);
        $this->assertSame(1, Tenant::count());
    }

    public function test_conversion_reloads_stale_source_link_before_creating_a_tenant(): void
    {
        [, , $property, , $data] = $this->setupAgreement();
        $source = $this->onboardingSource($property);
        $this->post('/portal/accommodation/forms/agreements/generate', ['source' => 'onboarding', 'source_id' => $source->id, 'agreement_type' => 'standard_flatmate', 'data' => $data])->assertSessionHasNoErrors();
        $agreement = AccommodationAgreement::latest('id')->firstOrFail();
        $source->update(['status' => 'payment_confirmed']);
        $stale = $source->fresh();
        $this->fakeSignedPdf();
        $this->sign($agreement)->assertRedirect();
        $tenantId = $agreement->fresh()->tenant_id;
        $source->refresh()->update(['status' => 'payment_confirmed']);
        $request = \Mockery::mock(\App\Http\Requests\Onboarding\ConvertToTenantRequest::class);
        $request->shouldReceive('validated')->andReturn(['expected_tenant_version' => Tenant::findOrFail($tenantId)->move_in_version, 'property_id' => $property->id, 'first_name' => 'Synthetic', 'family_name' => 'Tenant', 'contract_type' => 'fixed_term', 'contract_start' => '2026-11-18', 'contract_end' => '2026-12-17']);
        app(\App\Http\Controllers\Portal\EoiSubmissionController::class)->convertToTenant($request, $stale);
        $this->assertSame(1, Tenant::count());
        $this->assertSame($tenantId, $source->fresh()->converted_to_tenant_id);
        $this->assertSame('active', Tenant::findOrFail($tenantId)->current_status);
    }

    public function test_onboarding_tenant_cannot_use_standalone_activation_or_expose_signing_link_to_other_roles(): void
    {
        [, , $property, , $data] = $this->setupAgreement();
        $source = $this->onboardingSource($property);
        $this->post('/portal/accommodation/forms/agreements/generate', ['source' => 'onboarding', 'source_id' => $source->id, 'agreement_type' => 'standard_flatmate', 'data' => $data])->assertSessionHasNoErrors();
        $agreement = AccommodationAgreement::latest('id')->firstOrFail();
        $this->fakeSignedPdf();
        $this->sign($agreement)->assertRedirect();
        $tenantId = $agreement->fresh()->tenant_id;
        $this->get('/portal/accommodation/tenants/'.$tenantId)->assertInertia(fn (Assert $page) => $page->where('canActivate', false));
        $this->post('/portal/accommodation/tenants/'.$tenantId.'/activate', ['payment_confirmed' => true])->assertStatus(422);
        $this->assertSame('pending_payment', Tenant::findOrFail($tenantId)->current_status);
        $this->actingAs(User::factory()->create(['role' => 'education', 'portal_access' => ['accommodation']]))
            ->get('/portal/accommodation/applications/'.$source->id)->assertInertia(fn (Assert $page) => $page->where('agreement', null));
    }

    public function test_signer_name_must_match_the_selected_party_and_pending_lifecycle_is_protected(): void
    {
        [, , , $source, $data] = $this->setupAgreement();
        $agreement = $this->generate($source, $data);
        $this->post('/agreement/'.$agreement->signing_token.'/sign', ['signer_name' => 'Different Person', 'signature_data' => self::PNG, 'terms_accepted' => true])
            ->assertSessionHasErrors('signer_name');
        $this->assertSame([], $agreement->fresh()->data['tenant_signatures']);
        $this->fakeSignedPdf();
        $this->sign($agreement)->assertRedirect();
        $url = '/portal/accommodation/tenants/'.$agreement->fresh()->tenant_id;
        $this->post($url.'/vacate', ['vacate_date' => '2026-11-20'])->assertStatus(422);
        $this->post($url.'/renew', ['new_contract_start' => '2026-12-18', 'new_contract_end' => '2027-12-17'])->assertStatus(422);
        $this->actingAs(User::factory()->create(['role' => 'education', 'portal_access' => ['accommodation']]))
            ->post($url.'/activate', ['payment_confirmed' => true])->assertForbidden();
    }

    public function test_stale_move_in_form_cannot_erase_newly_recorded_payments_or_contacts(): void
    {
        [, , $property, , $data] = $this->setupAgreement();
        $source = $this->onboardingSource($property);
        $this->post('/portal/accommodation/forms/agreements/generate', ['source' => 'onboarding', 'source_id' => $source->id, 'agreement_type' => 'standard_flatmate', 'data' => $data])->assertSessionHasNoErrors();
        $agreement = AccommodationAgreement::latest('id')->firstOrFail();
        $this->fakeSignedPdf();
        $this->sign($agreement)->assertRedirect();
        $tenant = Tenant::findOrFail($agreement->fresh()->tenant_id);
        $payload = ['expected_tenant_version' => $tenant->move_in_version, 'property_id' => $property->id,
            'first_name' => 'Synthetic', 'family_name' => 'Tenant', 'contract_type' => 'fixed_term',
            'contract_start' => '2026-11-18', 'contract_end' => '2026-12-17', 'bond_paid_nzd' => '',
            'advance_paid_nzd' => '', 'email' => $tenant->email];
        $source->refresh()->update(['status' => 'payment_confirmed']);
        $tenant->update(['bond_paid_nzd' => 700, 'advance_paid_nzd' => 350, 'email' => 'corrected@example.test']);
        $url = '/portal/accommodation/applications/'.$source->id.'/convert';
        $this->post($url, $payload)->assertSessionHasErrors('expected_tenant_version');
        $tenant->refresh();
        $this->assertSame('700.00', $tenant->bond_paid_nzd);
        $this->assertSame('350.00', $tenant->advance_paid_nzd);
        $this->assertSame('corrected@example.test', $tenant->email);
        $this->assertSame('pending_payment', $tenant->current_status);
        $this->post($url, [...$payload, 'expected_tenant_version' => $tenant->move_in_version,
            'bond_paid_nzd' => $tenant->bond_paid_nzd, 'advance_paid_nzd' => $tenant->advance_paid_nzd,
            'email' => $tenant->email])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('active', $tenant->fresh()->current_status);
        $this->assertSame('700.00', $tenant->fresh()->bond_paid_nzd);
    }

    private function onboardingSource(Property $property): EoiSubmission
    {
        return EoiSubmission::create([
            'full_legal_name' => 'Synthetic Tenant', 'email' => 'onboarding@example.test', 'mobile' => '000',
            'id_number' => 'synthetic', 'visa_status' => 'Work Visa', 'nationality' => 'Synthetic',
            'preferred_name' => 'Synthetic', 'age' => 30, 'room_type_interest' => 'single',
            'tenancy_start_date' => '2026-11-18', 'stay_duration' => '1 month', 'occupants' => '1',
            'occupant_ages' => '30', 'employment_status' => 'Employed', 'current_address' => 'Synthetic',
            'current_address_duration' => '1 year', 'living_situation' => 'Renting', 'reason_for_moving' => 'Synthetic',
            'drinks_alcohol' => 'No', 'work_hours' => 'Day', 'flatmate_description' => 'Synthetic',
            'preferred_viewing_time' => 'Any', 'status' => 'pre_tenancy_form_completed',
            'pre_tenancy_form_data' => ['main' => ['full_legal_name' => 'Synthetic Tenant']], 'property_id' => $property->id,
        ]);
    }

    public function test_conflicting_link_rolls_back_the_last_signature_and_removes_its_pdf(): void
    {
        [, , , $source, $data] = $this->setupAgreement();
        $this->fakeSignedPdf();
        $first = $this->generate($source, $data);
        $this->sign($first)->assertRedirect();
        $data['property_id'] = Property::create(['name' => 'Different synthetic property', 'is_active' => true])->id;
        $second = $this->generate($source, $data);
        $files = Storage::disk('local')->allFiles();
        $this->sign($second)->assertUnprocessable();
        $this->assertSame('sent', $second->fresh()->status);
        $this->assertNull($second->fresh()->tenant_id);
        $this->assertSame([], $second->fresh()->data['tenant_signatures']);
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertSame(1, Tenant::count());
        Mail::assertQueued(AccommodationSignedCopy::class, 1);
    }
}
