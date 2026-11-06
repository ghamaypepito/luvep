<?php

namespace Tests\Feature\EpAgent;

use App\EpAgent\BudgetLedger;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Opt-in disposable MySQL only; never runs migrations against the portal database. */
class BudgetConcurrencyTest extends TestCase
{
    public function test_competing_connections_never_reserve_more_than_budget(): void
    {
        $socket = getenv('EP_TEST_MYSQL_SOCKET');
        if (! $socket || ! extension_loaded('pcntl') || ! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('Requires disposable MySQL socket, pcntl and pdo_mysql.');
        }
        $name = 'ep_test_'.bin2hex(random_bytes(6));
        $config = ['driver' => 'mysql', 'unix_socket' => $socket, 'host' => 'localhost', 'database' => '', 'username' => 'root', 'password' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true];
        config(['database.connections.ep_concurrency' => $config]);
        DB::connection('ep_concurrency')->statement("CREATE DATABASE `$name`");
        DB::purge('ep_concurrency');
        config(['database.connections.ep_concurrency.database' => $name]);
        DB::setDefaultConnection('ep_concurrency');
        try {
            $migration = require database_path('migrations/2026_11_06_000002_create_ep_agent_budget_tables.php');
            $migration->up();
            $id = DB::table('ep_budget_accounts')->insertGetId(['currency' => 'USD', 'starts_at' => now()->subMinute(), 'ends_at' => now()->addHour(), 'limit_micros' => 100, 'held_micros' => 0, 'spent_micros' => 0, 'frozen' => false]);
            // Socket barriers ensure both children are ready before either starts reserving.
            $children = [];
            DB::disconnect('ep_concurrency');
            for ($i = 0; $i < 2; $i++) {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                $pid = pcntl_fork();
                if ($pid === -1) {
                    $this->fail('Could not fork test worker');
                }
                if ($pid === 0) {
                    fclose($pair[0]);
                    fread($pair[1], 1);
                    try {
                        DB::purge('ep_concurrency');
                        app(BudgetLedger::class)->reserve($id, 'parallel-'.$i, 60);
                        exit(0);
                    } catch (\DomainException) {
                        exit(2);
                    } catch (\Throwable) {
                        exit(3);
                    }
                }
                fclose($pair[1]);
                $children[] = [$pid, $pair[0]];
            }
            foreach ($children as [$pid, $pipe]) {
                fwrite($pipe, 'x');
                fclose($pipe);
            }
            $results = [];
            foreach ($children as [$pid]) {
                pcntl_waitpid($pid, $status);
                $results[] = pcntl_wexitstatus($status);
            }
            sort($results);
            $this->assertSame([0, 2], $results);
            $this->assertSame(60, (int) DB::table('ep_budget_accounts')->where('id', $id)->value('held_micros'));
            $this->assertSame(1, DB::table('ep_usage_reservations')->count());
            $migration->down();
            $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('ep_budget_accounts'));
        } finally {
            DB::disconnect('ep_concurrency');
            config(['database.connections.ep_concurrency.database' => '']);
            DB::purge('ep_concurrency');
            DB::connection('ep_concurrency')->statement("DROP DATABASE IF EXISTS `$name`");
            DB::purge('ep_concurrency');
            DB::setDefaultConnection('sqlite');
        }
    }
}
