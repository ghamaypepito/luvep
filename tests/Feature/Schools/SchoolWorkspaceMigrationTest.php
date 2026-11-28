<?php

namespace Tests\Feature\Schools;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchoolWorkspaceMigrationTest extends TestCase
{
    public function test_real_rollback_and_reapply_preserve_school_history_and_file_references(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        foreach (['users', 'schools', 'programs', 'leads'] as $table) {
            Schema::create($table, fn (Blueprint $blueprint) => $blueprint->id());
        }
        DB::table('schools')->insert(['id' => 1]);
        $path = 'database/migrations/2026_11_24_010000_create_school_workspace_records.php';
        $this->artisan('migrate', ['--path' => $path, '--force' => true])->assertExitCode(0);
        DB::table('school_workspace_records')->insert(['id' => 1, 'school_id' => 1, 'kind' => 'communication', 'data' => '{"subject":"Synthetic retained history"}']);
        DB::table('school_workspace_files')->insert(['school_workspace_record_id' => 1, 'name' => 'reference.pdf', 'path' => 'synthetic/retained.pdf']);
        $this->artisan('migrate:rollback', ['--path' => $path, '--force' => true])->assertExitCode(0);
        $this->assertSame(1, DB::table('school_workspace_records')->count());
        $this->assertSame(1, DB::table('school_workspace_files')->count());
        $this->artisan('migrate', ['--path' => $path, '--force' => true])->assertExitCode(0);
        $this->assertSame(1, DB::table('school_workspace_records')->count());
        $this->assertSame('synthetic/retained.pdf', DB::table('school_workspace_files')->sole()->path);
    }
}
