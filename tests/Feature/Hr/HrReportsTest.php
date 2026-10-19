<?php

namespace Tests\Feature\Hr;

use App\Jobs\Hr\BuildHrExport;
use App\Models\DtrEntry;
use App\Models\DtrSetting;
use App\Models\HrEmployee;
use App\Models\HrShift;
use App\Models\User;
use App\Services\Hr\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HrReportsTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        config(['hr.enabled' => true]);
        $this->withoutVite();
        Storage::fake('hr_private');
        Queue::fake();
        $reviewer = User::factory()->create(['role' => 'sales']);
        HrEmployee::create(['user_id' => $reviewer->id, 'status' => 'active']);
        $u = User::factory()->create(['role' => 'sales']);
        $e = HrEmployee::create(['user_id' => $u->id, 'status' => 'active', 'reviewer_user_id' => $reviewer->id]);
        DtrSetting::create(['user_id' => $u->id, 'timezone' => 'UTC', 'is_complete' => true, 'break_hours' => 1, 'break_after' => 6]);
        $other = User::factory()->create(['role' => 'sales']);
        HrEmployee::create(['user_id' => $other->id, 'status' => 'active']);
        foreach ([$u, $other] as $person) {
            $r = DtrEntry::create(['user_id' => $person->id, 'work_date' => '2026-10-19', 'time_in' => '08:00', 'time_out' => '17:00', 'remarks' => 'PRIVATE MEDICAL NOTE']);
            $r->forceFill(['hr_status' => 'reviewed'])->save();
        }

        return [$e, $reviewer, $other];
    }

    private function filters(array $override = []): array
    {
        return [...['from' => '2026-10-01', 'to' => '2026-10-31'], ...$override];
    }

    public function test_own_assigned_and_explicit_report_scopes(): void
    {
        [$e,$r,$other] = $this->fixture();
        $s = app(ReportService::class);
        $this->assertCount(1, $s->rows($e->user, 'attendance', $this->filters()));
        $this->assertCount(1, $s->rows($r, 'attendance', $this->filters()));
        $this->assertCount(1, $s->rows($r, 'employee_status', $this->filters()));
        $r->update(['module_permissions' => ['hr.reports']]);
        $this->assertCount(3, $s->rows($r->fresh(), 'employee_status', $this->filters()));
        $this->actingAs($e->user)->getJson('https://hr.luvep.com/reports?type=attendance&from=2026-10-01&to=2026-10-31&employee_id='.HrEmployee::where('user_id', $other->id)->value('id'))->assertNotFound();
    }

    public function test_approved_hours_exclude_drafts_and_use_real_breaks(): void
    {
        [$e,$r] = $this->fixture();
        $entry = DtrEntry::where('user_id', $e->user_id)->first();
        $entry->forceFill(['time_source' => 'live'])->save();
        $shift = HrShift::create(['hr_employee_id' => $e->id, 'work_date' => '2026-10-19', 'timezone' => 'UTC', 'started_at' => '2026-10-19 08:00', 'ended_at' => '2026-10-19 16:00', 'state' => 'off', 'version' => 4]);
        $shift->breaks()->create(['started_at' => '2026-10-19 12:00', 'ended_at' => '2026-10-19 12:30']);
        $draft = DtrEntry::create(['user_id' => $e->user_id, 'work_date' => '2026-10-20']);
        $draft->forceFill(['hr_status' => 'draft'])->save();
        $rows = app(ReportService::class)->rows($r, 'approved_hours', $this->filters())->all();
        $this->assertCount(1, $rows);
        $this->assertSame(27000, $rows[0]['working_seconds']);
        $this->assertSame(1800, $rows[0]['break_seconds']);
        $this->assertStringNotContainsString('PRIVATE MEDICAL NOTE', json_encode($rows));
    }

    public function test_export_survives_database_json_key_reordering_but_not_changed_filters(): void
    {
        [$e, $r] = $this->fixture();
        $s = app(ReportService::class);
        $x = $s->requestExport($r, 'attendance', $this->filters(['employee_id' => $e->id]));
        // Native MySQL JSON storage normalizes object member order.
        $x->update(['filters' => array_reverse($x->filters, true)]);
        (new BuildHrExport($x->id))->handle();
        $this->assertSame('ready', $x->fresh()->status);
        $this->actingAs($r)->get('https://hr.luvep.com/reports/exports/'.$x->id)->assertOk();
        $x->update(['filters' => [...$x->filters, 'from' => '2026-09-01']]);
        $this->get('https://hr.luvep.com/reports/exports/'.$x->id)->assertNotFound();
    }

    public function test_revoked_export_is_not_downloadable(): void
    {
        [$e,$r] = $this->fixture();
        $s = app(ReportService::class);
        $x = $s->requestExport($r, 'attendance', $this->filters(['employee_id' => $e->id]));
        $e->update(['reviewer_user_id' => null]);
        (new BuildHrExport($x->id))->handle();
        $this->assertSame('revoked', $x->fresh()->status);
        $this->assertEmpty(Storage::disk('hr_private')->allFiles());
        $this->actingAs($r)->get('https://hr.luvep.com/reports/exports/'.$x->id)->assertNotFound();
    }

    public function test_download_rechecks_grants_after_generation_and_identity_and_expiry(): void
    {
        [$e,$r,$other] = $this->fixture();
        $s = app(ReportService::class);
        $x = $s->requestExport($r, 'attendance', $this->filters());
        (new BuildHrExport($x->id))->handle();
        $this->assertSame('ready', $x->fresh()->status);
        $this->actingAs($r)->get('https://hr.luvep.com/reports/exports/'.$x->id)->assertOk();
        $this->actingAs($other)->get('https://hr.luvep.com/reports/exports/'.$x->id)->assertNotFound();
        $e->update(['reviewer_user_id' => null]);
        $this->actingAs($r)->get('https://hr.luvep.com/reports/exports/'.$x->id)->assertNotFound();
        $own = $s->requestExport($other, 'attendance', $this->filters());
        (new BuildHrExport($own->id))->handle();
        $own->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($other)->get('https://hr.luvep.com/reports/exports/'.$own->id)->assertNotFound();
        $this->artisan('hr:expire-exports')->assertSuccessful();
        $this->assertFalse(Storage::disk('hr_private')->exists($own->fresh()->storage_key ?? 'missing'));
    }

    public function test_csv_escapes_formulas_quotes_and_separators_and_archiving_revokes_exports(): void
    {
        [$e] = $this->fixture();
        $e->user->update(['name' => "  =SUM(1,2)\"\nX"]);
        $s = app(ReportService::class);
        $x = $s->requestExport($e->user->fresh(), 'attendance', $this->filters());
        (new BuildHrExport($x->id))->handle();
        $csv = Storage::disk('hr_private')->get($x->fresh()->storage_key);
        $f = fopen('php://temp', 'r+');
        fwrite($f, $csv);
        rewind($f);
        fgetcsv($f, escape: '');
        $row = fgetcsv($f, escape: '');
        fclose($f);
        $this->assertSame("'  =SUM(1,2)\"\nX", $row[0]);
        foreach (['=1', '+1', '-1', '@SUM(1)', "\t=1", "\n=1"] as $v) {
            $this->assertSame("'".$v, $s->csvCell($v));
        }$e->update(['status' => 'archived']);
        $this->actingAs($e->user)->get('https://hr.luvep.com/reports/exports/'.$x->id)->assertForbidden();
    }

    public function test_range_limit_and_preview_export_creation(): void
    {
        [$e] = $this->fixture();
        $this->actingAs($e->user)->postJson('https://hr.luvep.com/reports/exports', ['type' => 'attendance', 'from' => '2020-01-01', 'to' => '2026-01-01'])->assertUnprocessable();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->withSession(['hr_preview_employee_id' => $e->id])->postJson('https://hr.luvep.com/reports/exports', ['type' => 'attendance', ...$this->filters()])->assertForbidden();
    }

    public function test_printable_leave_is_scoped_to_owner_and_authorized_reviewer(): void
    {
        [$e,$reviewer,$other] = $this->fixture();
        $leave = \App\Models\DtrLeave::create(['user_id' => $e->user_id, 'type' => 'Annual Leave', 'start_date' => '2026-11-01', 'end_date' => '2026-11-02', 'reason' => 'PRIVATE LEAVE REASON', 'status' => 'pending']);
        $url = 'https://hr.luvep.com/leave/'.$leave->id.'/print';
        $this->actingAs($e->user)->get($url)->assertOk()->assertSee('PRIVATE LEAVE REASON');
        $this->actingAs($other)->get($url)->assertNotFound();
        $this->actingAs($reviewer)->get($url)->assertNotFound();
        $e->update(['manager_user_id' => $reviewer->id]);
        $this->actingAs($reviewer)->get($url)->assertOk();
    }
}
