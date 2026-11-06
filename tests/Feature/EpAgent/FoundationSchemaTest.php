<?php

namespace Tests\Feature\EpAgent;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FoundationSchemaTest extends TestCase
{
    public function test_all_additive_tables_apply_and_reverse_on_disposable_mysql(): void
    {
        $socket = getenv('EP_TEST_MYSQL_SOCKET');
        if (! $socket || ! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('Requires disposable MySQL socket and pdo_mysql.');
        }
        $name = 'ep_test_'.bin2hex(random_bytes(6));
        config(['database.connections.ep_schema' => ['driver' => 'mysql', 'unix_socket' => $socket, 'host' => 'localhost', 'database' => '', 'username' => 'root', 'password' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true]]);
        DB::connection('ep_schema')->statement("CREATE DATABASE `$name`");
        DB::purge('ep_schema');
        config(['database.connections.ep_schema.database' => $name]);
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection('ep_schema');
        try {
            // Parent key types match production, without using a portal database.
            DB::statement('CREATE TABLE users (id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
            DB::statement('CREATE TABLE leads (id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
            $migrations = [];
            foreach (glob(database_path('migrations/2026_11_06_00000*_create_ep_agent_*tables.php')) as $file) {
                $migrations[] = require $file;
            }
            $this->assertCount(3, $migrations);
            foreach ($migrations as $migration) {
                $migration->up();
            }
            foreach (['ep_conversations', 'ep_messages', 'ep_record_grants', 'ep_budget_accounts', 'ep_usage_reservations', 'ep_runs', 'ep_events'] as $table) {
                $this->assertTrue(Schema::hasTable($table));
            }
            foreach (array_reverse($migrations) as $migration) {
                $migration->down();
            }
            $this->assertTrue(Schema::hasTable('users'));
            $this->assertTrue(Schema::hasTable('leads'));
            $this->assertFalse(Schema::hasTable('ep_conversations'));
        } finally {
            DB::disconnect('ep_schema');
            config(['database.connections.ep_schema.database' => '']);
            DB::purge('ep_schema');
            DB::connection('ep_schema')->statement("DROP DATABASE IF EXISTS `$name`");
            DB::purge('ep_schema');
            DB::setDefaultConnection($previous);
        }
    }
}
