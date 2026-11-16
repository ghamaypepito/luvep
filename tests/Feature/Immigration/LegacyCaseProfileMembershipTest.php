<?php

namespace Tests\Feature\Immigration;

use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LegacyCaseProfileMembershipTest extends TestCase
{
    use RefreshDatabase;

    private function legacy(string $stage = 'Endorsed to Immigration'): Lead
    {
        return Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Legacy', 'is_student' => true,
            'education_stage' => $stage, 'is_immigration_case' => false, 'inz_visa_type' => 'Student Visa']);
    }

    public function test_case_list_legacy_member_opens_its_documents_without_rewriting_conversion_flags(): void
    {
        $lead = $this->legacy();
        $this->actingAs(User::factory()->create(['role' => 'immigration']));
        $this->get('/portal/immigration/cases')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('cases.0.id', $lead->id));
        $this->get('/portal/immigration/cases/'.$lead->id.'/profile?tab=documents')->assertOk();
        $this->assertFalse((bool) $lead->fresh()->is_immigration_case);
        $this->assertNull($lead->fresh()->immigration_converted_at);
    }

    public function test_each_existing_legacy_case_stage_can_open_under_the_existing_staff_roles(): void
    {
        $leads = collect(Lead::EDUCATION_STAGES_IMMIGRATION)->map(fn ($stage) => $this->legacy($stage));
        foreach (['immigration', 'immigration_manager', 'immigration_adviser', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            foreach ($leads as $lead) {
                $this->get('/portal/immigration/cases/'.$lead->id.'/profile?tab=documents')->assertOk();
            }
        }
    }

    public function test_existing_upload_and_private_download_work_after_opening_a_legacy_case(): void
    {
        Storage::fake('local');
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $lead = $this->legacy();
        $staff = User::factory()->create(['role' => 'immigration']);
        $this->actingAs($staff)->get('/portal/immigration/cases/'.$lead->id.'/profile?tab=documents')->assertOk();
        $this->post('/admin/leads/'.$lead->id.'/documents/checklist/passport/upload', [
            'files' => [UploadedFile::fake()->create('synthetic-passport.pdf', 20, 'application/pdf')],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $document = LeadDocument::where('lead_id', $lead->id)->sole();
        $this->assertSame('passport', $document->checklist_key);
        $this->assertSame($staff->id, $document->uploaded_by);
        Storage::disk('local')->assertExists($document->file_path);
        $this->get('/portal/immigration/cases/'.$lead->id.'/profile?tab=documents')
            ->assertInertia(fn (Assert $p) => $p->has('documents', 1)->where('documents.0.id', $document->id));
        $url = '/admin/documents/'.$document->id.'/download';
        $this->get($url)->assertOk()->assertDownload($document->original_name);
        $other = Lead::create(['first_name' => 'Unrelated', 'last_name' => 'Client']);
        $this->actingAs(User::factory()->create(['role' => 'lead', 'lead_id' => $other->id]))->get($url)->assertForbidden();
        $this->assertFalse((bool) $lead->fresh()->is_immigration_case);
        $this->assertNull($lead->fresh()->immigration_converted_at);
    }

    public function test_plain_or_early_education_records_and_unrelated_roles_remain_denied(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'immigration']));
        foreach ([null, 'New Lead', 'School Enrolment'] as $stage) {
            $lead = Lead::create(['first_name' => 'Not', 'last_name' => 'A Case', 'is_student' => true,
                'education_stage' => $stage, 'is_immigration_case' => false]);
            $this->get('/portal/immigration/cases/'.$lead->id.'/profile')->assertNotFound();
        }
        $case = $this->legacy();
        foreach (['sales', 'education', 'finance', 'lead'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get('/portal/immigration/cases/'.$case->id.'/profile?tab=documents')->assertForbidden();
        }
    }
}
