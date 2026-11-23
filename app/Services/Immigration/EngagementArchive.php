<?php

namespace App\Services\Immigration;

use App\Models\ActivityLog;
use App\Models\EngagementPackArchive;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class EngagementArchive
{
    public static function permitted(User $user): bool
    {
        return $user->is_active && ($user->isAdmin() || in_array($user->role, ['immigration', 'immigration_manager', 'immigration_adviser'], true));
    }

    /** Apply only to the Engagement workspace, never to documents or Finance queries. */
    public static function activeDocuments(Builder $documents): Builder
    {
        return $documents->whereNotExists(fn ($archives) => $archives->selectRaw('1')
            ->from('engagement_pack_archives')
            ->whereColumn('engagement_pack_archives.lead_id', 'lead_documents.lead_id')
            ->where(fn ($pack) => $pack
                ->whereColumn('engagement_pack_archives.pack_id', 'lead_documents.notes->engagement_pack_id')
                ->orWhere(fn ($legacy) => $legacy->where('engagement_pack_archives.pack_id', 'legacy')
                    ->whereNull('lead_documents.notes->engagement_pack_id'))));
    }

    public function archive(User $actor, int $leadId, string $packId, int $documentId): EngagementPackArchive
    {
        abort_unless(self::permitted($actor), 403);

        return Cache::lock("engagement:case:{$leadId}", 90)->block(5, fn () => DB::transaction(function () use ($actor, $leadId, $packId, $documentId) {
            $lead = Lead::immigrationCase()->whereKey($leadId)->lockForUpdate()->firstOrFail();
            $query = LeadDocument::where('lead_id', $lead->id)->where('source_variant', 'like', 'engagement:%');
            $packId === 'legacy' ? $query->whereNull('notes->engagement_pack_id') : $query->where('notes->engagement_pack_id', $packId);
            // Bind the row staff actually selected; a stale/foreign document must not archive another pack.
            $query->whereKey($documentId)->lockForUpdate()->firstOrFail();

            $archive = EngagementPackArchive::firstOrCreate(['lead_id' => $lead->id, 'pack_id' => $packId], [
                'archived_by' => $actor->id, 'archived_at' => now(),
            ]);
            if ($archive->wasRecentlyCreated) {
                ActivityLog::record('engagement.archived', [
                    'description' => 'Archived an engagement from the active Engagement list. Documents and Finance records retained.',
                    'properties' => ['lead_id' => $lead->id, 'pack_id' => $packId, 'document_id' => $documentId],
                ]);
            }

            return $archive;
        }));
    }
}
