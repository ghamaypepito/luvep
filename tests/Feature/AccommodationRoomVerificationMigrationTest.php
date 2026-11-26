<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccommodationRoomVerificationMigrationTest extends TestCase
{
    public static function retainedRecords(): array
    {
        return ['empty table' => [false], 'recorded verification' => [true]];
    }

    #[DataProvider('retainedRecords')]
    public function test_rollback_and_reapply_preserve_room_verification_history(bool $recorded): void
    {
        Schema::create('users', fn (Blueprint $table) => $table->id());
        Schema::create('accommodation_properties', fn (Blueprint $table) => $table->id());
        $path = 'database/migrations/2026_11_24_030000_create_property_room_verifications.php';

        $this->artisan('migrate', ['--path' => $path, '--force' => true])->assertExitCode(0);
        if ($recorded) {
            DB::table('users')->insert(['id' => 1]);
            DB::table('accommodation_properties')->insert(['id' => 1]);
            DB::table('property_room_verifications')->insert([
                'property_id' => 1, 'total_rooms' => 5, 'occupied' => 4, 'vacant' => 1,
                'unavailable' => 0, 'observed_on' => '2026-11-26', 'source_ref' => 'Synthetic register',
                'tenancy_fingerprint' => str_repeat('a', 64), 'verified_by' => 1,
            ]);
        }
        $before = DB::table('property_room_verifications')->get()->toArray();

        $this->artisan('migrate:rollback', ['--path' => $path, '--force' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasTable('property_room_verifications'));
        $this->assertEquals($before, DB::table('property_room_verifications')->get()->toArray());
        $this->assertSame(0, DB::table('migrations')->count());

        $this->artisan('migrate', ['--path' => $path, '--force' => true])->assertExitCode(0);
        $this->assertEquals($before, DB::table('property_room_verifications')->get()->toArray());
        $this->assertSame(1, DB::table('migrations')->count());
    }
}
