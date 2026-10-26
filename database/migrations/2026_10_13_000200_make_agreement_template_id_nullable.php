<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A managed agreement can now be generated from staff-typed free body text (the
 * blank "initial agreement"), which has no template row. Make the template FK
 * nullable so those agreements can be stored without a template reference.
 * Requires doctrine/dbal on MySQL for the change(); sqlite recreates the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agreements', function (Blueprint $table) {
            $table->unsignedBigInteger('agreement_template_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('agreements', function (Blueprint $table) {
            $table->unsignedBigInteger('agreement_template_id')->nullable(false)->change();
        });
    }
};
