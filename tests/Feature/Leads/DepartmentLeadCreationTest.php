<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\LeadNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DepartmentLeadCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_immigration_can_create_a_lead_with_optional_notes_blank(): void
    {
        $staff = User::factory()->create(['role' => 'immigration']);
        $this->actingAs($staff)->post('/portal/immigration/leads', [
            'first_name' => 'New', 'last_name' => 'Applicant',
            'pre_screened_by' => '', 'goal_setting_by' => '',
        ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertDatabaseHas('leads', ['first_name' => 'New', 'status' => 'New Leads']);
        $this->assertDatabaseCount('lead_notes', 0);
    }

    public function test_immigration_staff_choices_exclude_client_and_agent_accounts(): void
    {
        $staff = User::factory()->create(['role' => 'immigration']);
        User::factory()->create(['role' => 'super_admin']);
        User::factory()->create(['role' => 'immigration_manager']);
        User::factory()->create(['role' => 'immigration_adviser']);
        User::factory()->create(['role' => 'lead']);
        User::factory()->create(['role' => 'agent']);
        $this->actingAs($staff)->get('/portal/immigration/leads')->assertOk()
            ->assertInertia(fn ($page) => $page->has('staffOptions', 4)
                ->where('staffOptions', fn ($options) => collect($options)->pluck('role')->sort()->values()->all() === ['immigration', 'immigration_adviser', 'immigration_manager', 'super_admin'])
                ->missing('staffOptions.0.email')->has('programs'));
    }

    public function test_other_portal_roles_cannot_create_immigration_leads(): void
    {
        foreach (['lead', 'agent', 'education'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->post('/portal/immigration/leads', ['first_name' => 'Denied'])->assertForbidden();
        }
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_education_can_record_staff_selection_without_optional_note_text(): void
    {
        $staff = User::factory()->create(['role' => 'education']);
        $this->actingAs($staff)->post('/portal/education/leads', [
            'first_name' => 'Education', 'pre_screened_by' => $staff->name,
            'goal_setting_by' => $staff->name,
        ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertDatabaseCount('leads', 1);
        $this->assertSame(['pre_screen', 'goal_setting'], LeadNote::orderBy('id')->pluck('kind')->all());
    }

    public function test_immigration_upload_stays_private_and_other_clients_cannot_download_it(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $staff = User::factory()->create(['role' => 'immigration']);
        $this->actingAs($staff)->post('/portal/immigration/leads', [
            'first_name' => 'Document owner',
            'cv_files' => [UploadedFile::fake()->create('private-cv.pdf', 10, 'application/pdf')],
        ])->assertRedirect()->assertSessionHas('success');

        $doc = LeadDocument::sole();
        Storage::disk('local')->assertExists($doc->file_path);
        Storage::disk('public')->assertMissing($doc->file_path);
        $owner = User::factory()->create(['role' => 'lead', 'lead_id' => $doc->lead_id]);
        $this->actingAs($owner)->get("/portal/lead/documents/{$doc->id}/download")->assertOk();

        $otherLead = Lead::create(['first_name' => 'Other', 'last_name' => 'Client']);
        $other = User::factory()->create(['role' => 'lead', 'lead_id' => $otherLead->id]);
        $this->actingAs($other)->get("/portal/lead/documents/{$doc->id}/download")->assertForbidden();
    }

    public function test_public_registration_accepts_minimum_required_fields(): void
    {
        Bus::fake();
        $this->post('/register', [
            'first_name' => 'Public', 'last_name' => 'Applicant',
            'email' => 'registration@example.test', 'phone' => '123456789',
            'terms_accepted' => true, 'declaration_accepted' => true,
        ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertDatabaseHas('leads', ['email' => 'registration@example.test', 'status' => 'New Leads']);
    }
}
