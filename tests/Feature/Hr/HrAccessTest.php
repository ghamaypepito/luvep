<?php

namespace Tests\Feature\Hr;

use App\Models\DtrSetting;
use App\Models\HrAuditEvent;
use App\Models\HrEmployee;
use App\Models\HrEvent;
use App\Models\User;
use App\Services\Hr\HrAccess;
use App\Services\Hr\HrAudit;
use App\Services\Hr\HrEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HrAccessTest extends TestCase
{
    use RefreshDatabase;

    private function employee(array $attributes = []): HrEmployee
    {
        $user = User::factory()->create(['role' => 'sales']);

        return HrEmployee::create(['user_id' => $user->id, 'status' => 'active', ...$attributes]);
    }

    public function test_active_employee_can_read_own_profile_but_not_another(): void
    {
        $own = $this->employee();
        $other = $this->employee();
        $access = app(HrAccess::class);
        $this->assertTrue($access->allows($own->user, 'profile.view', $own));
        $this->assertFalse($access->allows($own->user, 'profile.view', $other));
    }

    public function test_external_accounts_cannot_be_enrolled_even_with_grants(): void
    {
        foreach (['agent', 'sub_agent', 'lead', 'revoked_lead'] as $role) {
            $user = User::factory()->create(['role' => $role, 'module_permissions' => ['hr.enroll']]);
            try {
                HrEmployee::create(['user_id' => $user->id, 'status' => 'active']);
                $this->fail('External role was enrolled: '.$role);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('user_id', $exception->errors());
            }
            $this->assertNull(app(HrAccess::class)->employee($user));
        }
    }

    public function test_inactive_archived_and_candidate_staff_have_no_employee_access(): void
    {
        $employee = $this->employee();
        $access = app(HrAccess::class);
        foreach (['candidate', 'archived'] as $status) {
            $employee->update(['status' => $status]);
            $this->assertNull($access->employee($employee->user));
            $this->assertFalse($access->allows($employee->user, 'profile.view', $employee));
        }
        $employee->update(['status' => 'active']);
        $employee->user->update(['is_active' => false]);
        $this->assertNull($access->employee($employee->user));
        $employee->user->update(['is_active' => true, 'role' => 'agent']);
        $this->assertNull($access->employee($employee->user));
    }

    public function test_legacy_dtr_archive_revokes_employee_access(): void
    {
        $employee = $this->employee();
        DtrSetting::create(['user_id' => $employee->user_id, 'archived_at' => now()]);
        $this->assertNull(app(HrAccess::class)->employee($employee->user));
    }

    public function test_admin_or_parent_module_grant_does_not_unlock_sensitive_records(): void
    {
        $employee = $this->employee();
        $admin = User::factory()->create(['role' => 'admin', 'module_permissions' => ['hr']]);
        $access = app(HrAccess::class);
        $this->assertFalse($access->allows($admin, 'documents.medical', $employee));
        $this->assertFalse($access->allows($admin, 'documents.compensation', $employee));
        $admin->update(['module_permissions' => ['hr.documents.medical']]);
        $this->assertTrue($access->allows($admin, 'documents.medical', $employee));
        $this->assertFalse($access->allows($admin, 'documents.compensation', $employee));
        $this->assertFalse($access->allows($admin, 'unknown.ability', $employee));
    }

    public function test_manager_only_decides_assigned_reports_and_never_self(): void
    {
        $head = $this->employee();
        $report = $this->employee(['manager_user_id' => $head->user_id]);
        $other = $this->employee();
        $access = app(HrAccess::class);
        $this->assertTrue($access->allows($head->user, 'leave.review', $report));
        $this->assertFalse($access->allows($head->user, 'leave.review', $other));
        $this->assertFalse($access->allows($head->user, 'dtr.review', $report));
        $head->user->update(['role' => 'super_admin']);
        $this->assertFalse($access->allows($head->user, 'leave.review', $head));
    }

    public function test_emergency_contact_is_encrypted_and_not_implicitly_serialized(): void
    {
        $employee = $this->employee(['emergency_contact' => 'Private contact 555-0100']);
        $raw = DB::table('hr_employees')->where('id', $employee->id)->value('emergency_contact');
        $this->assertStringNotContainsString('555-0100', $raw);
        $this->assertSame('Private contact 555-0100', $employee->fresh()->emergency_contact);
        $this->assertArrayNotHasKey('emergency_contact', $employee->toArray());
    }

    public function test_event_retries_have_one_stable_record_and_audit_omits_secrets(): void
    {
        $employee = $this->employee();
        $events = app(HrEvents::class);
        $a = $events->record('profile.updated', $employee, $employee->user, 'profile:1:1');
        $b = $events->record('profile.updated', $employee, $employee->user, 'profile:1:1');
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, HrEvent::count());
        app(HrAudit::class)->record($employee->user, 'profile.updated', $employee, ['fields' => ['phone'], 'reason' => 'private medical reason']);
        $this->assertStringNotContainsString('private medical reason', HrAuditEvent::first()->toJson());
    }
}
