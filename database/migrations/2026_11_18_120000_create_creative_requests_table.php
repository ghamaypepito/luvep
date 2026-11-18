<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite skipped the older MySQL change; creative tasks have no lead.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('lead_tasks', fn (Blueprint $table) => $table->unsignedBigInteger('lead_id')->nullable()->change());
        }
        Schema::create('creative_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_ref', 32)->unique();
            $table->foreignId('lead_task_id')->unique()->constrained('lead_tasks');
            $table->json('brief');
            $table->json('attachments')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creative_requests');
    }
};
