<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('visa_checklist_ticket_81_backups')) {
            Schema::create('visa_checklist_ticket_81_backups', function (Blueprint $table) {
                $table->unsignedBigInteger('visa_type_id')->primary();
                $table->longText('original_checklist');
                $table->longText('patched_checklist');
            });
        }
        DB::transaction(function () {
            foreach (DB::table('visa_types')->whereNotNull('checklist_items')->get() as $visa) {
                $items = json_decode($visa->checklist_items, true);
                if (! is_array($items) || DB::table('visa_checklist_ticket_81_backups')->where('visa_type_id', $visa->id)->exists()) {
                    continue;
                }
                // Extend the visa's existing decision/outcome section only.
                // Do not infer a new required checklist for unrelated visas.
                $outcome = collect($items)->first(fn ($item) => preg_match('/outcome|decision/i', $item['category'] ?? '')
                    && preg_match('/approv|declin/i', $item['label'] ?? ''));
                if (! $outcome) {
                    continue;
                }
                $original = $items;
                foreach ([
                    'interim_visa' => ['label' => 'Interim Visa', 'pattern' => '/interim.*visa/i'],
                    'request_for_information' => ['label' => 'Request for Further Information (RFI)', 'pattern' => '/request.*information|\bRFI\b/i'],
                ] as $key => $definition) {
                    if (collect($items)->contains(fn ($item) => ($item['key'] ?? '') === $key || preg_match($definition['pattern'], $item['label'] ?? ''))) {
                        continue;
                    }
                    $items[] = ['key' => $key, 'label' => $definition['label'], 'category' => $outcome['category'],
                        'required' => false, 'hint' => 'Upload the INZ notice when issued.'];
                }
                if ($items === $original) {
                    continue;
                }
                $patched = json_encode($items);
                DB::table('visa_checklist_ticket_81_backups')->insert([
                    'visa_type_id' => $visa->id, 'original_checklist' => $visa->checklist_items, 'patched_checklist' => $patched,
                ]);
                DB::table('visa_types')->where('id', $visa->id)->update(['checklist_items' => $patched]);
            }
        });
    }

    public function down(): void
    {
        // Preserve subsequent staff edits and any documents using the new keys.
        if (DB::table('lead_documents')->whereIn('checklist_key', ['interim_visa', 'request_for_information'])->exists()) {
            return;
        }
        foreach (DB::table('visa_checklist_ticket_81_backups')->get() as $backup) {
            $visa = DB::table('visa_types')->where('id', $backup->visa_type_id)->first();
            if ($visa && json_decode($visa->checklist_items, true) === json_decode($backup->patched_checklist, true)) {
                DB::table('visa_types')->where('id', $visa->id)->update(['checklist_items' => $backup->original_checklist]);
                DB::table('visa_checklist_ticket_81_backups')->where('visa_type_id', $visa->id)->delete();
            }
        }
    }
};
