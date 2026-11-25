<?php

namespace App\Http\Controllers;

use App\Models\AccommodationDtrTask;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccommodationDtrTaskController extends Controller
{
    private function members(Request $request): Collection
    {
        $ids = collect(config('accommodation.shared_dtr_members', []))
            ->filter(fn ($id) => filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false)
            ->map(fn ($id) => (int) $id)->unique();
        $user = $request->user();

        if ($ids->count() !== 2 || ! $user?->is_active || ! $ids->containsStrict((int) $user->id)
            || ! $user->canAccessPortal('accommodation') || $request->attributes->has('user_view_actor')
            || app(\App\Services\Hr\HrContext::class)->isPreview($request)) {
            return collect();
        }

        return User::whereIn('id', $ids)->where('is_active', true)->orderBy('name')->get()
            ->filter(fn (User $member) => $member->canAccessPortal('accommodation'))
            ->map(fn (User $member) => ['id' => $member->id, 'name' => $member->name])->values();
    }

    public function panel(Request $request): ?array
    {
        $members = $this->members($request);
        if ($members->isEmpty()) {
            return null;
        }

        return [
            'members' => $members,
            'userId' => $request->user()->id,
            'tasks' => AccommodationDtrTask::whereNull('completed_at')->latest()->latest('id')->get(),
            'completed' => AccommodationDtrTask::whereNotNull('completed_at')->orderByDesc('completed_at')->orderByDesc('id')->limit(30)->get(),
        ];
    }

    public function store(Request $request)
    {
        $members = $this->members($request);
        abort_if($members->isEmpty(), 403);
        AccommodationDtrTask::create($request->validate($this->rules($members)));

        return back();
    }

    public function update(Request $request, int $id)
    {
        $members = $this->members($request);
        abort_if($members->isEmpty(), 403);
        $data = $request->validate($this->rules($members) + ['version' => ['required', 'integer', 'min:0']]);

        DB::transaction(function () use ($id, $data) {
            $task = AccommodationDtrTask::lockForUpdate()->findOrFail($id);
            if ($task->completed_at || $task->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => 'This task changed. Refresh before editing it again.']);
            }
            $task->update([...$data, 'version' => $task->version + 1]);
        });

        return back();
    }

    public function complete(Request $request, int $id)
    {
        abort_if($this->members($request)->isEmpty(), 403);
        $actor = $request->user();
        $data = $request->validate(['version' => ['required', 'integer', 'min:0']]);

        DB::transaction(function () use ($id, $actor, $data) {
            $task = AccommodationDtrTask::lockForUpdate()->findOrFail($id);
            if (! $task->completed_at) {
                if ($task->version !== (int) $data['version']) {
                    throw ValidationException::withMessages(['version' => 'This task changed. Refresh before completing it.']);
                }
                $task->update([
                    'status' => 'completed', 'completed_at' => now(), 'completed_by' => $actor->id,
                    'completed_by_name' => $actor->name, 'version' => $task->version + 1,
                ]);
            }
        });

        return back();
    }

    private function rules(Collection $members): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'assignee_id' => ['required', 'integer', Rule::in($members->pluck('id')->all())],
            'status' => ['required', Rule::in(['pending', 'ongoing'])],
        ];
    }
}
