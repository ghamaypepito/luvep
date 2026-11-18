<?php

use App\Models\ActivityLog;
use App\Models\EducationRsvp;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['education_rsvps', 'education_event_registrations'] as $table) {
            if (! Schema::hasColumn($table, 'legacy_event_follow_up')) {
                Schema::table($table, fn (Blueprint $schema) => $schema->boolean('legacy_event_follow_up')->nullable());
            }
        }
        // The original agreement covers contact about this event, not the later study-tips preference.
        $cutoff = Carbon::parse('2026-11-08 00:00:00', 'Asia/Manila')->utc();
        $visits = DB::table('education_visits')->where('slug', 'jmc')->where('school', 'Jose Maria College Foundation, Inc.')
            ->where('kind', 'partnership')->whereDate('event_date', '2026-11-06')
            ->where('event_time', '08:30')->where('timezone', 'Asia/Manila')->where('status', '!=', 'cancelled')->pluck('id');
        foreach ($visits as $visitId) {
            DB::transaction(function () use ($visitId, $cutoff) {
                foreach (['education_rsvps', 'education_event_registrations'] as $table) {
                    $query = DB::table($table)->where('education_visit_id', $visitId)->whereNull('legacy_event_follow_up')
                        ->where('email_follow_up', false)->whereNotNull('consented_at')
                        ->where('consented_at', '<', $cutoff)->where('created_at', '<', $cutoff);
                    if ($table === 'education_rsvps') {
                        $query->where('attendance_status', '!=', 'cancelled');
                    }
                    $ids = $query->orderBy('id')->lockForUpdate()->pluck('id');
                    if ($table === 'education_rsvps' && $ids->isNotEmpty()) {
                        // Match the staff editor's record-then-audit lock order and use a current read.
                        $blocked = ActivityLog::where('action', 'education.rsvp.answers_updated')->where('entity_type', EducationRsvp::class)
                            ->whereIn('entity_id', $ids)->lockForUpdate()->get(['entity_id', 'changes', 'properties'])->filter(function ($log) {
                                if ($log->properties['identity_changed'] ?? false) {
                                    return true;
                                }
                                foreach (['email_follow_up', 'legacy_event_follow_up'] as $choice) {
                                    if (($log->changes['before'][$choice] ?? false) && array_key_exists($choice, $log->changes['after'] ?? []) && ! $log->changes['after'][$choice]) {
                                        return true;
                                    }
                                }

                                return false;
                            })->pluck('entity_id');
                        $ids = $ids->diff($blocked)->values();
                    }
                    if ($ids->isEmpty()) {
                        continue;
                    }
                    DB::table($table)->whereIn('id', $ids)->update(['legacy_event_follow_up' => true, 'updated_at' => now()]);
                    ActivityLog::create([
                        'actor_name' => 'Education consent repair', 'actor_role' => 'system', 'portal' => 'education',
                        'action' => 'education.jmc.legacy_follow_up_restored',
                        'description' => 'Recognised the original JMC event-contact agreement for event follow-ups only; no email queued.',
                        'properties' => ['visit_id' => $visitId, 'table' => $table, 'record_ids' => $ids->all(), 'cutoff_utc' => $cutoff->toIso8601String(), 'source' => 'original_event_contact_agreement'],
                        'changes' => ['before' => ['legacy_event_follow_up' => null], 'after' => ['legacy_event_follow_up' => true]],
                    ]);
                }
            });
        }
    }

    public function down(): void
    {
        // Retain the nullable mapping columns and audited choices so rollback cannot erase withdrawals.
    }
};
