<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EnglishStudentArchiveController extends Controller
{
    public static function canManage(Request $request): bool
    {
        $user = $request->user();

        return $user?->is_active && ($user->isAdmin() || $user->hasRole('education'))
            && ! $request->attributes->has('user_view_actor');
    }

    public function index(Request $request)
    {
        abort_unless(self::canManage($request), 403);

        $search = trim($request->validate(['search' => 'nullable|string|max:120'])['search'] ?? '');
        $pattern = '%'.strtr(mb_strtolower($search), ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
        $students = Lead::query()->whereNotNull('english_students_archived_at')
            ->when($search !== '', fn ($query) => $query->where(fn ($matches) => $matches
                ->whereRaw("LOWER(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(lead_id) LIKE ? ESCAPE '!'", [$pattern])))
            ->orderByDesc('english_students_archived_at')->orderByDesc('id')
            ->paginate(25, ['id', 'lead_id', 'first_name', 'last_name'])
            ->appends(['search' => $search])
            ->through(fn (Lead $lead) => [
                'id' => $lead->id, 'lead_id' => $lead->lead_id,
                'name' => trim("{$lead->first_name} {$lead->last_name}") ?: 'Unknown',
            ]);

        return inertia('portal/education/EnglishStudentArchive', ['students' => $students, 'search' => $search]);
    }

    public function update(Request $request, int $lead)
    {
        abort_unless(self::canManage($request), 403);
        $request->validate(['archived' => 'required|boolean']);
        $archive = $request->boolean('archived');

        DB::transaction(function () use ($lead, $archive) {
            $current = Lead::whereKey($lead)->lockForUpdate()->firstOrFail();
            $alreadyArchived = $current->english_students_archived_at !== null;
            if ($alreadyArchived === $archive) {
                return;
            }
            abort_if($archive && ! $current->english_stage && $current->stage !== 'English Pro', 404);

            // This queue preference must not change the lead's activity date or trigger lifecycle observers.
            Lead::whereKey($lead)->toBase()->update(['english_students_archived_at' => $archive ? now() : null]);
            ActivityLog::record($archive ? 'education.english_student_archived' : 'education.english_student_restored', [
                'portal' => 'education', 'entity_type' => Lead::class, 'entity_id' => $lead,
            ]);
        });

        return back()->with('success', $archive ? 'Student archived from the English queue.' : 'Student restored to the English queue.');
    }
}
