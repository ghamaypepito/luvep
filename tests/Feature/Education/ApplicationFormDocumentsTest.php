<?php

namespace Tests\Feature\Education;

use App\Http\Controllers\LeadTrackingController;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApplicationFormDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Mail::fake();
        Notification::fake();
        Bus::fake();
        Http::preventStrayRequests();
    }

    public function test_education_can_append_application_forms_and_retrieve_them_without_rewriting_existing_documents(): void
    {
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Student', 'is_student' => true]);
        $staff = User::factory()->create(['role' => 'education']);
        $old = LeadDocument::create(['lead_id' => $lead->id, 'checklist_key' => 'acad.transcript', 'original_name' => 'transcript.pdf', 'file_path' => 'old.pdf', 'source' => 'upload', 'status' => 'Approved', 'note' => 'Keep original review']);
        Storage::disk('local')->put('old.pdf', 'Original bytes');
        $before = $old->fresh()->getRawOriginal();
        $tracker = app(LeadTrackingController::class);
        $requiredBefore = collect($tracker->buildTrackerPayload($lead, $lead->tracking_code)['visa']['checklist'])->where('required', true)->pluck('key')->all();
        $this->actingAs($staff);
        for ($i = 0; $i < 2; $i++) {
            $this->post("/admin/leads/{$lead->id}/documents/checklist/acad.application_forms/upload", [
                'files' => [UploadedFile::fake()->createWithContent('application-form.pdf', "%PDF-1.4\nSynthetic application")],
            ])->assertRedirect()->assertSessionHasNoErrors();
        }
        $forms = $lead->documents()->where('checklist_key', 'acad.application_forms')->get();
        $this->assertCount(2, $forms);
        $this->assertCount(2, $forms->pluck('file_path')->unique());
        foreach ($forms as $form) {
            $this->assertSame('Submitted', $form->status);
            $this->assertSame($staff->id, $form->uploaded_by);
            Storage::disk('local')->assertExists($form->file_path);
            $this->get('/admin/documents/'.$form->id.'/download')->assertDownload('application-form.pdf');
        }
        $this->get('/portal/education/leads/'.$lead->id.'?tab=documents')
            ->assertOk()->assertInertia(fn ($page) => $page->where('checklistFiles', fn ($groups) => count(collect($groups)->get('acad.application_forms', [])) === 2 && count(collect($groups)->get('acad.transcript', [])) === 1)
            );
        $this->assertSame($before, $old->fresh()->getRawOriginal());
        $this->assertSame('Original bytes', Storage::disk('local')->get('old.pdf'));
        $this->assertSame($requiredBefore, collect($tracker->buildTrackerPayload($lead->fresh(), $lead->tracking_code)['visa']['checklist'])->where('required', true)->pluck('key')->all());
        $this->assertDatabaseCount('lead_document_requests', 0);
        Mail::assertNothingOutgoing();
    }

    public function test_application_form_download_preserves_client_ownership_and_does_not_move_unclassified_files(): void
    {
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Student', 'is_student' => true]);
        $historical = LeadDocument::create(['lead_id' => $lead->id, 'original_name' => 'old-application.pdf', 'file_path' => 'historical.pdf', 'source' => 'upload', 'status' => 'Submitted']);
        $form = LeadDocument::create(['lead_id' => $lead->id, 'checklist_key' => 'acad.application_forms', 'original_name' => 'form.pdf', 'file_path' => 'form.pdf', 'source' => 'upload', 'status' => 'Submitted']);
        Storage::disk('local')->put('form.pdf', "%PDF-1.4\nSynthetic");
        $owner = User::factory()->create(['role' => 'lead', 'lead_id' => $lead->id]);
        $this->actingAs($owner)->get('/portal/lead/documents/'.$form->id.'/download')->assertDownload('form.pdf');
        $other = Lead::create(['first_name' => 'Other', 'last_name' => 'Student']);
        $this->actingAs(User::factory()->create(['role' => 'lead', 'lead_id' => $other->id]))
            ->get('/portal/lead/documents/'.$form->id.'/download')->assertForbidden();
        $this->assertNull($historical->fresh()->checklist_key);
        $this->assertSame(2, $lead->documents()->count());
    }
}
