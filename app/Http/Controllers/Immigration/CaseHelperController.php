<?php

namespace App\Http\Controllers\Immigration;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\User;
use App\Services\Immigration\AssignedCaseAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CaseHelperController extends Controller
{
    private function staff(Request $request, bool $lock = false): void
    {
        $user = $lock ? User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail() : $request->user();
        abort_unless($user?->is_active && ($user->isAdmin() || $user->role === 'immigration_manager'), 403);
        abort_if($request->attributes->has('user_view_actor'), 403);
    }

    public function show(Request $request, int $lead, AssignedCaseAccess $access)
    {
        $this->staff($request);
        $case = Lead::immigrationCase()->findOrFail($lead);
        $helper = $case->immigration_helper_id ? User::find($case->immigration_helper_id) : null;
        $eligible = User::where('role', 'agent')->where('is_active', true)->orderBy('name')->get()
            ->filter(fn ($user) => $access->eligible($user))->map(fn ($user) => $user->only(['id', 'name']))->values();

        return inertia('portal/immigration/CaseHelper', ['case' => $access->summary($case),
            'helper' => $helper?->only(['id', 'name']), 'eligibleHelpers' => $eligible]);
    }

    public function store(Request $request, int $lead, AssignedCaseAccess $access)
    {
        $this->staff($request);
        $unexpected = array_diff(array_keys($request->all()), ['helper_id', 'expected_helper_id', 'confirmed', '_token']);
        if ($unexpected) {
            throw ValidationException::withMessages(array_fill_keys($unexpected, 'Only the confirmed helper assignment may be submitted.'));
        }
        $data = $request->validate(['helper_id' => 'present|nullable|integer|min:1',
            'expected_helper_id' => 'present|nullable|integer|min:1', 'confirmed' => 'required|accepted']);
        DB::transaction(function () use ($lead, $data, $access, $request) {
            $case = Lead::immigrationCase()->whereKey($lead)->lockForUpdate()->firstOrFail();
            $this->staff($request, true);
            $id = $data['helper_id'] === null ? null : (int) $data['helper_id'];
            if ($id) {
                $helper = User::whereKey($id)->lockForUpdate()->first();
                if (! $helper || ! $access->eligible($helper)) {
                    throw ValidationException::withMessages(['helper_id' => 'Choose an active Agent with the assigned-case module already enabled.']);
                }
            }
            $old = $case->immigration_helper_id;
            if ($old === $id) {
                return;
            }
            $expected = $data['expected_helper_id'] === null ? null : (int) $data['expected_helper_id'];
            if ($old !== $expected) {
                throw ValidationException::withMessages(['expected_helper_id' => 'Helper access changed. Refresh the case and confirm the current assignment before saving.']);
            }
            $case->forceFill(['immigration_helper_id' => $id])->save();
            ActivityLog::record('case.helper.assigned', ['description' => $id ? 'Assigned a case helper without changing custody.' : 'Removed case helper access.',
                'properties' => ['subject_type' => 'Lead', 'subject_id' => $case->id, 'old_helper_id' => $old, 'helper_id' => $id]]);
        });

        return back()->with('success', 'Case helper access updated.');
    }
}
