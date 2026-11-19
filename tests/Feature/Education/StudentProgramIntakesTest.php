<?php

namespace Tests\Feature\Education;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class StudentProgramIntakesTest extends TestCase
{
    use RefreshDatabase;

    private const A = 'English for Academic Purposes';

    private const B = 'Master of Education';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => 'education']));
        Mail::fake();
        Notification::fake();
        Bus::fake();
        Http::preventStrayRequests();
    }

    private function payload(array $extra = []): array
    {
        return array_merge(['first_name' => 'Synthetic', 'last_name' => 'Student', 'email' => 'programs@example.test',
            'phone' => '+64000000000', 'education_stage' => 'School Enrolment',
            'program_text' => self::A.' · '.self::B, 'program_schools' => ['School A', 'School B'],
            'program_intakes' => [['program' => self::A, 'intake' => '2027-02-17'], ['program' => self::B, 'intake' => '2027-07-12']],
            'intake' => '2027-02-17'], $extra);
    }

    private function existing(?array $intakes = null, string $intake = '2027-02-17'): Lead
    {
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Student', 'email' => 'programs@example.test',
            'phone' => '+64000000000', 'is_student' => true, 'education_stage' => 'School Enrolment',
            'education_notes' => ['keep' => 'original', 'actual_course_started_on' => '2026-09-03']]);
        $lead->studyPlans()->create(['qualification_level' => '', 'preferred_course' => self::A.' · '.self::B,
            'preferred_intake' => $intake, 'program_schools' => ['School A', 'School B'], 'program_intakes' => $intakes]);

        return $lead;
    }

    public function test_create_and_listing_round_trip_distinct_program_dates(): void
    {
        $this->post('/portal/education/students', $this->payload())->assertRedirect()->assertSessionHasNoErrors()->assertSessionMissing('error');
        $lead = Lead::where('email', 'programs@example.test')->sole();
        $plan = $lead->studyPlans()->sole();
        $this->assertSame($this->payload()['program_intakes'], $plan->program_intakes);
        $this->assertSame('2027-02-17', $plan->preferred_intake);
        $this->assertSame(['School A', 'School B'], $plan->program_schools);
        $this->get('/portal/education/students')->assertOk()->assertInertia(fn ($page) => $page
            ->where('students.0.program_intakes', $this->payload()['program_intakes'])
            ->where('students.0.intake', '2027-02-17'));
    }

    public function test_edit_secondary_date_clear_and_reorder_keep_program_associations_and_other_data(): void
    {
        $lead = $this->existing($this->payload()['program_intakes']);
        $second = $lead->studyPlans()->create(['qualification_level' => '', 'preferred_course' => 'Untouched separate plan', 'preferred_intake' => '2028-01-01']);
        $url = '/portal/education/students/'.$lead->id.'/update';
        $entries = [['program' => self::A, 'intake' => '2027-02-17'], ['program' => self::B, 'intake' => '2027-08-01']];
        $this->post($url, $this->payload(['program_intakes' => $entries]))->assertSessionHasNoErrors()->assertSessionMissing('error');
        $plan = $lead->studyPlans()->first();
        $this->assertSame($entries, $plan->program_intakes);
        $this->assertSame('2027-02-17', $plan->preferred_intake);
        $this->assertSame(['keep' => 'original', 'actual_course_started_on' => '2026-09-03'], $lead->fresh()->education_notes);
        $this->assertSame('2028-01-01', $second->fresh()->preferred_intake);
        $reversed = array_reverse($entries);
        $this->post($url, $this->payload(['program_text' => self::B.' · '.self::A, 'program_schools' => ['School B', 'School A'], 'program_intakes' => $reversed]))->assertSessionHasNoErrors();
        $this->assertSame($reversed, $plan->fresh()->program_intakes);
        $this->assertSame('2027-08-01', $plan->fresh()->preferred_intake);
        $reversed[0]['intake'] = null;
        $this->post($url, $this->payload(['program_text' => self::B.' · '.self::A, 'program_intakes' => $reversed]))->assertSessionHasNoErrors();
        $this->assertNull($plan->fresh()->preferred_intake);
        $this->assertSame('2027-02-17', $plan->fresh()->program_intakes[1]['intake']);
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_legacy_requests_preserve_other_dates_and_do_not_copy_a_shared_intake_to_second_program(): void
    {
        $lead = $this->existing($this->payload()['program_intakes']);
        $payload = $this->payload(['intake' => '2027-03-01']);
        unset($payload['program_intakes']);
        $this->post('/portal/education/students/'.$lead->id.'/update', $payload)->assertSessionHasNoErrors();
        $plan = $lead->studyPlans()->first();
        $this->assertSame('2027-03-01', $plan->preferred_intake);
        $this->assertSame('2027-03-01', $plan->program_intakes[0]['intake']);
        $this->assertSame('2027-07-12', $plan->program_intakes[1]['intake']);
        unset($payload['intake'], $payload['program_text']);
        $this->post('/portal/education/students/'.$lead->id.'/update', $payload)->assertSessionHasNoErrors();
        $this->assertSame($plan->program_intakes, $plan->fresh()->program_intakes);
    }

    public function test_original_undated_or_free_text_intake_is_preserved_without_inventing_dates(): void
    {
        $lead = $this->existing(null, 'November 2027');
        $rows = [['program' => self::A, 'intake' => 'November 2027'], ['program' => self::B, 'intake' => '2028-02-01']];
        $this->post('/portal/education/students/'.$lead->id.'/update', $this->payload(['program_intakes' => $rows]))->assertSessionHasNoErrors()->assertSessionMissing('error');
        $plan = $lead->studyPlans()->first();
        $this->assertSame('November 2027', $plan->preferred_intake);
        $this->assertSame($rows, $plan->program_intakes);
        $rows[0]['intake'] = '2027-11-15';
        $rows[1]['intake'] = null;
        $this->post('/portal/education/students/'.$lead->id.'/update', $this->payload(['program_intakes' => $rows]))->assertSessionHasNoErrors();
        $this->assertSame('2027-11-15', $plan->fresh()->preferred_intake);
        $this->assertNull($plan->fresh()->program_intakes[1]['intake']);
    }

    public function test_invalid_dates_wrong_programs_duplicates_and_incomplete_lists_rollback_all_writes(): void
    {
        $lead = $this->existing($this->payload()['program_intakes']);
        $before = $lead->fresh()->getAttributes();
        $plan = $lead->studyPlans()->first();
        $planBefore = $plan->getAttributes();
        $good = $this->payload()['program_intakes'];
        $invalid = [
            [['program' => self::A, 'intake' => '2027-02-30'], $good[1]],
            [['program' => self::A, 'intake' => 'November 2027'], $good[1]],
            [['program' => 'Different program', 'intake' => '2027-02-17'], $good[1]],
            [$good[0], $good[0]], [$good[0]],
        ];
        foreach ($invalid as $rows) {
            $this->postJson('/portal/education/students/'.$lead->id.'/update', $this->payload(['first_name' => 'Must not save', 'program_intakes' => $rows]))->assertUnprocessable();
            $this->assertSame($before, $lead->fresh()->getAttributes());
            $this->assertSame($planBefore, $plan->fresh()->getAttributes());
        }
        $this->postJson('/portal/education/students', $this->payload(['email' => 'invalid@example.test', 'program_intakes' => $invalid[0]]))->assertUnprocessable();
        $this->assertDatabaseMissing('leads', ['email' => 'invalid@example.test']);
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_link_existing_person_saves_both_dates_and_keeps_legacy_context(): void
    {
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Student', 'email' => 'programs@example.test',
            'phone' => '+64000000000', 'education_notes' => ['keep' => 'original']]);
        $this->post('/portal/education/students/link-existing', $this->payload(['lead_id' => $lead->id]))->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->assertSame($this->payload()['program_intakes'], $lead->studyPlans()->sole()->program_intakes);
        $this->assertSame(['keep' => 'original'], $lead->fresh()->education_notes);
        $this->assertDatabaseCount('leads', 1);
    }

    public function test_ungranted_roles_cannot_save_new_program_dates(): void
    {
        $lead = $this->existing();
        foreach (['lead', 'agent', 'finance'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->postJson('/portal/education/students/'.$lead->id.'/update', $this->payload())->assertForbidden();
        }
        $this->assertNull($lead->studyPlans()->first()->program_intakes);
    }
}
