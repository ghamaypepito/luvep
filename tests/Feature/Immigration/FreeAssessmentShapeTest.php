<?php

namespace Tests\Feature\Immigration;

use App\Models\Lead;
use App\Models\User;
use App\Models\WorkIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

class FreeAssessmentShapeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        Notification::fake();
        $this->actingAs(User::factory()->create(['role' => 'immigration_manager']));
    }

    private function lead(array $data = []): Lead
    {
        return Lead::create(array_merge(['first_name' => 'Synthetic', 'last_name' => 'Assessment', 'source' => 'free-assessment', 'status' => 'Submitted'], $data));
    }

    private function section(Lead $lead, string $title): array
    {
        $response = $this->getJson('/portal/immigration/assessments/free/'.$lead->id.'/data')->assertOk();

        return collect($response->json('sections'))->firstWhere('title', $title)['fields'];
    }

    public function test_imported_work_object_does_not_hide_normal_free_assessments_or_visa_intakes(): void
    {
        $imported = $this->lead(['work_info' => ['current_job' => 'Imported occupation', 'current_location' => 'Imported location']]);
        $normal = $this->lead(['work_info' => [['company_name' => 'Normal employer', 'job_title' => 'Normal title']]]);
        $intake = WorkIntake::create(['intake_id' => 'WI-SYNTHETIC-SHAPE', 'first_name' => 'Normal', 'family_name' => 'Intake', 'email' => 'shape@example.test', 'status' => 'Submitted']);
        $rows = $this->get('/portal/immigration/assessments')->assertOk()->inertiaProps('intakes');
        $this->assertCount(3, $rows);
        $this->assertEqualsCanonicalizing([$imported->id, $normal->id], collect($rows)->where('record', 'lead')->pluck('id')->all());
        $this->assertSame($intake->id, collect($rows)->where('visa_type', 'work')->sole()['id']);
    }

    public function test_imported_work_answers_reach_detail_preview_and_pdf_without_rewriting_storage(): void
    {
        $lead = $this->lead(['work_info' => json_encode(['current_job' => 'Synthetic occupation', 'current_location' => 'Synthetic location'])]);
        $before = $lead->getRawOriginal('work_info');
        $fields = collect($this->section($lead, 'Work Experience'));
        $this->assertSame('Synthetic occupation', $fields->firstWhere('key', 'current_job')['value']);
        $this->assertSame('Synthetic location', $fields->firstWhere('key', 'current_location')['value']);
        $base = '/portal/immigration/intakes/free/'.$lead->id;
        $this->get($base.'/preview')->assertOk()->assertSee('Synthetic occupation')->assertSee('Synthetic location');
        $pdf = $this->get($base.'/pdf')->assertOk();
        $text = (new Parser)->parseContent($pdf->getContent())->getText();
        $this->assertStringContainsString('Synthetic occupation', $text);
        $this->assertStringContainsString('Synthetic location', $text);
        $this->assertSame($before, $lead->fresh()->getRawOriginal('work_info'));
    }

    public function test_keyed_jobs_and_members_are_numbered_and_malformed_members_are_ignored_safely(): void
    {
        $lead = $this->lead([
            'work_info' => ['first-job' => ['company_name' => 'Employer one'], 'broken' => 'not a record', 9 => ['company_name' => 'Employer two']],
            'family_info' => ['members' => ['child-record' => ['first_name' => 'Synthetic', 'family_name' => 'Child'], 'broken' => null, 'unanswered' => [], 'malformed-name' => ['first_name' => ['bad'], 'relation' => ['bad'], 'occupation' => 'Student']]],
        ]);
        $jobs = collect($this->section($lead, 'Work Experience'));
        $this->assertSame('Employer one', $jobs->firstWhere('key', 'work_0_company')['value']);
        $this->assertSame('Employer two', $jobs->firstWhere('key', 'work_1_company')['value']);
        $this->assertSame('Job 2 — Company', $jobs->firstWhere('key', 'work_1_company')['label']);
        $family = collect($this->section($lead, 'Family'));
        $this->assertSame('Synthetic Child', $family->firstWhere('key', 'fam_0_name')['value']);
        $this->assertSame('Member 2 — Name', $family->firstWhere('key', 'fam_1_name')['label']);
        $this->assertSame('Student', $family->firstWhere('key', 'fam_1_occupation')['value']);
        $this->assertCount(10, $family);
    }

    public function test_flat_records_and_import_summary_merged_with_job_lists_keep_their_answers(): void
    {
        $flat = $this->lead(['work_info' => ['company_name' => 'Flat employer', 'job_title' => 'Flat title'], 'family_info' => ['members' => ['first_name' => 'Flat', 'family_name' => 'Member', 'relation' => 'Partner']]]);
        $this->assertSame('Flat employer', collect($this->section($flat, 'Work Experience'))->firstWhere('key', 'work_0_company')['value']);
        $this->assertSame('Flat Member', collect($this->section($flat, 'Family'))->firstWhere('key', 'fam_0_name')['value']);
        $mixed = $this->lead(['work_info' => ['current_job' => 'Imported summary', 'current_location' => 'Imported city', ['company_name' => 'Existing detailed employer']]]);
        $fields = collect($this->section($mixed, 'Work Experience'));
        $this->assertSame('Imported summary', $fields->firstWhere('key', 'current_job')['value']);
        $this->assertSame('Imported city', $fields->firstWhere('key', 'current_location')['value']);
        $this->assertSame('Existing detailed employer', $fields->firstWhere('key', 'work_0_company')['value']);
    }
}
