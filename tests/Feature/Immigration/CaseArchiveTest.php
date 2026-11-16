<?php

namespace Tests\Feature\Immigration;

use App\Models\CaseDependent;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CaseArchiveTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/portal/immigration/cases';

    private function case(array $attributes = []): Lead
    {
        return Lead::create($attributes + [
            'first_name' => 'Sample', 'last_name' => 'Applicant', 'email' => 'case@example.test',
            'is_immigration_case' => true, 'immigration_stage' => 'For Assessment',
        ]);
    }

    public function test_archive_uses_the_existing_trash_and_only_lists_archived_cases(): void
    {
        $case = $this->case(['lead_id' => 'IC-ARCHIVE-TEST']);
        $other = $this->case(['lead_id' => 'IC-ACTIVE-TEST']);
        $ordinary = $this->case(['is_immigration_case' => false]);
        $ordinary->delete();
        $document = $case->documents()->create(['original_name' => 'offer.pdf', 'file_path' => 'private/offer.pdf', 'status' => LeadDocument::STATUS_APPROVED]);

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->post(self::BASE."/{$case->id}/archive")->assertSessionHasNoErrors();
        $this->assertSoftDeleted($case);
        $this->get(self::BASE)->assertInertia(fn (Assert $page) => $page
            ->where('canManageCaseArchive', true)->has('cases', 1)->where('cases.0.id', $other->id));
        $this->get(self::BASE.'/archived?search=IC-ARCHIVE')
            ->assertInertia(fn (Assert $page) => $page->component('portal/immigration/ArchivedCases')
                ->has('cases.data', 1)->where('cases.data.0.id', $case->id));
        $this->get(self::BASE."/{$other->id}/profile")->assertInertia(fn (Assert $page) => $page
            ->where('caseOptions', fn ($options) => ! collect($options)->contains('id', $case->id)));
        $this->postJson(self::BASE."/{$other->id}/dependents", ['relationship' => 'child', 'linked_lead_id' => $case->id])
            ->assertUnprocessable()->assertJsonValidationErrors('linked_lead_id');
        $this->post(self::BASE."/archived/{$case->id}/restore")->assertSessionHasNoErrors();
        $this->assertNotSoftDeleted($case);
        $this->assertModelExists($document);
    }

    public function test_staff_cannot_access_archive_or_change_existing_names(): void
    {
        $case = $this->case(['first_name' => 'Two Names']);
        $archived = $this->case();
        $archived->delete();
        $dependant = CaseDependent::create(['lead_id' => $case->id, 'relationship' => 'child', 'first_name' => 'Existing', 'family_name' => 'Name', 'source' => 'staff']);
        $this->actingAs(User::factory()->create(['role' => 'immigration_manager']));
        $this->get(self::BASE.'/archived')->assertForbidden();
        $this->post(self::BASE."/{$case->id}/archive")->assertForbidden();
        $this->patch(self::BASE."/archived/{$archived->id}/name")->assertForbidden();
        $this->post(self::BASE."/archived/{$archived->id}/restore")->assertForbidden();
        $this->delete(self::BASE."/archived/{$archived->id}", ['confirmation' => 'DELETE ACCOUNT'])->assertForbidden();
        $this->postJson(self::BASE."/{$case->id}", ['first_name' => 'Modified', 'last_name' => $case->last_name])
            ->assertUnprocessable()->assertJsonValidationErrors('first_name');
        $this->postJson(self::BASE."/{$case->id}/personal", ['first_name' => $case->first_name, 'last_name' => 'Modified', 'email' => $case->email])
            ->assertUnprocessable()->assertJsonValidationErrors('last_name');
        $this->postJson("/admin/leads/{$case->id}/personal", ['first_name' => 'Modified'])
            ->assertUnprocessable()->assertJsonValidationErrors('first_name');
        $this->putJson(self::BASE."/{$case->id}/dependents/{$dependant->id}", ['relationship' => 'child', 'family_name' => 'Modified'])
            ->assertUnprocessable()->assertJsonValidationErrors('family_name');
        $this->post(self::BASE."/{$case->id}/personal", ['first_name' => $case->first_name, 'last_name' => $case->last_name, 'email' => 'contact@example.test'])
            ->assertSessionHasNoErrors();
        $this->assertSame('contact@example.test', $case->fresh()->email);

        $case->update(['is_student' => true]);
        $education = User::factory()->create(['role' => 'education']);
        $this->actingAs($education)->get('/portal/education/students')
            ->assertInertia(fn (Assert $page) => $page->where('students.0.first_name', 'Two Names')->where('students.0.case_name_locked', true));
        $contact = ['last_name' => $case->last_name, 'email' => $case->fresh()->email, 'phone' => '+64210000000'];
        $this->postJson("/portal/education/students/{$case->id}/update", ['first_name' => 'Modified'] + $contact)
            ->assertUnprocessable()->assertJsonValidationErrors('first_name');
        $this->post("/portal/education/students/{$case->id}/update", ['first_name' => 'Two Names', 'payment' => 'Paid'] + $contact)
            ->assertSessionHasNoErrors();
        $this->assertSame('Paid', $case->fresh()->student_payment);
    }

    public function test_permanent_delete_requires_archived_empty_case_and_exact_confirmation(): void
    {
        $empty = $this->case(['lead_id' => 'IC-DELETE-TEST']);
        $withDocument = $this->case();
        $linked = $this->case();
        $active = $this->case();
        CaseDependent::create(['lead_id' => $active->id, 'linked_lead_id' => $linked->id, 'relationship' => 'child', 'source' => 'staff']);
        $document = $withDocument->documents()->create(['original_name' => 'keep.pdf', 'file_path' => 'private/keep.pdf', 'status' => LeadDocument::STATUS_APPROVED]);
        foreach ([$empty, $withDocument, $linked] as $archived) {
            $archived->delete();
        }

        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->deleteJson(self::BASE."/archived/{$empty->id}", ['confirmation' => 'delete account'])
            ->assertUnprocessable()->assertJsonValidationErrors('confirmation');
        foreach ([$withDocument, $linked] as $protected) {
            $this->deleteJson(self::BASE."/archived/{$protected->id}", ['confirmation' => 'DELETE ACCOUNT'])
                ->assertUnprocessable()->assertJsonValidationErrors('confirmation');
            $this->assertSoftDeleted($protected);
        }
        $this->deleteJson(self::BASE."/archived/{$active->id}", ['confirmation' => 'DELETE ACCOUNT'])->assertNotFound();
        $this->patch(self::BASE."/archived/{$empty->id}/name", [
            'first_name' => 'Corrected', 'middle_name' => null, 'last_name' => 'Applicant', 'suffix' => null,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('leads', ['id' => $empty->id, 'first_name' => 'Corrected']);
        $this->delete(self::BASE."/archived/{$empty->id}", ['confirmation' => 'DELETE ACCOUNT'])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('leads', ['id' => $empty->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'immigration.case_permanently_deleted', 'user_id' => $admin->id]);
        $this->assertModelExists($document);
    }
}
