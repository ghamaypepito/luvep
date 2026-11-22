<?php

namespace Tests\Feature\Education;

use App\Models\{Lead, LeadDocument, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Bus, Http, Mail, Notification, Storage};
use Tests\TestCase;

class DocumentRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Bus::fake(); Mail::fake(); Notification::fake(); Http::preventStrayRequests();
        Storage::fake('local'); Storage::fake('public');
        $this->actingAs(User::factory()->create(['role' => 'education']));
    }

    public function test_folder_search_includes_students_beyond_the_previous_200_folder_limit(): void
    {
        for ($i = 0; $i <= 200; $i++) {
            $lead = Lead::create(['first_name' => sprintf('Applicant%03d', $i), 'last_name' => 'Synthetic']);
            $lead->documents()->create(['original_name' => 'cv.pdf', 'file_path' => 'fixture', 'status' => 'Approved', 'source' => 'upload']);
        }
        $this->get('/portal/education/documents?view=folders')->assertOk()->assertInertia(fn ($page) => $page
            ->has('folders', 40)->where('folderPagination.total', 201)->where('folderPagination.from', 1)->where('folderPagination.to', 40)
            ->where('folderPagination.next', fn ($url) => str_contains($url, 'view=folders') && str_contains($url, 'page=2')));
        $this->get('/portal/education/documents?view=folders&search=Applicant200')->assertOk()->assertInertia(fn ($page) => $page
            ->has('folders', 1)->where('folders.0.id', $lead->id)->where('folderPagination.total', 1));
        $this->get('/portal/education/documents?view=folders&search=applicant200%20synthetic')->assertOk()->assertInertia(fn ($page) => $page->has('folders', 1));
    }

    public function test_folder_status_filters_apply_before_pagination(): void
    {
        foreach (['Approved', 'Submitted', 'Rejected', 'Draft'] as $status) {
            $lead = Lead::create(['first_name' => $status, 'last_name' => 'Synthetic']);
            $lead->documents()->create(['original_name' => 'cv.pdf', 'file_path' => 'fixture', 'status' => $status, 'source' => 'upload']);
        }
        foreach (['Complete' => 1, 'Needs attention' => 2, 'In progress' => 1] as $status => $count) {
            $this->get('/portal/education/documents?'.http_build_query(['folder_status' => $status]))
                ->assertOk()->assertInertia(fn ($page) => $page->has('folders', $count)->where('folderFilters.status', $status));
        }
    }

    public function test_scoped_recovery_handles_public_and_private_intake_files_idempotently(): void
    {
        $lead = Lead::create(['first_name' => 'Legacy', 'last_name' => 'Synthetic', 'education_notes' => [
            'uploaded_files' => ['cv' => ['enrolment-docs/old.pdf'], 'passport' => ['enrolment-docs/new.pdf']],
        ]]);
        Storage::disk('public')->put('enrolment-docs/old.pdf', 'old content');
        Storage::disk('local')->put('enrolment-docs/new.pdf', 'new content');
        $other = Lead::create(['first_name' => 'Unselected', 'last_name' => 'Synthetic', 'education_notes' => [
            'uploaded_files' => ['cv' => ['enrolment-docs/other.pdf']],
        ]]);
        Storage::disk('public')->put('enrolment-docs/other.pdf', 'other content');
        $this->artisan('leads:backfill-enrolment-docs', ['--dry-run' => true, '--lead' => [$lead->id]])
            ->expectsOutputToContain('[dry-run] would create: 2 document row(s) across 1 lead(s).')->assertSuccessful();
        $this->assertDatabaseCount('lead_documents', 0);
        $this->artisan('leads:backfill-enrolment-docs', ['--lead' => [$lead->id]])->assertSuccessful();
        $this->artisan('leads:backfill-enrolment-docs', ['--lead' => [$lead->id]])->assertSuccessful();
        $this->assertSame(['acad.cv', 'pers.passport'], $lead->documents()->orderBy('id')->pluck('checklist_key')->all());
        $this->assertSame(0, $other->documents()->count());
        foreach ($lead->documents as $doc) $this->get('/admin/documents/'.$doc->id.'/download')->assertOk();
        Storage::disk('public')->assertExists('enrolment-docs/old.pdf');
        Storage::disk('local')->assertExists('enrolment-docs/new.pdf');
    }

    public function test_recovery_skips_cross_lead_and_non_intake_paths(): void
    {
        $owner = Lead::create(['first_name' => 'Owner', 'last_name' => 'Synthetic']);
        $owner->documents()->create(['original_name' => 'cv.pdf', 'file_path' => 'enrolment-docs/owner.pdf', 'source' => 'upload']);
        $lead = Lead::create(['first_name' => 'Other', 'last_name' => 'Synthetic', 'education_notes' => [
            'uploaded_files' => ['cv' => ['enrolment-docs/owner.pdf', 'private/other.pdf', 'enrolment-docs/../private/other.pdf']],
        ]]);
        Storage::disk('local')->put('enrolment-docs/owner.pdf', 'private');
        Storage::disk('local')->put('private/other.pdf', 'private');
        $this->artisan('leads:backfill-enrolment-docs', ['--lead' => [$lead->id]])->assertSuccessful();
        $this->assertSame(0, $lead->documents()->count());
        $this->artisan('leads:backfill-enrolment-docs', ['--lead' => ['bad']])->assertExitCode(2);
    }
}
