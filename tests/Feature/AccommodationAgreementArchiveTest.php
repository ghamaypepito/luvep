<?php

namespace Tests\Feature;

use App\Models\AccommodationAgreement;
use App\Models\ActivityLog;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccommodationAgreementArchiveTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = '/portal/accommodation/forms/agreements';

    public function test_signed_agreement_can_be_archived_downloaded_and_restored_with_tenant_link_intact(): void
    {
        Storage::fake('local');
        Mail::fake();
        $staff = User::factory()->create(['role' => 'accommodation']);
        $property = Property::create(['name' => 'Test house', 'address' => 'Test address']);
        $tenant = Tenant::create(['property_id' => $property->id, 'first_name' => 'Test', 'family_name' => 'Tenant']);
        $agreement = $this->agreement([
            'tenant_id' => $tenant->id, 'property_id' => $property->id, 'status' => 'signed',
            'signed_pdf_path' => 'test/signed.pdf', 'signed_at' => now(), 'signature_data' => 'signed evidence',
            'signing_token' => 'test-signing-token',
        ]);
        Storage::disk('local')->put('test/signed.pdf', '%PDF-signed');
        $active = $this->agreement(['client_name' => $agreement->client_name]);
        $url = self::PATH.'/'.$agreement->id;

        $this->withoutVite()->actingAs($staff)->from(self::PATH)
            ->patch($url.'/archive')->assertRedirect(self::PATH);
        $this->patch($url.'/archive')->assertRedirect(self::PATH);
        $this->get(self::PATH)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('agreements', 1)->where('agreements.0.id', $active->id)
            ->has('archivedAgreements', 1)->where('archivedAgreements.0.id', $agreement->id)
            ->where('archivedAgreements.0.tenant_id', $tenant->id)
            ->where('archivedAgreements.0.status', 'signed')->where('archivedAgreements.0.can_delete', false));
        $this->get($url.'/download?inline=1')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $download = $this->get($url.'/download')->assertDownload();
        $this->assertSame('%PDF-signed', $download->streamedContent());
        $this->get('/agreement/test-signing-token/signed-pdf')->assertOk();
        $this->delete($url)->assertSessionHas('error');

        $this->patch($url.'/restore')->assertRedirect(self::PATH);
        $this->patch($url.'/restore')->assertRedirect(self::PATH);
        $this->get(self::PATH)->assertInertia(fn (Assert $page) => $page->has('agreements', 2)->has('archivedAgreements', 0));
        $this->assertDatabaseHas('accommodation_agreements', [
            'id' => $agreement->id, 'tenant_id' => $tenant->id, 'status' => 'signed',
            'signed_pdf_path' => 'test/signed.pdf', 'signature_data' => 'signed evidence', 'archived_at' => null,
        ]);
        $this->assertSame(2, ActivityLog::where('entity_type', AccommodationAgreement::class)
            ->where('entity_id', $agreement->id)
            ->whereIn('action', ['accommodation.agreement.archived', 'accommodation.agreement.restored'])->count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'accommodation.agreement.restored', 'user_id' => $staff->id, 'entity_id' => $agreement->id]);
        Storage::disk('local')->assertExists(['test/original.pdf', 'test/signed.pdf']);
        Mail::assertNothingQueued();
    }

    public function test_unsigned_agreement_can_be_deleted_after_restore_but_partial_or_signed_evidence_is_protected(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'accommodation']))->from(self::PATH);
        $unsigned = $this->agreement(['pdf_path' => 'test/duplicate.pdf']);
        $this->patch(self::PATH.'/'.$unsigned->id.'/archive')->assertSessionHas('success');
        $this->delete(self::PATH.'/'.$unsigned->id)->assertSessionHas('error');
        Storage::disk('local')->assertExists('test/duplicate.pdf');
        $this->patch(self::PATH.'/'.$unsigned->id.'/restore')->assertSessionHas('success');
        $this->delete(self::PATH.'/'.$unsigned->id)->assertSessionHas('success');
        $this->assertModelMissing($unsigned);
        Storage::disk('local')->assertMissing('test/duplicate.pdf');

        foreach ([
            ['status' => 'signed'],
            ['data' => ['tenant_signatures' => [['image' => 'stored signature']]]],
            ['signature_data' => 'legacy signature'],
            ['signed_pdf_path' => 'test/signed-proof.pdf'],
            ['signed_at' => now()],
        ] as $protected) {
            $agreement = $this->agreement($protected);
            $this->delete(self::PATH.'/'.$agreement->id)->assertSessionHas('error');
            $this->assertModelExists($agreement);
            Storage::disk('local')->assertExists('test/original.pdf');
        }
    }

    public function test_department_portal_grant_alone_does_not_allow_agreement_management(): void
    {
        Storage::fake('local');
        $agreement = $this->agreement();
        $this->actingAs(User::factory()->create(['role' => 'education', 'portal_access' => ['accommodation']]));
        $this->patch(self::PATH.'/'.$agreement->id.'/archive')->assertForbidden();
        $this->patch(self::PATH.'/'.$agreement->id.'/restore')->assertForbidden();
        $this->delete(self::PATH.'/'.$agreement->id)->assertForbidden();
        $this->assertModelExists($agreement);
    }

    private function agreement(array $details = []): AccommodationAgreement
    {
        $agreement = AccommodationAgreement::create(array_replace([
            'client_name' => 'Test Client', 'agreement_type' => 'standard_flatmate',
            'status' => 'viewed', 'pdf_path' => 'test/original.pdf', 'data' => [],
        ], $details));
        Storage::disk('local')->put($agreement->pdf_path, '%PDF-original');

        return $agreement;
    }
}
