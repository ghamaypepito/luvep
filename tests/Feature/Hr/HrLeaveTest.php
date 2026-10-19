<?php

namespace Tests\Feature\Hr;

use App\Models\DtrLeave;
use App\Models\DtrSetting;
use App\Models\HrEmployee;
use App\Models\User;
use App\Services\Hr\LeaveService;
use App\Services\Hr\WorkingCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class HrLeaveTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fixture(): array
    {
        config(['hr.enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-10-19 00:00', 'UTC'));
        $user = User::factory()->create(['role' => 'sales']);
        $head = User::factory()->create(['role' => 'sales']);
        HrEmployee::create(['user_id' => $head->id, 'status' => 'active']);
        $employee = HrEmployee::create(['user_id' => $user->id, 'status' => 'active', 'manager_user_id' => $head->id]);
        DtrSetting::create(['user_id' => $user->id, 'timezone' => 'Asia/Manila', 'team' => 'Philippines', 'sched_in' => '08:00', 'sched_out' => '17:00', 'schedule_type' => 'fixed', 'is_complete' => true]);

        return [$employee, $head];
    }

    private function data(array $override = []): array
    {
        return [...['type' => 'Personal / Sick Leave', 'start_date' => '2026-10-19', 'end_date' => '2026-10-19', 'half_day' => 'N/A', 'sick_for' => 'self', 'notified_via' => 'Phone', 'notified_at' => '2026-10-18T12:00:00Z', 'certificate_status' => 'not_needed', 'declaration' => true], ...$override];
    }

    public function test_sick_leave_can_be_same_day_or_retrospective_with_server_duration(): void
    {
        [$e] = $this->fixture();
        $service = app(LeaveService::class);
        $leave = $service->submit($e->user, $this->data(['total_days' => 999]), (string) Str::uuid());
        $this->assertSame('1.0', $leave->total_days);
        $this->assertSame('pending', $leave->status);
        $this->assertSame($e->user->name, $leave->declared_name);
        $past = $service->submit($e->user, $this->data(['start_date' => '2026-10-17', 'end_date' => '2026-10-17']), (string) Str::uuid());
        $this->assertSame('1.0', $past->total_days);
    }

    public function test_annual_notice_requires_management_exception_but_head_makes_final_decision(): void
    {
        [$e,$head] = $this->fixture();
        $service = app(LeaveService::class);
        $data = $this->data(['type' => 'Annual Leave', 'team_checked' => true, 'travel_acknowledged' => true, 'auto_reply_planned' => true, 'start_date' => '2026-11-02', 'end_date' => '2026-11-02']);
        $normal = $service->submit($e->user, $data, (string) Str::uuid());
        $this->assertFalse($normal->emergency_required);
        $short = $service->submit($e->user, [...$data, 'start_date' => '2026-10-20', 'end_date' => '2026-10-20'], (string) Str::uuid());
        $this->assertTrue($short->emergency_required);
        try {
            $service->decide($head, $short, 'approved', '', 0);
            $this->fail('Exception was bypassed');
        } catch (ValidationException $ex) {
            $this->assertArrayHasKey('emergency', $ex->errors());
        }
        $management = User::factory()->create(['role' => 'sales', 'module_permissions' => ['hr.leave.emergency']]);
        $short = $service->approveEmergency($management, $short, 'Urgent family need', 0);
        $this->assertSame('pending', $short->status);
        $short = $service->decide($head, $short, 'approved', 'Approved cover', $short->hr_version);
        $this->assertSame('approved', $short->status);
        $this->assertSame($head->id, $short->reviewed_by);
    }

    public function test_calendar_honours_saturday_half_days_holidays_and_snapshot(): void
    {
        [$e] = $this->fixture();
        config(['dtr_holidays.PH.2026-10-23' => 'Test holiday']);
        $calendar = app(WorkingCalendar::class);
        $this->assertSame(1.0, $calendar->duration($e, '2026-10-23', '2026-10-25', null)['total_days']);
        $this->assertSame(0.5, $calendar->duration($e, '2026-10-24', '2026-10-24', 'AM')['total_days']);
        $leave = app(LeaveService::class)->submit($e->user, $this->data(['start_date' => '2026-10-24', 'end_date' => '2026-10-24', 'half_day' => 'AM']), (string) Str::uuid());
        $e->schedule->update(['weekly_schedule' => ['sat' => ['on' => false]]]);
        $this->assertSame('0.5', $leave->fresh()->total_days);
        $this->assertNotEmpty($leave->calendar_snapshot);
    }

    public function test_only_head_or_explicit_fallback_can_decide_and_never_self(): void
    {
        [$e,$head] = $this->fixture();
        $service = app(LeaveService::class);
        $leave = $service->submit($e->user, $this->data(), (string) Str::uuid());
        foreach ([$e->user, User::factory()->create(['role' => 'admin'])] as $actor) {
            try {
                $service->decide($actor, $leave, 'approved', '', 0);
                $this->fail('Unauthorized decision');
            } catch (HttpException $ex) {
                $this->assertSame(403, $ex->getStatusCode());
            }
        }
        $hr = User::factory()->create(['role' => 'sales', 'module_permissions' => ['hr.leave.fallback']]);
        $this->assertSame('rejected', $service->decide($hr, $leave, 'rejected', 'Head unavailable', 0)->status);
        try {
            $service->decide($head, $leave, 'approved', '', 0);
            $this->fail('Conflicting decision');
        } catch (HttpException $ex) {
            $this->assertSame(409, $ex->getStatusCode());
        }
    }

    public function test_repeat_submission_overlap_and_pending_cancellation(): void
    {
        [$e] = $this->fixture();
        $service = app(LeaveService::class);
        $key = (string) Str::uuid();
        $leave = $service->submit($e->user, $this->data(), $key);
        $this->assertSame($leave->id, $service->submit($e->user, $this->data(), $key)->id);
        try {
            $service->submit($e->user, $this->data(), (string) Str::uuid());
            $this->fail('Overlap accepted');
        } catch (ValidationException $ex) {
            $this->assertArrayHasKey('dates', $ex->errors());
        }
        $this->assertSame('cancelled', $service->cancel($e->user, $leave, 0)->status);
        $this->assertSame('pending', $service->submit($e->user, $this->data(), (string) Str::uuid())->status);
    }

    public function test_conditional_fields_types_and_schedule_are_validated(): void
    {
        [$e] = $this->fixture();
        $service = app(LeaveService::class);
        foreach ([['type' => 'Unknown'], ['certificate_status' => 'arbitrary'], ['notified_via' => null], ['type' => 'Other', 'other_specify' => null], ['type' => 'Annual Leave', 'team_checked' => false]] as $bad) {
            try {
                $service->submit($e->user, $this->data($bad), (string) Str::uuid());
                $this->fail('Invalid form accepted');
            } catch (ValidationException $ex) {
                $this->assertNotEmpty($ex->errors());
            }
        }
        $e->schedule->update(['is_complete' => false]);
        try {
            $service->submit($e->user, $this->data(), (string) Str::uuid());
            $this->fail('Missing schedule accepted');
        } catch (ValidationException $ex) {
            $this->assertArrayHasKey('schedule', $ex->errors());
        }
    }

    public function test_legacy_routes_use_new_rules_and_do_not_expose_employee_details(): void
    {
        [$e,$head] = $this->fixture();
        $data = [...$this->data(), 'request_id' => (string) Str::uuid()];
        $this->actingAs($e->user)->postJson('/dtr/leaves', $data)->assertOk()->assertJsonPath('leave.status', 'pending');
        $leave = DtrLeave::first();
        $this->actingAs($head)->getJson('/dtr/leaves/'.$leave->id)->assertOk();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->getJson('/dtr/leaves/'.$leave->id)->assertNotFound();
        $this->postJson('/dtr/leaves/'.$leave->id.'/review', ['version' => 0, 'decision' => 'approved'])->assertForbidden();
        $this->actingAs($head)->postJson('/dtr/leaves/'.$leave->id.'/review', ['version' => 0, 'decision' => 'approved'])->assertOk();
    }

    public function test_http_validation_legacy_dates_and_preview_mutations(): void
    {
        [$e] = $this->fixture();
        $this->actingAs($e->user)->postJson('https://hr.luvep.com/leave', [...$this->data(), 'request_id' => (string) Str::uuid(), 'declaration' => false])->assertUnprocessable();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->withSession(['hr_preview_employee_id' => $e->id])->postJson('https://hr.luvep.com/leave',[...$this->data(), 'request_id' => (string) Str::uuid()])->assertForbidden();
    }
}
