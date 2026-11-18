<?php

namespace Tests\Feature\Immigration;

use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\User;
use App\Models\VisaType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Build 12 §2 — advice-bearing content is gated by a current IAA licence, not
 * by role. These tests pin the guardrail so it survives a future route-group
 * refactor: without them, an unlicensed user silently gaining approval rights
 * would go unnoticed until someone approved something they should not have.
 *
 * Case documents and catalogue pricing are ordinary role-authorized work.
 * Adviser catalogue access was expanded after Build 12; it does not grant
 * approval rights over advice-bearing content without a current licence.
 */
class AdviceBearingLicenceTest extends TestCase
{
    use RefreshDatabase;

    private function adviser(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'role' => User::ROLE_IMMIGRATION_ADVISER,
        ], $attrs));
    }

    // ── The gate: licence, not role ─────────────────────────────────────────

    public function test_current_licence_can_approve_advice_bearing_content(): void
    {
        $user = $this->adviser([
            'iaa_licence_number' => 'IAA-2001',
            'iaa_licence_expiry' => now()->addYear(),
        ]);

        $this->assertTrue($user->holdsCurrentLicence());
        $this->assertTrue(Gate::forUser($user)->allows('approve-advice-bearing'));
    }

    public function test_expired_licence_cannot_approve(): void
    {
        // A lapsed licence must close the gate automatically — the whole point
        // of gating on expiry rather than the role string.
        $user = $this->adviser([
            'iaa_licence_number' => 'IAA-2001',
            'iaa_licence_expiry' => now()->subDay(),
        ]);

        $this->assertFalse($user->holdsCurrentLicence());
        $this->assertFalse(Gate::forUser($user)->allows('approve-advice-bearing'));
    }

    public function test_no_licence_cannot_approve(): void
    {
        // Holds the adviser ROLE but has no licence on file → still denied.
        $user = $this->adviser([
            'iaa_licence_number' => null,
            'iaa_licence_expiry' => null,
        ]);

        $this->assertFalse($user->holdsCurrentLicence());
        $this->assertFalse(Gate::forUser($user)->allows('approve-advice-bearing'));
    }

    public function test_admin_without_a_licence_cannot_approve(): void
    {
        // A powerful role is not a substitute for a licence. This is also how
        // an AI / system actor is denied structurally: it runs as a user with
        // no licence, so it can never be recorded as the approver.
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'iaa_licence_number' => null,
            'iaa_licence_expiry' => null,
        ]);

        $this->assertFalse($admin->holdsCurrentLicence());
        $this->assertFalse(Gate::forUser($admin)->allows('approve-advice-bearing'));
    }

    // ── The boundary the flip drew ──────────────────────────────────────────

    public function test_adviser_can_write_case_documents(): void
    {
        Storage::fake('local');

        $case = Lead::create([
            'first_name' => 'Visa', 'last_name' => 'Applicant',
            'email' => 'visa@example.test', 'is_immigration_case' => true,
            'inz_visa_type' => 'Student Visa',
        ]);

        // The adviser was previously OUTSIDE the document-write route group;
        // Build 12 §2 added them. An unlicensed adviser still passes here —
        // document upload is ordinary work, not an advice-bearing artifact.
        $this->actingAs($this->adviser())
            ->post("/admin/leads/{$case->id}/documents/checklist/passport/upload", [
                'files' => [UploadedFile::fake()->create('passport.pdf', 20, 'application/pdf')],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('lead_documents', [
            'lead_id' => $case->id,
            'checklist_key' => 'passport',
        ]);
    }

    public function test_an_unlicensed_adviser_can_edit_catalogue_fees_with_an_audit_trail(): void
    {
        $adviser = $this->adviser();
        $visa = VisaType::create([
            'code' => 'TESTVISA', 'name' => 'Test Visa', 'category' => 'work',
            'consultation_price_nzd' => 250, 'consultation_duration_minutes' => 30,
            'estimated_minutes' => 15, 'icon' => 'Globe', 'active' => true,
            'professional_fees' => 1000,
        ]);

        $this->actingAs($adviser)
            ->post("/portal/immigration/visa-types/{$visa->id}", [
                'name' => 'Test Visa', 'code' => 'TESTVISA', 'icon' => 'Globe',
                'active' => true, 'consultation_price_nzd' => 250,
                'consultation_duration_minutes' => 30, 'estimated_minutes' => 15,
                'professional_fees' => 9999,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(9999.0, (float) $visa->fresh()->professional_fees);
        $audit = ActivityLog::where('action', 'visa_type.updated')
            ->where('entity_type', VisaType::class)
            ->where('entity_id', $visa->id)
            ->sole();
        $this->assertSame($adviser->id, $audit->user_id);
        $this->assertSame(1000.0, (float) $audit->changes['professional_fees']['old']);
        $this->assertSame(9999.0, (float) $audit->changes['professional_fees']['new']);
        $this->assertFalse(Gate::forUser($adviser)->allows('approve-advice-bearing'));
    }

    public function test_sales_cannot_edit_catalogue_fees(): void
    {
        $sales = User::factory()->create(['role' => 'sales']);
        $visa = VisaType::create([
            'code' => 'TESTVISA', 'name' => 'Test Visa', 'category' => 'work',
            'consultation_price_nzd' => 250, 'consultation_duration_minutes' => 30,
            'estimated_minutes' => 15, 'icon' => 'Globe', 'active' => true,
            'professional_fees' => 1000,
        ]);

        $this->assertFalse(Gate::forUser($sales)->allows('update', $visa));
        $this->actingAs($sales)
            ->post("/portal/immigration/visa-types/{$visa->id}", [
                'name' => 'Test Visa', 'code' => 'TESTVISA', 'icon' => 'Globe',
                'active' => true, 'consultation_price_nzd' => 250,
                'consultation_duration_minutes' => 30, 'estimated_minutes' => 15,
                'professional_fees' => 9999,
            ])
            ->assertForbidden();

        $this->assertSame(1000.0, (float) $visa->fresh()->professional_fees);
        $this->assertDatabaseMissing('activity_logs', [
            'action' => 'visa_type.updated', 'entity_id' => $visa->id,
        ]);
    }
}
