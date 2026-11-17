<?php

namespace Tests\Feature\Immigration;

use App\Mail\TemplatedMessage;
use App\Models\EmailAutomationMessage;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StudentHandoffConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        foreach (['educ_to_migration' => 'client', 'educ_to_migration_internal' => 'team'] as $key => $recipient) {
            MessageTemplate::create(['key' => $key, 'name' => $key, 'department' => 'immigration',
                'channels' => ['email'], 'email_subject' => $key, 'email_body' => 'Synthetic handoff', 'is_active' => true]);
            EmailAutomationMessage::create(['event_key' => 'immigration.education.handoff',
                'recipient' => $recipient, 'template_key' => $key, 'channel' => 'email', 'enabled' => true]);
        }
        User::factory()->create(['role' => 'immigration_manager', 'email' => 'handoff-manager@example.test']);
        $this->actingAs(User::factory()->create(['role' => 'education']));
    }

    public static function entryPoints(): array
    {
        return [['inline'], ['profile'], ['edit'], ['link'], ['create']];
    }

    public static function conversionEntryPoints(): array
    {
        return [...self::entryPoints(), ['convert']];
    }

    private function handoffRequest(string $entry, ?Lead $lead): array
    {
        $payload = ['first_name' => 'Synthetic', 'last_name' => 'Handoff', 'email' => 'handoff@example.test',
            'phone' => '+64000000000', 'education_stage' => 'Endorsed to Immigration'];

        return match ($entry) {
            'inline' => ["/portal/education/students/{$lead->id}/dashboard-field", ['education_stage' => 'Endorsed to Immigration']],
            'profile' => ["/admin/leads/{$lead->id}/stage", ['field' => 'education_stage', 'status' => 'Endorsed to Immigration']],
            'edit' => ["/portal/education/students/{$lead->id}/update", $payload],
            'link' => ['/portal/education/students/link-existing', $payload + ['lead_id' => $lead->id, 'program_text' => 'Free-form program']],
            'create' => ['/portal/education/students', $payload],
            'convert' => ["/admin/leads/{$lead->id}/convert-to-case", []],
        };
    }

    #[DataProvider('entryPoints')]
    public function test_stage_handoff_reuses_one_record_defaults_visa_and_notifies_once(string $entry): void
    {
        $lead = $entry === 'create' ? null : Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Handoff',
            'email' => 'handoff@example.test', 'phone' => '+64000000000', 'is_student' => $entry !== 'link']);
        [$url, $data] = $this->handoffRequest($entry, $lead);
        $this->post($url, $data)->assertRedirect()->assertSessionHasNoErrors()->assertSessionMissing('error');
        $lead = Lead::where('email', 'handoff@example.test')->sole();
        $this->assertTrue($lead->is_student);
        $this->assertTrue($lead->is_immigration_case);
        $this->assertSame('Student Visa', $lead->inz_visa_type);
        $this->assertNotNull($lead->immigration_converted_at);
        $this->assertSame(auth()->id(), $lead->immigration_converted_by);
        if ($entry === 'link') {
            $this->assertSame('Free-form program', $lead->studyPlans()->sole()->preferred_course);
        }
        $converted = $lead->immigration_converted_at->toIso8601String();
        Mail::assertQueued(TemplatedMessage::class, 2);
        $this->get('/portal/education/students')->assertInertia(fn (Assert $page) => $page
            ->where('students', fn ($rows) => collect($rows)->contains(fn ($row) => $row['id'] === $lead->id && $row['is_student'] && $row['is_immigration_case'])));
        $this->actingAs(User::where('role', 'immigration_manager')->first())->get('/portal/immigration/cases')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('cases', fn ($rows) => collect($rows)->contains(fn ($row) => $row['id'] === $lead->id)));
        $this->actingAs(User::where('role', 'education')->first());
        foreach (['School Enrolment', 'Endorsed to Immigration', 'Endorsed to Immigration'] as $stage) {
            $this->post("/admin/leads/{$lead->id}/stage", ['field' => 'education_stage', 'status' => $stage])
                ->assertSessionHasNoErrors()->assertSessionMissing('error');
        }
        $this->assertSame($converted, $lead->refresh()->immigration_converted_at->toIso8601String());
        $this->assertTrue($lead->is_student);
        $this->assertTrue($lead->is_immigration_case);
        $this->assertDatabaseCount('leads', 1);
        Mail::assertQueued(TemplatedMessage::class, 2);
    }

    public function test_profile_handoff_keeps_an_existing_visa_and_parallel_stage_without_repeat_notifications(): void
    {
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Visa', 'email' => 'visa@example.test',
            'is_student' => true, 'inz_visa_type' => 'Partner Visa', 'immigration_stage' => 'For Assessment',
            'english_stage' => 'For PTE Exam']);
        for ($i = 0; $i < 2; $i++) {
            $this->post("/admin/leads/{$lead->id}/stage", ['field' => 'education_stage', 'status' => 'Endorsed to Immigration'])
                ->assertSessionHasNoErrors();
        }
        $this->assertSame('Partner Visa', $lead->refresh()->inz_visa_type);
        $this->assertSame('For Assessment', $lead->immigration_stage);
        $this->assertSame('For PTE Exam', $lead->english_stage);
        Mail::assertQueued(TemplatedMessage::class, 2);
    }

    public function test_sales_cannot_handoff_another_departments_student_from_profile_or_register(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'sales']));
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Handoff',
            'email' => 'handoff@example.test', 'is_student' => true]);
        foreach (['profile', 'inline', 'edit'] as $entry) {
            [$url, $data] = $this->handoffRequest($entry, $lead);
            $this->post(str_replace('/portal/education/', '/portal/sales/', $url), $data)->assertForbidden();
        }
        $this->assertFalse($lead->refresh()->is_immigration_case);
        $this->assertNull($lead->education_stage);
        $this->assertNull($lead->immigration_converted_at);
        Mail::assertNothingOutgoing();
    }

    public function test_an_external_portal_grant_cannot_create_or_link_a_student_and_send_handoff_mail(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'agent', 'portal_access' => ['education']]));
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Handoff',
            'email' => 'handoff@example.test']);
        foreach (['create', 'link'] as $entry) {
            [$url, $data] = $this->handoffRequest($entry, $lead);
            $this->post($url, $data)->assertForbidden();
        }
        $this->assertFalse($lead->refresh()->is_student);
        $this->assertFalse($lead->is_immigration_case);
        $this->assertDatabaseCount('leads', 1);
        Mail::assertNothingOutgoing();
    }

    #[DataProvider('conversionEntryPoints')]
    public function test_rolled_back_handoff_never_queues_a_notice(string $entry): void
    {
        $lead = $entry === 'create' ? null : Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Handoff',
            'email' => 'handoff@example.test', 'is_student' => $entry !== 'link']);
        [$url, $data] = $this->handoffRequest($entry, $lead);

        DB::beginTransaction();
        try {
            $this->post($url, $data)->assertRedirect()->assertSessionHasNoErrors()->assertSessionMissing('error');
            $this->assertTrue(Lead::where('email', 'handoff@example.test')->sole()->is_immigration_case);
        } finally {
            DB::rollBack();
        }

        $this->assertDatabaseCount('leads', $lead === null ? 0 : 1);
        $this->assertFalse(Lead::where('is_immigration_case', true)->exists());
        Mail::assertNothingOutgoing();
    }

    public function test_unrelated_updates_do_not_implicitly_convert_a_legacy_handoff_record(): void
    {
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Legacy', 'email' => 'legacy@example.test',
            'phone' => '+64000000000', 'is_student' => true, 'education_stage' => 'Endorsed to Immigration']);
        $this->post("/admin/leads/{$lead->id}/stage", ['field' => 'status', 'status' => Lead::STAGES[0]])->assertSessionHasNoErrors();
        $this->post("/portal/education/students/{$lead->id}/update", [
            'first_name' => 'Synthetic', 'last_name' => 'Legacy', 'email' => 'legacy@example.test',
            'phone' => '+64000000000', 'program_text' => '', 'internal_note' => 'An ordinary edit',
        ])->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->assertFalse($lead->refresh()->is_immigration_case);
        $this->assertNull($lead->inz_visa_type);
        Mail::assertNothingOutgoing();
    }

    public function test_already_converted_profile_can_fill_a_blank_visa_without_resetting_or_renotifying(): void
    {
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Converted', 'is_student' => true,
            'is_immigration_case' => true, 'immigration_converted_at' => '2026-10-01 00:00:00',
            'immigration_converted_by' => auth()->id(), 'immigration_stage' => 'For Assessment']);
        $at = $lead->immigration_converted_at->toIso8601String();
        $this->post("/admin/leads/{$lead->id}/stage", ['field' => 'education_stage', 'status' => 'Endorsed to Immigration'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Student Visa', $lead->refresh()->inz_visa_type);
        $this->assertSame($at, $lead->immigration_converted_at->toIso8601String());
        $this->assertSame('For Assessment', $lead->immigration_stage);
        Mail::assertNothingOutgoing();
    }
}
