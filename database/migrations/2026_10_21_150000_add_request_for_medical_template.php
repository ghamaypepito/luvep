<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve any existing operator-authored template, including inactive ones.
        if (DB::table('message_templates')->where('department', 'immigration')->where('key', 'request_for_medical')->exists()) {
            return;
        }
        DB::table('message_templates')->insert([
            'department' => 'immigration', 'key' => 'request_for_medical',
            'name' => 'Request for Medical', 'channels' => json_encode(['email']),
            'email_subject' => 'Request for Medical',
            'email_body' => "Hi {{first_name}},\n\nImmigration New Zealand has requested medical information for your application. Please read the attached INZ notice and follow its instructions.\n\nResponse deadline: **{{medical_deadline}}**.\n\nIf you have questions about the notice or deadline, please contact your immigration adviser.",
            'variables_documented' => json_encode(['first_name', 'medical_deadline']),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Retain editable correspondence templates when rolling back application code.
    }
};
