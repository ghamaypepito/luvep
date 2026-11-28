<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->templates() as $key => $template) {
            DB::table('message_templates')->insertOrIgnore([
                'department' => 'accommodation',
                'key' => $key,
                'name' => $template['name'],
                'channels' => json_encode(['email']),
                'email_subject' => $template['subject'],
                'email_body' => $template['body'],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('message_templates')->where('department', 'accommodation')->whereIn('key', array_keys($this->templates()))->delete();
    }

    private function templates(): array
    {
        return json_decode(file_get_contents(database_path('data/exalt-accommodation-email-templates.json')), true, flags: JSON_THROW_ON_ERROR);
    }
};
