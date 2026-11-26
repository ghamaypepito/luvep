<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AgentAgreement;
use App\Models\Lead;
use App\Models\LeadTask;
use App\Models\MessageLog;
use App\Models\PartnerActivityFile;
use App\Models\PartnerActivityRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PartnerActivityTrackerTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/portal/education/partner-tracker';

    private function setupTracker(): array
    {
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 11, 24)->setTime(12, 0));
        Storage::fake('local');
        Mail::fake();
        Notification::fake();
        $staff = User::factory()->create(['role' => 'education', 'module_permissions' => ['agents']]);
        $agent = User::factory()->create(['role' => 'agent', 'name' => 'Synthetic Partner']);
        $this->actingAs($staff);

        return [$staff, $agent];
    }

    private function payload(User $agent, array $changes = []): array
    {
        return array_replace_recursive(['source_key' => 'manual:'.Str::uuid(), 'version' => '', 'agent_id' => $agent->id,
            'category' => 'email', 'title' => 'Synthetic subject', 'activity_on' => '2026-11-20',
            'status' => 'awaiting_response', 'assignee_id' => auth()->id(), 'follow_up_on' => '2026-11-23', 'lead_id' => null,
            'details' => ['purpose' => 'Synthetic partnership follow-up', 'remarks' => 'Test only']], $changes);
    }

    public function test_partner_mail_does_not_pollute_event_or_case_history_with_colliding_identity(): void
    {
        [, $agent] = $this->setupTracker();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $event = \App\Models\Event::create(['name' => 'Synthetic Fair', 'type' => 'Fair', 'status' => 'upcoming',
            'mode' => 'in-person', 'event_code' => 'synthetic-partner-history', 'date_from' => '2026-12-01']);
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Client', 'email' => $agent->email,
            'event_id' => $event->id, 'is_immigration_case' => true]);
        foreach ([null, 'compose', 'partner-tracker'] as $source) {
            MessageLog::create(['channel' => 'email', 'source' => $source,
                'recipient_type' => $source === 'partner-tracker' ? 'user' : 'lead',
                'recipient_id' => $lead->id, 'recipient_address' => $agent->email,
                'subject' => $source ?? 'Legacy message', 'body' => 'Synthetic body', 'status' => 'sent']);
        }
        $this->get('/admin/events/'.$event->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('sentEmails', 2)->where('sentEmails', fn ($rows) => collect($rows)->pluck('subject')->sort()->values()->all() === ['Legacy message', 'compose']));
        $this->get('/portal/immigration/cases/'.$lead->id.'/profile')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('communications', 2)->where('communications', fn ($rows) => collect($rows)->pluck('subject')->sort()->values()->all() === ['Legacy message', 'compose']));
    }

    private function agreement(User $agent, array $changes = []): AgentAgreement
    {
        return AgentAgreement::create($changes + ['agent_id' => $agent->id, 'version' => 1, 'is_draft' => false,
            'file_path' => 'agreements/synthetic.pdf', 'original_name' => 'synthetic-agreement.pdf', 'generated_by' => auth()->id()]);
    }

    public function test_manual_categories_assignments_dates_statuses_and_stale_retries_preserve_accounts(): void
    {
        [$staff, $agent] = $this->setupTracker();
        $account = $agent->fresh()->getRawOriginal();
        foreach (['email', 'agreement', 'training', 'follow_up'] as $category) {
            $data = $this->payload($agent, ['category' => $category,
                'status' => ['email' => 'awaiting_response', 'agreement' => 'under_review', 'training' => 'completed', 'follow_up' => 'completed'][$category],
                'details' => ['attendance' => 'attended', 'facilitator' => 'Synthetic Facilitator', 'client_name' => 'Synthetic Prospect', 'method' => 'Phone', 'outcome' => 'Provided information']]);
            $this->post(self::BASE.'/activities', $data)->assertRedirect()->assertSessionHasNoErrors();
            $record = PartnerActivityRecord::where('source_key', $data['source_key'])->firstOrFail();
            $this->post(self::BASE.'/activities', $data)->assertSessionHasErrors('version');
            $data['version'] = $record->version();
            $data['details']['remarks'] = 'Updated tracking note';
            $this->post(self::BASE.'/activities', $data)->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame('Updated tracking note', $record->fresh()->details['remarks']);
        }
        $this->assertSame(4, PartnerActivityRecord::count());
        $this->assertSame($account, $agent->fresh()->getRawOriginal());
        $this->get(self::BASE.'?from=2026-11-01&to=2026-11-24')->assertInertia(fn (Assert $p) => $p
            ->has('rows', 4)->where('summary.agents_trained', 1)->where('summary.follow_ups_completed', 1));
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public function test_latest_issued_agreement_counts_do_not_double_count_drafts_or_rewrite_source(): void
    {
        [, $agent] = $this->setupTracker();
        $old = $this->agreement($agent, ['version' => 1, 'sent_at' => now()->subMonths(2), 'agent_signed_at' => now()->subMonths(2), 'company_signed_at' => now()->subMonths(2), 'expires_on' => '2026-10-01']);
        $current = $this->agreement($agent, ['version' => 2, 'agent_signed_at' => now()->subDays(10), 'company_signed_at' => now()->subDays(9), 'expires_on' => '2026-12-10']);
        $this->agreement($agent, ['version' => 3, 'is_draft' => true]);
        $before = $current->fresh()->getRawOriginal();
        $this->get(self::BASE)->assertInertia(fn (Assert $p) => $p->has('agreements', 3)
            ->where('summary.agreements_signed', 1)->where('summary.renewals_due', 1)->where('summary.agreements_pending', 0));
        $this->post(self::BASE.'/activities', $this->payload($agent, ['source_key' => 'agreement:'.$current->id, 'category' => 'agreement',
            'status' => 'expired', 'title' => 'Forged contract title', 'details' => ['expiry_on' => '2099-01-01', 'remarks' => 'Tracking note']]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($before, $current->fresh()->getRawOriginal());
        $this->get(self::BASE)->assertInertia(fn (Assert $p) => $p->where('summary.agreements_signed', 1));
    }

    public function test_automatic_audit_events_use_their_actual_week_and_reads_do_not_duplicate_records(): void
    {
        [, $agent] = $this->setupTracker();
        $agreement = $this->agreement($agent);
        foreach (['2026-11-02', '2026-11-23'] as $date) {
            $log = ActivityLog::create(['action' => 'affiliate.agreement.details_saved', 'user_id' => auth()->id(), 'properties' => ['agreement_id' => $agreement->id]]);
            $log->forceFill(['created_at' => $date.' 10:00:00'])->saveQuietly();
        }
        for ($i = 0; $i < 2; $i++) {
            $this->get(self::BASE.'?from=2026-11-23&to=2026-11-24')->assertInertia(fn (Assert $p) => $p
                ->has('rows', 1)->where('rows.0.activity_on', '2026-11-23')->where('rows.0.details.event', 'affiliate.agreement.details_saved'));
        }
        $this->assertSame(0, PartnerActivityRecord::count());
        Mail::assertNothingOutgoing();
    }

    public function test_old_overdue_workload_is_not_hidden_by_activity_date_filters_and_absence_is_not_training(): void
    {
        [, $agent] = $this->setupTracker();
        $this->post(self::BASE.'/activities', $this->payload($agent, ['category' => 'follow_up', 'status' => 'pending',
            'activity_on' => '2026-10-01', 'follow_up_on' => '2026-10-10', 'details' => ['client_name' => 'Synthetic Prospect', 'method' => 'Call']]))->assertSessionHasNoErrors();
        $this->post(self::BASE.'/activities', $this->payload($agent, ['category' => 'training', 'status' => 'completed',
            'details' => ['attendance' => 'absent', 'facilitator' => 'Synthetic Trainer']]))->assertSessionHasNoErrors();
        $this->get(self::BASE.'?from=2026-11-01&to=2026-11-24')->assertInertia(fn (Assert $p) => $p
            ->has('rows', 1)->has('workload', 1)->where('summary.follow_ups_overdue', 1)->where('summary.follow_ups_pending', 1)->where('summary.agents_trained', 0));
    }

    public function test_task_logging_preserves_original_affiliate_and_department_scope_without_mutating_task(): void
    {
        [, $original] = $this->setupTracker();
        $assigned = User::factory()->create(['role' => 'agent']);
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Student', 'is_student' => true, 'agent_id' => $assigned->id, 'original_affiliate_id' => $original->id]);
        $private = Lead::create(['first_name' => 'Private', 'last_name' => 'Case', 'is_immigration_case' => true, 'agent_id' => $original->id]);
        $task = LeadTask::create(['lead_id' => $lead->id, 'title' => 'Student follow-up', 'type' => 'follow_up', 'assignee_id' => auth()->id(), 'created_by' => auth()->id(), 'completed' => true, 'completion_notes' => 'Synthetic completion']);
        LeadTask::create(['lead_id' => $private->id, 'title' => 'Unrelated private case', 'type' => 'follow_up', 'created_by' => auth()->id()]);
        $before = $task->fresh()->getRawOriginal();
        $this->get(self::BASE)->assertInertia(fn (Assert $p) => $p->has('rows', 1)->where('rows.0.agent_id', $original->id)->where('rows.0.status', 'completed'));
        $this->post(self::BASE.'/activities', $this->payload($original, ['source_key' => 'task:'.$task->id, 'category' => 'follow_up', 'status' => 'pending']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($before, $task->fresh()->getRawOriginal());
    }

    public function test_client_scope_is_checked_for_new_existing_changed_and_file_access(): void
    {
        [$staff, $agent] = $this->setupTracker();
        $private = Lead::create(['first_name' => 'Private', 'last_name' => 'Client', 'agent_id' => $agent->id, 'is_immigration_case' => true]);
        $data = $this->payload($agent, ['lead_id' => $private->id]);
        $this->post(self::BASE.'/activities', $data)->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(self::BASE.'/activities', $data)->assertRedirect()->assertSessionHasNoErrors();
        $record = PartnerActivityRecord::firstOrFail();
        $this->post(self::BASE.'/activities/'.$record->id.'/files', ['file' => UploadedFile::fake()->create('private.pdf', 2, 'application/pdf')])->assertRedirect();
        $file = PartnerActivityFile::firstOrFail();
        $this->actingAs($staff)->get(self::BASE.'/files/'.$file->id)->assertNotFound();
        $data['lead_id'] = null;
        $data['version'] = $record->version();
        $this->post(self::BASE.'/activities', $data)->assertForbidden();
        $this->get(self::BASE)->assertInertia(fn (Assert $p) => $p->has('rows', 0));
    }

    public function test_access_requires_existing_module_permission_and_denies_external_or_unrelated_roles(): void
    {
        [, $agent] = $this->setupTracker();
        foreach (['education', 'agent', 'lead', 'finance', 'immigration_manager'] as $role) {
            $user = User::factory()->create(['role' => $role, 'module_permissions' => $role === 'education' ? [] : ['agents'], 'portal_access' => ['education']]);
            $this->actingAs($user)->get(self::BASE)->assertForbidden();
            $this->post(self::BASE.'/activities', $this->payload($agent))->assertForbidden();
            $this->get(self::BASE.'/export?format=xlsx')->assertForbidden();
        }
    }

    public function test_private_attachments_download_and_failed_audit_cleanup_are_atomic(): void
    {
        [, $agent] = $this->setupTracker();
        $this->post(self::BASE.'/activities', $this->payload($agent))->assertSessionHasNoErrors();
        $record = PartnerActivityRecord::firstOrFail();
        $this->post(self::BASE.'/activities/'.$record->id.'/files', ['file' => UploadedFile::fake()->create('signed.pdf', 2, 'application/pdf')])->assertRedirect()->assertSessionHasNoErrors();
        $file = PartnerActivityFile::firstOrFail();
        $this->get(self::BASE.'/files/'.$file->id)->assertDownload('signed.pdf');
        $paths = Storage::disk('local')->allFiles();
        ActivityLog::creating(function ($event) {
            if ($event->action === 'partner_activity.file_uploaded') {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        $this->post(self::BASE.'/activities/'.$record->id.'/files', ['file' => UploadedFile::fake()->create('second.pdf', 2, 'application/pdf')])->assertServerError();
        $this->assertSame(1, PartnerActivityFile::count());
        $this->assertSame($paths, Storage::disk('local')->allFiles());
        ActivityLog::flushEventListeners();
    }

    public function test_validation_rejects_bad_status_wrong_assignment_and_future_completed_work(): void
    {
        [, $agent] = $this->setupTracker();
        $this->post(self::BASE.'/activities', $this->payload($agent, ['status' => 'signed']))->assertSessionHasErrors('status');
        $this->post(self::BASE.'/activities', $this->payload($agent, ['assignee_id' => $agent->id]))->assertSessionHasErrors('assignee_id');
        $this->post(self::BASE.'/activities', $this->payload($agent, ['activity_on' => '2026-12-01']))->assertSessionHasErrors('activity_on');
        $this->getJson(self::BASE.'?from=2026-12-01&to=2026-11-01')->assertUnprocessable();
        $this->assertSame(0, PartnerActivityRecord::count());
    }

    public function test_email_compose_uses_explicit_partner_id_and_only_actual_sent_mail_is_tracked(): void
    {
        [$staff, $agent] = $this->setupTracker();
        $this->get('/portal/education/compose?partner_id='.$agent->id)->assertInertia(fn (Assert $p) => $p->where('partnerRecipient.id', $agent->id));
        $this->post('/portal/education/compose/send', ['partner_id' => $agent->id, 'emails' => [$agent->email], 'subject' => 'Synthetic email', 'body' => 'Test only'])->assertRedirect()->assertSessionHasNoErrors();
        $log = MessageLog::latest('id')->firstOrFail();
        $this->assertSame('user', $log->recipient_type);
        $this->assertSame($agent->id, $log->recipient_id);
        $this->assertSame('queued', $log->status);
        $this->get(self::BASE)->assertInertia(fn (Assert $p) => $p->has('rows', 0));
        $log->update(['status' => 'sent', 'sent_at' => now()]);
        $this->get(self::BASE)->assertInertia(fn (Assert $p) => $p->has('rows', 1)->where('rows.0.source_key', 'message:'.$log->id));
        $this->post('/portal/education/compose/send', ['partner_id' => $agent->id, 'emails' => ['different@example.test'], 'subject' => 'Wrong', 'body' => 'Test'])->assertSessionHasErrors('emails');
        $this->assertSame(1, MessageLog::count());
        $other = User::factory()->create(['role' => 'education', 'module_permissions' => ['agents']]);
        $this->actingAs($other)->get(self::BASE)->assertInertia(fn (Assert $p) => $p->has('rows', 1));
    }

    public function test_excel_and_pdf_exports_contain_scoped_data_without_formula_execution(): void
    {
        [, $agent] = $this->setupTracker();
        $this->post(self::BASE.'/activities', $this->payload($agent, ['title' => '=HYPERLINK("https://example.test")']))->assertSessionHasNoErrors();
        $excel = $this->get(self::BASE.'/export?format=xlsx')->assertOk()->assertDownload('partner-activities.xlsx');
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($excel->baseResponse->getFile()->getPathname()) === true);
        $sheet = $zip->getFromName('xl/worksheets/sheet2.xml');
        $this->assertStringContainsString('t="inlineStr"', $sheet);
        $this->assertStringContainsString('=HYPERLINK', $sheet);
        $this->assertStringNotContainsString('<f>', $sheet);
        $zip->close();
        $pdf = $this->get(self::BASE.'/export?format=pdf')->assertOk()->assertDownload('partner-activities.pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        if ($directory = getenv('PARTNER_REPORT_QA_DIR')) {
            copy($excel->baseResponse->getFile()->getPathname(), $directory.'/partner-report.xlsx');
            file_put_contents($directory.'/partner-report.pdf', $pdf->getContent());
        }
    }

    public function test_pre_migration_reads_work_but_new_writes_are_unavailable(): void
    {
        [, $agent] = $this->setupTracker();
        Schema::drop('partner_activity_files');
        Schema::drop('partner_activity_records');
        $this->get(self::BASE)->assertOk()->assertInertia(fn (Assert $p) => $p->where('installed', false));
        $this->post(self::BASE.'/activities', $this->payload($agent))->assertStatus(503);
    }

    public function test_staff_filter_does_not_promote_a_filtered_old_agreement_to_current(): void
    {
        [$staff, $agent] = $this->setupTracker();
        $other = User::factory()->create(['role' => 'education', 'module_permissions' => ['agents']]);
        $this->agreement($agent, ['version' => 1, 'generated_by' => $staff->id, 'agent_signed_at' => now(), 'company_signed_at' => now(), 'expires_on' => '2026-12-01']);
        $this->agreement($agent, ['version' => 2, 'generated_by' => $other->id, 'sent_at' => now()]);
        $this->get(self::BASE.'?assignee_id='.$staff->id)->assertInertia(fn (Assert $p) => $p
            ->where('summary.agreements_signed', 0)->where('summary.renewals_due', 0)->has('agreements', 1));
        $this->get(self::BASE.'?assignee_id='.$other->id)->assertInertia(fn (Assert $p) => $p->where('summary.agreements_pending', 1));
    }

    public function test_unlinked_email_addresses_and_unrelated_user_messages_are_not_guessed_into_tracker(): void
    {
        [$staff, $agent] = $this->setupTracker();
        foreach (['compose', 'unrelated-private-source'] as $source) {
            MessageLog::create(['source' => $source, 'channel' => 'email', 'recipient_type' => 'user', 'recipient_id' => $agent->id,
                'recipient_address' => $agent->email, 'subject' => 'Not a tracker communication', 'body' => 'Private', 'status' => 'sent', 'sent_at' => now(), 'triggered_by_user_id' => $staff->id]);
        }
        MessageLog::create(['source' => 'compose', 'channel' => 'email', 'recipient_type' => 'raw', 'recipient_address' => $agent->email,
            'subject' => 'Unattributed typed address', 'body' => 'Private', 'status' => 'sent', 'sent_at' => now(), 'triggered_by_user_id' => $staff->id]);
        $this->get(self::BASE)->assertInertia(fn (Assert $p) => $p->has('rows', 0));
        $this->actingAs($agent)->post('/portal/agent/compose/send', ['partner_id' => $agent->id, 'emails' => [$agent->email], 'body' => 'Forbidden'])->assertForbidden();
        Mail::assertNothingOutgoing();
    }

    public function test_preview_is_denied_and_a_failed_save_audit_rolls_back_activity(): void
    {
        [$staff, $agent] = $this->setupTracker();
        $request = \Illuminate\Http\Request::create('/synthetic');
        $request->setUserResolver(fn () => $staff);
        $request->attributes->set('user_view_actor', User::factory()->create(['role' => 'super_admin']));
        try {
            app(\App\Services\PartnerActivityTracker::class)->authorize($request);
            $this->fail('View-as must be denied.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        ActivityLog::creating(function ($event) {
            if ($event->action === 'partner_activity.saved') {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        $this->post(self::BASE.'/activities', $this->payload($agent))->assertServerError();
        $this->assertSame(0, PartnerActivityRecord::count());
        ActivityLog::flushEventListeners();
    }

    public function test_week_month_and_inclusive_date_boundaries_use_the_stated_reporting_timezone(): void
    {
        [, $agent] = $this->setupTracker();
        foreach (['2026-11-22', '2026-11-23', '2026-11-24'] as $date) {
            $this->post(self::BASE.'/activities', $this->payload($agent, ['activity_on' => $date]))->assertSessionHasNoErrors();
        }
        $this->get(self::BASE.'?period=week')->assertInertia(fn (Assert $p) => $p
            ->where('filters.from', '2026-11-23')->where('filters.to', '2026-11-24')->has('rows', 2)->where('timezone', config('app.timezone')));
        $this->get(self::BASE.'?period=month')->assertInertia(fn (Assert $p) => $p->where('filters.from', '2026-11-01')->has('rows', 3));
    }

    public function test_superseded_agreement_deadline_does_not_create_a_false_overdue_alert(): void
    {
        [, $agent] = $this->setupTracker();
        $old = $this->agreement($agent, ['version' => 1, 'sent_at' => now()->subDays(5)]);
        $this->post(self::BASE.'/activities', $this->payload($agent, ['source_key' => 'agreement:'.$old->id, 'category' => 'agreement', 'status' => 'sent']))->assertSessionHasNoErrors();
        $this->agreement($agent, ['version' => 2, 'agent_signed_at' => now(), 'company_signed_at' => now()]);
        $this->get(self::BASE)->assertInertia(fn (Assert $p) => $p->has('workload', 0)->where('summary.agreements_signed', 1)->where('summary.agreements_pending', 0));
    }

    public function test_client_picker_respects_original_referral_and_confidential_department_scope(): void
    {
        [, $original] = $this->setupTracker();
        $assigned = User::factory()->create(['role' => 'agent']);
        $student = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Referral', 'is_student' => true, 'original_affiliate_id' => $original->id, 'agent_id' => $assigned->id]);
        Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Private Case', 'is_immigration_case' => true, 'agent_id' => $original->id]);
        $this->getJson(self::BASE.'/clients?agent_id='.$original->id.'&q=Synthetic%20Referral')->assertOk()->assertJsonCount(1, 'clients')->assertJsonPath('clients.0.id', $student->id);
        $this->getJson(self::BASE.'/clients?agent_id='.$assigned->id)->assertOk()->assertJsonCount(0, 'clients');
        $this->getJson(self::BASE.'/clients?agent_id='.$original->id)->assertOk()->assertJsonCount(1, 'clients');
    }

    public function test_rollback_and_reapply_preserve_recorded_history_and_attachments(): void
    {
        [, $agent] = $this->setupTracker();
        $this->post(self::BASE.'/activities', $this->payload($agent))->assertSessionHasNoErrors();
        $record = PartnerActivityRecord::firstOrFail();
        $this->post(self::BASE.'/activities/'.$record->id.'/files', ['file' => UploadedFile::fake()->create('retained.pdf', 2, 'application/pdf')])->assertSessionHasNoErrors();
        $migration = require database_path('migrations/2026_11_24_040000_create_partner_activity_records.php');
        $migration->down();
        $migration->up();
        $this->assertSame(1, PartnerActivityRecord::count());
        $this->assertSame(1, PartnerActivityFile::count());
        $this->get(self::BASE.'/files/'.PartnerActivityFile::firstOrFail()->id)->assertDownload('retained.pdf');
    }

    public function test_linked_client_name_comes_from_authorized_record_and_cannot_be_retargeted(): void
    {
        [, $agent] = $this->setupTracker();
        $lead = Lead::create(['first_name' => 'Verified', 'last_name' => 'Student', 'is_student' => true, 'agent_id' => $agent->id]);
        $other = Lead::create(['first_name' => 'Other', 'last_name' => 'Student', 'is_student' => true, 'agent_id' => $agent->id]);
        $data = $this->payload($agent, ['category' => 'follow_up', 'status' => 'pending', 'lead_id' => $lead->id,
            'details' => ['client_name' => 'Incorrect typed name', 'method' => 'Call']]);
        $this->post(self::BASE.'/activities', $data)->assertSessionHasNoErrors();
        $record = PartnerActivityRecord::firstOrFail();
        $this->assertSame('Verified Student', $record->details['client_name']);
        $data['version'] = $record->version();
        $data['lead_id'] = $other->id;
        $this->post(self::BASE.'/activities', $data)->assertSessionHasErrors('lead_id');
        $this->assertSame($lead->id, $record->fresh()->lead_id);
    }

    public function test_shared_student_case_does_not_expose_unassigned_immigration_tasks(): void
    {
        [$staff, $agent] = $this->setupTracker();
        $lia = User::factory()->create(['role' => 'immigration_manager']);
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Shared Client', 'is_student' => true, 'is_immigration_case' => true, 'agent_id' => $agent->id]);
        LeadTask::create(['lead_id' => $lead->id, 'title' => 'Confidential other-department work', 'type' => 'follow_up', 'department' => 'immigration', 'created_by' => $lia->id, 'assignee_id' => $lia->id]);
        LeadTask::create(['lead_id' => $lead->id, 'title' => 'Explicitly shared follow-up', 'type' => 'follow_up', 'department' => 'immigration', 'created_by' => $lia->id, 'assignee_id' => $lia->id, 'additional_assignee_ids' => [$staff->id]]);
        $this->get(self::BASE)->assertInertia(fn (Assert $p) => $p->has('rows', 1)->where('rows.0.title', 'Explicitly shared follow-up'));
        $this->get(self::BASE.'?assignee_id='.$staff->id)->assertInertia(fn (Assert $p) => $p
            ->has('rows', 1)->where('rows.0.title', 'Explicitly shared follow-up')
            ->where('rows.0.staff', $lia->name.', '.$staff->name));
    }

    public function test_agreement_link_is_only_exposed_when_the_private_pdf_exists(): void
    {
        [, $agent] = $this->setupTracker();
        $agreement = $this->agreement($agent);
        $this->get(self::BASE)->assertInertia(fn (Assert $p) => $p->where('agreements.0.details.document_url', null));
        Storage::disk('local')->put($agreement->file_path, '%PDF-synthetic');
        $this->get(self::BASE)->assertInertia(fn (Assert $p) => $p->where('agreements.0.details.document_url', self::BASE.'/agreements/'.$agreement->id.'/document'));
        $this->get(self::BASE.'/agreements/'.$agreement->id.'/document')->assertDownload('synthetic-agreement.pdf');
        $this->actingAs($agent)->get(self::BASE.'/agreements/'.$agreement->id.'/document')->assertForbidden();
    }

    public function test_shared_compose_history_keeps_partner_mail_inside_the_tracker_audience(): void
    {
        [$staff, $agent] = $this->setupTracker();
        foreach (['compose', 'partner-tracker'] as $source) {
            MessageLog::create(['channel' => 'email', 'source' => $source, 'recipient_type' => 'user', 'recipient_id' => $agent->id,
                'recipient_address' => $agent->email, 'subject' => $source.' subject', 'body' => 'Synthetic body',
                'status' => 'sent', 'sent_at' => now(), 'triggered_by_user_id' => $staff->id]);
        }
        $finance = User::factory()->create(['role' => 'finance', 'module_permissions' => ['agents']]);
        $education = User::factory()->create(['role' => 'education', 'module_permissions' => []]);
        foreach ([[$finance, 'finance'], [$education, 'education']] as [$actor, $portal]) {
            $this->actingAs($actor)->get('/portal/'.$portal.'/compose')->assertOk()->assertInertia(fn (Assert $p) => $p
                ->has('sent', 1)->where('sent.0.subject', 'compose subject'));
        }
        $this->actingAs($staff)->get('/portal/education/compose')->assertInertia(fn (Assert $p) => $p->has('sent', 2));
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/admin/email/compose')->assertInertia(fn (Assert $p) => $p->has('sent', 2));
    }
}
