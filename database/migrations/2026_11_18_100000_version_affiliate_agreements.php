<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_agreements', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1);
            $table->longText('rendered_html')->nullable();
            $table->string('issued_file_path')->nullable();
            $table->date('expires_on')->nullable();
            $table->boolean('is_draft')->default(false);
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('send_requested_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('send_error', 500)->nullable();
        });
        DB::table('agent_agreements')->orderBy('agent_id')->orderBy('id')->get(['id', 'agent_id', 'file_path', 'created_at'])->groupBy('agent_id')->each(function ($agreements) {
            foreach ($agreements->values() as $index => $agreement) {
                DB::table('agent_agreements')->where('id', $agreement->id)->update(['version' => $index + 1, 'issued_file_path' => $agreement->file_path, 'generated_at' => $agreement->created_at]);
            }
        });
        Schema::table('agent_agreements', fn (Blueprint $table) => $table->unique(['agent_id', 'version']));
    }

    public function down(): void
    {
        Schema::table('agent_agreements', function (Blueprint $table) {
            $table->dropUnique(['agent_id', 'version']);
            $table->dropColumn(['version', 'rendered_html', 'issued_file_path', 'expires_on', 'is_draft', 'generated_at', 'send_requested_at', 'sent_at', 'send_error']);
        });
    }
};
