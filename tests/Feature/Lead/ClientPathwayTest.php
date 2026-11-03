<?php

namespace Tests\Feature\Lead;

use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use App\Notifications\LeadConvertedToDepartment;
use App\Services\EmailAutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ClientPathwayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function client(array $attributes = []): Lead
    {
        return Lead::create(array_merge([
            'first_name' => 'Pathway', 'last_name' => 'Client',
            'email' => 'pathway@example.test', 'phone' => '+640000000',
        ], $attributes));
    }

    public function test_approved_immigration_client_can_start_education_at_proposal_sent_without_losing_data(): void
    {
        $owner = User::factory()->create(['role' => 'immigration']);
        $lead = $this->client([
            'is_immigration_case' => true, 'immigration_stage' => 'Approved Visa',
            'immigration_assignee' => 'Hendry', 'assigned_to' => $owner->id,
            'stage_history' => [['department' => 'immigration', 'stage' => 'Approved Visa', 'at' => '2026-10-01']],
            'passport_number' => 'EXAMPLE123', 'document_checklist' => ['passport' => true],
        ]);
        $portalUser = User::factory()->create(['role' => 'lead', 'lead_id' => $lead->id]);
        $document = LeadDocument::create([
            'lead_id' => $lead->id, 'original_name' => 'passport.pdf',
            'file_path' => 'synthetic/passport.pdf', 'mime' => 'application/pdf',
            'size' => 1, 'status' => 'Approved', 'source' => 'upload',
        ]);
        $before = $lead->fresh()->getAttributes();
        $login = $portalUser->fresh()->getAttributes();
        $this->actingAs($owner)->post("/clients/{$lead->id}/pathways", [
            'pathway' => 'education', 'stage' => 'Proposal Sent', 'note' => 'Client now wants to study.',
        ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');

        $lead->refresh();
        $this->assertTrue($lead->is_student);
        $this->assertSame('Proposal Sent', $lead->education_stage);
        foreach (['email', 'phone', 'passport_number', 'assigned_to', 'immigration_stage', 'immigration_assignee', 'document_checklist'] as $field) {
            $this->assertSame($before[$field], $lead->getAttributes()[$field], $field);
        }
        $this->assertSame($before['id'], $lead->id);
        $this->assertSame($login, $portalUser->fresh()->getAttributes());
        $this->assertSame('Approved', $document->fresh()->status);
        $this->assertSame(1, Lead::count());
        $this->assertSame(1, LeadDocument::count());
        $this->assertSame('Approved Visa', $lead->stage_history[0]['stage']);
        $this->assertSame('Proposal Sent', $lead->stage_history[1]['stage']);
        $this->assertSame($owner->id, $lead->stage_history[1]['by_id']);
        $this->assertSame($owner->id, $lead->student_converted_by);
        $this->assertTrue(Lead::inStudentsRegister()->whereKey($lead->id)->exists());
        $this->assertTrue(Lead::immigrationCase()->whereKey($lead->id)->exists());
        $this->assertFalse(Lead::inLeadPipeline()->whereKey($lead->id)->exists());
        $audit = ActivityLog::where('action', 'client.pathway_added')->sole();
        $this->assertSame('education', $audit->properties['pathway']);
        $this->assertSame('Client now wants to study.', $audit->properties['note']);
        $this->assertDatabaseHas('lead_notes', ['lead_id' => $lead->id, 'user_id' => $owner->id]);
        $this->actingAs(User::factory()->create(['role' => 'education']))
            ->get('/portal/education/students')->assertOk()->assertInertia(fn ($page) => $page
            ->where('students', fn ($students) => collect($students)->contains(
                fn ($student) => $student['id'] === $lead->id && $student['education_stage'] === 'Proposal Sent'
            ))
            );
    }

    public function test_all_destinations_use_their_existing_stages_and_membership(): void
    {
        foreach ([
            ['education', 'is_student', 'education_stage', 'New Lead'],
            ['immigration', 'is_immigration_case', 'immigration_stage', 'For Assessment'],
            ['english', 'is_english_student', 'english_stage', 'PTE Review'],
            ['accommodation', 'is_accommodation_client', null, null],
        ] as [$pathway, $flag, $column, $stage]) {
            $lead = $this->client();
            $this->post("/clients/{$lead->id}/pathways", ['pathway' => $pathway])
                ->assertRedirect()->assertSessionHasNoErrors();
            $this->assertTrue((bool) $lead->fresh()->{$flag});
            if ($column) {
                $this->assertSame($stage, $lead->fresh()->{$column});
            }
        }
    }

    public function test_options_use_server_stage_and_assignee_definitions(): void
    {
        $lead = $this->client();
        $this->getJson("/clients/{$lead->id}/pathways")->assertOk()
            ->assertJsonPath('pathways.0.key', 'education')
            ->assertJsonPath('pathways.0.stages', Lead::EDUCATION_STAGES)
            ->assertJsonPath('pathways.0.default_stage', 'New Lead')
            ->assertJsonPath('pathways.0.assignees', [])
            ->assertJsonPath('pathways.1.assignees', Lead::IMMIGRATION_STAGE_ASSIGNEES)
            ->assertJsonPath('pathways.2.assignees', Lead::ENGLISH_STAGE_ASSIGNEES)
            ->assertJsonPath('pathways.3.stages', []);
    }

    public function test_repeated_add_is_a_noop_including_notifications_and_notes(): void
    {
        $recipient = User::factory()->create(['role' => 'education']);
        $lead = $this->client();
        $this->post("/clients/{$lead->id}/pathways", ['pathway' => 'education', 'stage' => 'Proposal Sent', 'note' => 'Once'])
            ->assertSessionHasNoErrors();
        $before = $lead->fresh()->getAttributes();
        $this->post("/clients/{$lead->id}/pathways", ['pathway' => 'education', 'stage' => 'New Lead', 'note' => 'Twice'])
            ->assertSessionHasNoErrors();
        $this->assertSame($before, $lead->fresh()->getAttributes());
        $this->assertSame(1, ActivityLog::where('action', 'client.pathway_added')->count());
        $this->assertDatabaseCount('lead_notes', 1);
        Notification::assertSentToTimes($recipient, LeadConvertedToDepartment::class, 1);
    }

    public function test_implicit_membership_is_active_and_cannot_be_reset(): void
    {
        foreach ([
            ['immigration', ['is_student' => true, 'education_stage' => 'Endorsed to Immigration', 'immigration_stage' => 'Approved Visa']],
            ['english', ['english_stage' => 'For PTE Exam']],
            ['english', ['stage' => 'English Pro']],
        ] as [$pathway, $attributes]) {
            $lead = $this->client($attributes);
            $before = $lead->fresh()->getAttributes();
            $options = collect($this->getJson("/clients/{$lead->id}/pathways")->assertOk()->json('pathways'));
            $this->assertTrue($options->firstWhere('key', $pathway)['active']);
            $this->post("/clients/{$lead->id}/pathways", ['pathway' => $pathway])->assertSessionHasNoErrors();
            $this->assertSame($before, $lead->fresh()->getAttributes());
        }
        Notification::assertNothingSent();
    }

    public function test_blank_assignee_preserves_destination_assignment_and_global_owner(): void
    {
        $owner = User::factory()->create();
        $lead = $this->client(['assigned_to' => $owner->id, 'immigration_assignee' => 'Tarun']);
        $this->post("/clients/{$lead->id}/pathways", ['pathway' => 'immigration', 'assignee' => ''])
            ->assertSessionHasNoErrors();
        $this->assertSame('Tarun', $lead->fresh()->immigration_assignee);
        $this->assertSame($owner->id, $lead->fresh()->assigned_to);
    }

    public function test_valid_assignee_changes_only_the_destination(): void
    {
        $lead = $this->client(['is_immigration_case' => true, 'immigration_assignee' => 'Hendry', 'immigration_stage' => 'Approved Visa']);
        $this->post("/clients/{$lead->id}/pathways", ['pathway' => 'english', 'assignee' => 'Paula'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Paula', $lead->fresh()->english_assignee);
        $this->assertSame('Hendry', $lead->fresh()->immigration_assignee);
    }

    public function test_invalid_inputs_leave_the_client_unchanged(): void
    {
        $lead = $this->client();
        $before = $lead->fresh()->getAttributes();
        foreach ([
            ['pathway' => 'missing'],
            ['pathway' => 'education', 'stage' => 'Made up'],
            ['pathway' => 'education', 'assignee' => 'Hendry'],
            ['pathway' => 'english', 'assignee' => 'Hendry'],
            ['pathway' => 'accommodation', 'stage' => 'New Lead'],
            ['pathway' => 'education', 'note' => str_repeat('x', 2001)],
        ] as $input) {
            $this->postJson("/clients/{$lead->id}/pathways", $input)->assertUnprocessable();
            $this->assertSame($before, $lead->fresh()->getAttributes());
        }
        Notification::assertNothingSent();
    }

    public function test_unrelated_and_external_roles_cannot_read_or_add_pathways(): void
    {
        $lead = $this->client(['is_immigration_case' => true]);
        foreach (['lead', 'agent', 'finance', 'sales', 'education', 'english', 'accommodation'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->getJson("/clients/{$lead->id}/pathways")->assertForbidden();
            $this->postJson("/clients/{$lead->id}/pathways", ['pathway' => 'education'])->assertForbidden();
        }
        $this->assertFalse($lead->fresh()->is_student);
    }

    public function test_granted_lead_handling_staff_can_add_any_pathway_from_new_leads(): void
    {
        foreach (['education', 'immigration_manager'] as $role) {
            $staff = User::factory()->create([
                'role' => $role, 'module_permissions' => ['client_pathways'],
            ]);
            $before = $staff->fresh()->getAttributes();
            foreach (['education' => 'is_student', 'immigration' => 'is_immigration_case',
                'english' => 'is_english_student', 'accommodation' => 'is_accommodation_client'] as $key => $flag) {
                $lead = $this->client();
                $this->actingAs($staff)->getJson("/clients/{$lead->id}/pathways")
                    ->assertOk()->assertJsonPath('pathways.0.label', 'Student')
                    ->assertJsonCount(4, 'pathways');
                $this->post("/clients/{$lead->id}/pathways", ['pathway' => $key])
                    ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');
                $this->assertTrue((bool) $lead->fresh()->{$flag});
                $this->assertFalse(Lead::inLeadPipeline()->whereKey($lead->id)->exists());
                $this->assertSame(1, ActivityLog::where('action', 'client.pathway_added')
                    ->where('user_id', $staff->id)->where('properties->subject_id', $lead->id)->count());
            }
            $this->assertSame($before, $staff->fresh()->getAttributes());
            $this->assertFalse($staff->canAccessPortal($role === 'education' ? 'immigration' : 'education'));
        }
    }

    public function test_new_lead_pathway_access_requires_an_explicit_grant(): void
    {
        $lead = $this->client();
        foreach (['education', 'immigration_manager'] as $role) {
            $staff = User::factory()->create(['role' => $role]);
            $this->actingAs($staff)->getJson("/clients/{$lead->id}/pathways")->assertForbidden();
            $this->postJson("/clients/{$lead->id}/pathways", ['pathway' => 'education'])->assertForbidden();
        }
        $this->assertFalse($lead->fresh()->is_student);
        Notification::assertNothingSent();
    }

    public function test_pathway_grant_does_not_open_other_departments_or_implicit_memberships(): void
    {
        foreach ([['education', ['is_immigration_case' => true]],
            ['education', ['english_stage' => 'DIY Review']],
            ['immigration_manager', ['is_student' => true]],
            ['immigration_manager', ['stage' => 'English Pro']]] as [$role, $attributes]) {
            $lead = $this->client($attributes);
            $before = $lead->fresh()->getAttributes();
            $staff = User::factory()->create(['role' => $role, 'module_permissions' => ['client_pathways']]);
            $this->actingAs($staff)->getJson("/clients/{$lead->id}/pathways")->assertForbidden();
            $this->postJson("/clients/{$lead->id}/pathways", ['pathway' => 'accommodation'])->assertForbidden();
            $this->assertSame($before, $lead->fresh()->getAttributes());
        }
    }

    public function test_inactive_or_external_accounts_cannot_use_a_pathway_grant(): void
    {
        $lead = $this->client();
        foreach (['lead', 'revoked_lead', 'agent', 'sub_agent', 'finance'] as $role) {
            $staff = User::factory()->create([
                'role' => $role, 'module_permissions' => ['client_pathways'], 'portal_access' => ['education'],
            ]);
            $this->actingAs($staff)->getJson("/clients/{$lead->id}/pathways")->assertForbidden();
            $this->postJson("/clients/{$lead->id}/pathways", ['pathway' => 'education'])->assertForbidden();
        }
        $staff = User::factory()->create(['role' => 'education', 'is_active' => false, 'module_permissions' => ['client_pathways']]);
        $this->assertFalse(app(\App\Services\ClientPathways::class)->canManage($staff, $lead));
        $this->assertFalse(app(\App\Services\ClientPathways::class)->canManage($staff, $this->client(['is_student' => true])));
        Notification::assertNothingSent();
    }

    public function test_each_source_department_can_add_a_pathway_for_its_own_client(): void
    {
        foreach ([
            ['sales', []], ['education', ['is_student' => true]],
            ['english', ['is_english_student' => true]], ['accommodation', ['is_accommodation_client' => true]],
            ['immigration', ['is_immigration_case' => true]],
            ['immigration_manager', ['is_immigration_case' => true]],
            ['immigration_adviser', ['is_immigration_case' => true]],
        ] as [$role, $attributes]) {
            $lead = $this->client($attributes);
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->getJson("/clients/{$lead->id}/pathways")->assertOk();
            $this->post("/clients/{$lead->id}/pathways", ['pathway' => 'education'])
                ->assertRedirect()->assertSessionHasNoErrors();
        }
    }

    public function test_implicitly_active_source_department_has_access_but_unrelated_sales_does_not(): void
    {
        $lead = $this->client(['is_student' => true, 'education_stage' => 'Endorsed to Immigration']);
        $this->actingAs(User::factory()->create(['role' => 'immigration']))
            ->getJson("/clients/{$lead->id}/pathways")->assertOk();
        $english = $this->client(['english_stage' => 'DIY Review']);
        $this->actingAs(User::factory()->create(['role' => 'english']))
            ->getJson("/clients/{$english->id}/pathways")->assertOk();
        $this->actingAs(User::factory()->create(['role' => 'sales']))
            ->getJson("/clients/{$english->id}/pathways")->assertForbidden();
    }

    public function test_destination_links_do_not_cross_into_forbidden_portals(): void
    {
        $lead = $this->client(['is_immigration_case' => true, 'is_student' => true]);
        $this->actingAs(User::factory()->create(['role' => 'immigration']));
        $options = collect($this->getJson("/clients/{$lead->id}/pathways")->assertOk()->json('pathways'));
        $this->assertNull($options->firstWhere('key', 'education')['open_url']);
        $this->assertSame("/portal/immigration/cases/{$lead->id}/profile", $options->firstWhere('key', 'immigration')['open_url']);
    }

    public function test_admin_can_open_the_existing_immigration_pathway_using_a_real_registered_route(): void
    {
        $lead = $this->client(['is_immigration_case' => true, 'immigration_stage' => 'Approved Visa']);
        $options = collect($this->getJson("/clients/{$lead->id}/pathways")->assertOk()->json('pathways'));
        $this->get($options->firstWhere('key', 'immigration')['open_url'])->assertOk();
    }

    public function test_unauthenticated_and_deleted_records_are_rejected(): void
    {
        $lead = $this->client();
        $lead->delete();
        $this->getJson("/clients/{$lead->id}/pathways")->assertNotFound();
        $this->postJson("/clients/{$lead->id}/pathways", ['pathway' => 'education'])->assertNotFound();
        auth()->logout();
        $this->getJson('/clients/123/pathways')->assertUnauthorized();
        $this->postJson('/clients/123/pathways', ['pathway' => 'education'])->assertUnauthorized();
    }

    public function test_a_failed_audit_rolls_back_membership_history_and_handoff_note(): void
    {
        $lead = $this->client();
        $before = $lead->fresh()->getAttributes();
        Event::listen('eloquent.creating: '.ActivityLog::class, function (ActivityLog $log) {
            if ($log->action === 'client.pathway_added') {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->post("/clients/{$lead->id}/pathways", ['pathway' => 'education', 'note' => 'Must roll back']);
            $this->fail('The synthetic audit failure must abort the transaction.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure', $exception->getMessage());
        }
        $this->assertSame($before, $lead->fresh()->getAttributes());
        $this->assertDatabaseCount('lead_notes', 0);
        Notification::assertNothingSent();
    }

    public function test_immigration_conversion_reuses_configured_automation_once_and_does_not_infer_a_visa_event(): void
    {
        $this->mock(EmailAutomationService::class)->shouldReceive('fire')->once()
            ->with('immigration.education.handoff', \Mockery::type(Lead::class), ['visa_type' => ''])
            ->andReturn(false);
        $lead = $this->client(['is_student' => true, 'education_stage' => 'Proposal Sent']);
        foreach ([1, 2] as $attempt) {
            $this->post("/clients/{$lead->id}/pathways", ['pathway' => 'immigration', 'stage' => 'Visa Lodged'])
                ->assertSessionHasNoErrors();
        }
        $this->assertNull($lead->fresh()->inz_lodged_at);
        $this->assertSame('Proposal Sent', $lead->fresh()->education_stage);
        $this->assertSame(1, ActivityLog::where('action', 'client.pathway_added')->count());
        $this->assertDatabaseCount('message_logs', 0);
    }
}
