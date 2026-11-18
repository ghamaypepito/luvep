<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Use the approved existing content only. Configuration creates no
        // messages and never scans or re-notifies historical students.
        foreach (['educ_to_migration' => 'client', 'educ_to_migration_internal' => 'team'] as $key => $recipient) {
            $exists = DB::table('message_templates')->where('key', $key)
                ->where('department', 'immigration')->where('is_active', true)->whereNull('deleted_at')->exists();
            if (! $exists || DB::table('email_automation_messages')->where('event_key', 'immigration.education.handoff')->where('recipient', $recipient)->exists()) {
                continue;
            }
            DB::table('email_automation_messages')->insert([
                'event_key' => 'immigration.education.handoff', 'recipient' => $recipient,
                'template_key' => $key, 'channel' => 'email', 'enabled' => true,
                'sort_order' => $recipient === 'client' ? 0 : 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Retain administrator-owned configuration on code rollback. Without
        // the handoff trigger these rows are inert; do not delete later edits.
    }
};
