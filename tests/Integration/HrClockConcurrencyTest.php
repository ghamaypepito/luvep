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
}
