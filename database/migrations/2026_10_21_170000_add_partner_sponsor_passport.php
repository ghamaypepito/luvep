<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('visa_checklist_ticket_74_backups')) {
            Schema::create('visa_checklist_ticket_74_backups', function (Blueprint $table) {
                $table->unsignedBigInteger('visa_type_id')->primary();
                $table->longText('original_checklist');
                $table->longText('patched_checklist');
            });
        }
        // Only catalogues confirmed to use the partnership checklist. Never
        // rewrite case overrides, document keys, uploads or unrelated visa types.
        DB::transaction(function () {
            foreach (DB::table('visa_types')->whereIn('code', ['PRSWV', 'PRAEWV', 'PRNZRV', 'PARTNER_WORK', 'PARTNER_RES'])->get() as $visa) {
                $items = json_decode($visa->checklist_items ?? '[]', true);
                if (! is_array($items) || $items === []) {
                    continue;
                }
                $present = collect($items)->contains(fn ($item) => ($item['key'] ?? '') === 'sponsor_passport'
                    || preg_match('/(sponsor|partner).*passport|passport.*(sponsor|partner)/i', $item['label'] ?? ''));
                if ($present || DB::table('visa_checklist_ticket_74_backups')->where('visa_type_id', $visa->id)->exists()) {
                    continue;
                }
                $items[] = ['key' => 'sponsor_passport', 'label' => 'Passport of Sponsor',
                    'category' => 'Sponsor Documents', 'hint' => "Copy of the supporting partner's passport.",
                    'required' => true, 'file_code' => 'PPT', 'file_suffix' => '(of sponsor)'];
                $patched = json_encode($items);
                DB::table('visa_checklist_ticket_74_backups')->insert([
                    'visa_type_id' => $visa->id, 'original_checklist' => $visa->checklist_items, 'patched_checklist' => $patched,
                ]);
                DB::table('visa_types')->where('id', $visa->id)->update(['checklist_items' => $patched]);
            }
        });
    }

    public function down(): void
    {
        // Restore only an untouched patch. Retain backups and never remove a
        // checklist slot once a case has uploaded against it.
        foreach (DB::table('visa_checklist_ticket_74_backups')->get() as $backup) {
            $visa = DB::table('visa_types')->where('id', $backup->visa_type_id)->first();
            if (! $visa || json_decode($visa->checklist_items, true) !== json_decode($backup->patched_checklist, true)) {
                continue;
            }
            // Case visa types can change and dependants have separate types;
            // any use of this key conservatively prevents slot removal.
            $hasUploads = DB::table('lead_documents')->where('checklist_key', 'sponsor_passport')->exists();
            if (! $hasUploads) {
                DB::table('visa_types')->where('id', $visa->id)->update(['checklist_items' => $backup->original_checklist]);
                DB::table('visa_checklist_ticket_74_backups')->where('visa_type_id', $visa->id)->delete();
            }
        }
    }
};
