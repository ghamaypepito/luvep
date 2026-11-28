<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accommodation_move_outs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('accommodation_tenants')->nullOnDelete();
            $table->string('tenant_label');
            $table->foreignId('property_id')->nullable()->constrained('accommodation_properties')->nullOnDelete();
            $table->string('property_label');
            $table->foreignId('agreement_id')->nullable()->constrained('accommodation_agreements')->nullOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('agreement_ends_on');
            $table->date('move_out_on')->nullable();
            $table->date('check_in_on')->index();
            $table->date('reminder_on')->nullable()->index();
            $table->boolean('automatic_email')->default(true);
            $table->string('stage', 32);
            $table->string('decision', 16)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'agreement_ends_on'], 'accommodation_move_out_term_unique');
        });

        Schema::create('accommodation_move_out_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('move_out_id')->constrained('accommodation_move_outs')->cascadeOnDelete();
            $table->string('action', 32);
            $table->string('actor');
            $table->json('details');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accommodation_move_out_events');
        Schema::dropIfExists('accommodation_move_outs');
    }
};
