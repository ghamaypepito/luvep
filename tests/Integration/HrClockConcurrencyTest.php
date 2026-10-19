<?php

namespace Tests\Integration;

use App\Models\DtrSetting;
use App\Models\HrEmployee;
use App\Models\HrShift;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class HrClockConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Requires the isolated MySQL fixture.');
        }
        $this->assertSame('luvep_hr_test', config('database.connections.mysql.database'), 'Never run destructive test setup against an application database.');
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }

    private function compete(bool $sameKey): array
    {
        $u = User::factory()->create(['role' => 'sales']);
        DtrSetting::create(['user_id' => $u->id, 'timezone' => 'Asia/Manila', 'sched_in' => '08:00', 'sched_out' => '17:00', 'is_complete' => true, 'schedule_type' => 'fixed']);
        $e = HrEmployee::create(['user_id' => $u->id, 'status' => 'active']);
        $gate = sys_get_temp_dir().'/hr-clock-gate-'.Str::uuid();
        $key = (string) Str::uuid();
        $children = [];
        for ($i = 0; $i < 2; $i++) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, base_path('tests/Fixtures/hr-clock-worker.php'), (string) $e->id, $sameKey ? $key : (string) Str::uuid(), (string) $gate, (string) $i], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            $children[] = [$process, $pipes];
        }
        $deadline = microtime(true) + 10;
        while (! file_exists($gate.'.ready.0') || ! file_exists($gate.'.ready.1')) {
            if (microtime(true) > $deadline) {
                $this->fail('Clock workers did not become ready.');
            }
            usleep(10000);
        }
        touch($gate);
        $results = [];
        foreach ($children as [$process,$pipes]) {
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $errors);
            $results[] = (int) $output;
        }
        unlink($gate);
        unlink($gate.'.ready.0');
        unlink($gate.'.ready.1');
        sort($results);
        $this->assertSame(1, HrShift::whereNull('ended_at')->count(), 'Worker responses: '.json_encode($results));

        return $results;
    }

    public function test_competing_devices_cannot_open_two_shifts(): void
    {
        $this->assertSame([200, 409], $this->compete(false));
    }

    public function test_concurrent_retry_returns_one_shift_to_both_callers(): void
    {
        $this->assertSame([200, 200], $this->compete(true));
    }

    private function operationRace(string $operation, array $actors, array $subjects): array
    {
        $gate = sys_get_temp_dir().'/hr-operation-gate-'.Str::uuid();
        $children = [];
        for ($i = 0; $i < 2; $i++) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, base_path('tests/Fixtures/hr-operation-worker.php'), $operation, (string) $actors[$i], (string) $subjects[$i], $gate, (string) $i], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            $children[] = [$process, $pipes];
        }
        $deadline = microtime(true) + 10;
        while (! file_exists($gate.'.ready.0') || ! file_exists($gate.'.ready.1')) {
            if (microtime(true) > $deadline) {
                $this->fail('Operation workers did not become ready.');
            }usleep(10000);
        }touch($gate);
        $results = [];
        foreach ($children as [$process,$pipes]) {
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $errors);
            $results[] = trim($output);
        }unlink($gate);
        unlink($gate.'.ready.0');
        unlink($gate.'.ready.1');
        sort($results);

        return $results;
    }

    public function test_competing_leave_equipment_and_reminder_requests_preserve_one_result(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $a = User::factory()->create(['role' => 'sales']);
        $b = User::factory()->create(['role' => 'sales']);
        $ea = HrEmployee::create(['user_id' => $a->id, 'status' => 'active', 'manager_user_id' => $admin->id]);
        $eb = HrEmployee::create(['user_id' => $b->id, 'status' => 'active']);
        $this->assertSame(['200', '409'], $this->operationRace('equipment', [$admin->id, $admin->id], [$ea->id, $eb->id]));
        $this->assertSame(1, \App\Models\HrEquipmentEvent::where('action', 'issued')->count());
        $leave = \App\Models\DtrLeave::create(['user_id' => $a->id, 'type' => 'Annual Leave', 'start_date' => '2026-12-01', 'end_date' => '2026-12-01', 'status' => 'pending']);
        $this->assertSame(['200', '409'], $this->operationRace('leave', [$admin->id, $admin->id], [$leave->id, $leave->id]));
        $this->assertSame(1, \App\Models\HrEvent::where('subject_id', $leave->id)->where('subject_type', $leave->getMorphClass())->count());
        HrShift::create(['hr_employee_id' => $ea->id, 'work_date' => now()->toDateString(), 'timezone' => 'UTC', 'started_at' => now()->subHours(7), 'scheduled_end_at' => now()->addMinutes(10), 'active_guard' => 1]);
        $this->assertSame(['claimed', 'empty'], $this->operationRace('reminder', [$a->id, $a->id], [$ea->id, $ea->id]));
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('hr_shift_reminders')->count());
    }
}
