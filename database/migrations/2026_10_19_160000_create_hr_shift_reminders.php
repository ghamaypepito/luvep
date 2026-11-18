<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_shift_reminders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hr_shift_id')->unique()->constrained()->restrictOnDelete();
            $t->timestamp('claimed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_shift_reminders');
    }
};
