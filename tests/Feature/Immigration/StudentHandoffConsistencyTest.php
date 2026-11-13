<?php

namespace Tests\Feature\Immigration;

use App\Mail\TemplatedMessage;
use App\Models\EmailAutomationMessage;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    #[DataProvider('entryPoints')]
    public function test_stage_handoff_reuses_one_record_defaults_visa_and_notifies_once(string $entry): void
    {
        $lead = $entry === 'create' ? null : Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Handoff',
            'email' => 'handoff@example.test', 'phone' => '+64000000000', 'is_student' => $entry !== 'link']);
        $payload = ['first_name' => 'Synthetic', 'last_name' => 'Handoff', 'email' => 'handoff@example.test',
            'phone' => '+64000000000', 'program_text' => '', 'education_stage' => 'Endorsed to Immigration'];
        [$url, $data] = match ($entry) {
            'inline' => ["/portal/education/students/{$lead->id}/dashboard-field", ['education_stage' => 'Endorsed to Immigration']],
            'profile' => ["/admin/leads/{$lead->id}/stage", ['field' => 'education_stage', 'status' => 'Endorsed to Immigration']],
            'edit' => ["/portal/education/students/{$lead->id}/update", $payload],
            'link' => ['/portal/education/students/link-existing', $payload + ['lead_id' => $lead->id]],
            'create' => ['/portal/education/students', $payload],
        };
        $this->post($url, $data)->assertRedirect()->assertSessionHasNoErrors()->assertSessionMissing('error');
        $lead = Lead::where('email', 'handoff@example.test')->sole();
        $this->assertTrue($lead->is_student);
        $this->assertTrue($lead->is_immigration_case);
        $this->assertSame('Student Visa', $lead->inz_visa_type);
        $this->assertNotNull($lead->immigration_converted_at);
        $this->assertSame(auth()->id(), $lead->immigration_converted_by);
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
