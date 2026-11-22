<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\LeadNote;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/** A single-ticket repair, deliberately not a general-purpose client merge. */
class RepairEducationDuplicateTicket extends Command
{
    protected $signature = 'education:repair-duplicate-ntujat {--apply= : Exact digest from a dry run} {--actor= : Administrator recording the repair}';
    protected $description = 'Preview or apply the verified 402 -> 512 Education duplicate consolidation for TKT-NTUJAT.';
    private const SOURCE = 402;
    private const TARGET = 512;
    private const ACTION = 'lead.education_duplicate_merged';

    public function handle(): int
    {
        try {
            return DB::transaction(function () {
                $source = Lead::withTrashed()->lockForUpdate()->findOrFail(self::SOURCE);
                $target = Lead::lockForUpdate()->findOrFail(self::TARGET);
                $prior = ActivityLog::where('action', self::ACTION)->where('entity_type', Lead::class)->where('entity_id', self::TARGET)->first();
                if ($source->trashed() && $prior) {
                    $this->info('TKT-NTUJAT was already applied; no changes made.');
                    return self::SUCCESS;
                }
                $this->require(! $source->trashed() && ! $prior, 'Unexpected partial repair; inspect before continuing.');
                $normalize = fn ($lead) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($lead->first_name.' '.$lead->last_name)));
                $this->require($normalize($source) === $normalize($target), 'Names no longer match.');
                $this->require($source->education_stage === 'Started Course' && $target->education_stage === null, 'Education stages changed.');
                $this->require($target->is_student && $target->is_immigration_case && $target->immigration_stage === 'Approved Visa', 'Survivor membership changed.');
                $this->require(! $source->dob && ! $source->passport_number && $target->dob && $target->passport_number, 'Identity fields changed.');
                $this->require($target->portalUser()->exists() && ! $source->portalUser()->exists(), 'Portal account ownership changed.');

                // Reject newly discovered source relationships rather than silently
                // abandoning bookings, financials, consent or other client records.
                $relations = [];
                foreach (Schema::getTables() as $table) {
                    foreach (Schema::getColumns($table['name']) as $column) {
                        if (! in_array($column['name'], ['lead_id', 'linked_lead_id', 'dependent_id'], true)) continue;
                        $rows = DB::table($table['name'])->where($column['name'], self::SOURCE)->lockForUpdate()->get()->map(fn ($row) => (array) $row)->all();
                        if (! $rows) continue;
                        $this->require($column['name'] === 'lead_id' && in_array($table['name'], [
                            'lead_documents', 'lead_study_plans', 'case_audit_views', 'case_findings', 'case_finding_runs',
                        ], true), 'Unreviewed source relationship: '.$table['name'].'.'.$column['name']);
                        $relations[$table['name']] = $rows;
                    }
                }
                $documents = $source->documents()->lockForUpdate()->get();
                $targetDocuments = $target->documents()->lockForUpdate()->get();
                $this->require($documents->count() === 1, 'Source document inventory changed.');
                $doc = $documents->sole();
                $this->require($doc->checklist_key === 'acad.offer_of_place', 'Source attachment type changed.');
                $digest = $this->fileDigest($doc);
                $matching = $targetDocuments->filter(fn ($other) => $other->checklist_key === $doc->checklist_key && hash_equals($digest, $this->fileDigest($other)));
                $this->require($matching->isNotEmpty(), 'Personalized offer no longer matches the survivor.');
                $sourcePlans = $source->studyPlans()->lockForUpdate()->get();
                $targetPlans = $target->studyPlans()->lockForUpdate()->get();
                $this->require($sourcePlans->count() === 1 && $targetPlans->count() === 1, 'Study plan inventory changed.');
                foreach (['preferred_course', 'qualification_level', 'preferred_intake'] as $field) {
                    $this->require($sourcePlans->sole()->getRawOriginal($field) === $targetPlans->sole()->getRawOriginal($field), 'Conflicting study plan: '.$field);
                }
                // Keep the original study plan, findings and audit views on the
                // archived source. The current identical plan stays on the survivor.
                $snapshot = ['ticket' => 'TKT-NTUJAT', 'source' => $source->getAttributes(), 'target' => $target->getAttributes(),
                    'relations' => $relations, 'target_documents' => $targetDocuments->map->getAttributes()->all(),
                    'target_plans' => $targetPlans->map->getAttributes()->all(), 'matching_offer_sha256' => $digest];
                $json = json_encode($snapshot, JSON_THROW_ON_ERROR);
                $planDigest = hash('sha256', $json);
                $this->info('Plan SHA256: '.$planDigest);
                $this->line('Retain 512 account/identity/referral; retain Started Course; move 1 offer; archive 402 with original history.');
                if (! $this->option('apply')) return self::SUCCESS;
                $this->require(hash_equals($planDigest, (string) $this->option('apply')), 'Dry-run digest changed; preview again.');
                $actor = User::find($this->option('actor'));
                $this->require($actor && $actor->is_active && $actor->isAdmin(), 'An active administrator actor is required.');
                Auth::login($actor);
                // Encrypted snapshot remains on the server and is written before
                // any mutation. Soft deletion preserves the complete source row.
                $backupPath = 'repairs/TKT-NTUJAT/'.$planDigest.'.json.enc';
                $disk = Storage::disk('local');
                $this->require($disk->put($backupPath, Crypt::encryptString($json)), 'Could not save the recovery snapshot.');
                $this->require(chmod($disk->path($backupPath), 0600), 'Could not restrict recovery snapshot access.');
                LeadDocument::whereKey($doc->id)->update(['lead_id' => self::TARGET]);
                $target->education_stage = 'Started Course';
                $target->saveQuietly(); // No stage-triggered email, job or notification.
                $source->deleteQuietly();
                LeadNote::create(['lead_id' => self::TARGET, 'user_id' => $actor->id, 'author_name' => $actor->name, 'author_role' => $actor->role,
                    'body' => 'TKT-NTUJAT: consolidated duplicate 402 into 512. Retained portal account, identity, referral and current study plan; restored Started Course from 402 and retained its offer attachment. Original conflicting contacts, study plan, findings and history remain on archived 402 with an encrypted recovery snapshot. No emails sent.']);
                ActivityLog::record(self::ACTION, ['entity_type' => Lead::class, 'entity_id' => self::TARGET,
                    'description' => 'TKT-NTUJAT: archived duplicate 402 under surviving Education profile 512.',
                    'properties' => ['source_id' => self::SOURCE, 'target_id' => self::TARGET, 'document_id' => $doc->id,
                        'snapshot_path' => $backupPath, 'plan_sha256' => $planDigest]]);
                $this->info('TKT-NTUJAT applied. No emails dispatched.');
                return self::SUCCESS;
            });
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) throw new \RuntimeException($message);
    }

    private function fileDigest(LeadDocument $document): string
    {
        foreach (['local', 'public'] as $name) {
            $disk = Storage::disk($name);
            if ($disk->exists($document->file_path)) {
                return hash_file('sha256', $disk->path($document->file_path));
            }
        }
        throw new \RuntimeException('An offer file is unavailable.');
    }
}
