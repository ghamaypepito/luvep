<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ImmigrationEmployer;
use App\Models\ImmigrationJobCheck;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ImmigrationEmployerController extends Controller
{
    private const STAFF_ROLES = ['immigration', User::ROLE_IMMIGRATION_MANAGER, User::ROLE_IMMIGRATION_ADVISER];

    private const ASSIGNEE_ROLES = [...self::STAFF_ROLES, User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN];

    public function index(Request $request)
    {
        $user = $this->staff($request);
        $case = null;
        if ($request->route('jobCheck') !== null) {
            $case = $this->cases($user)->with(['employer', 'assignee:id,name'])->findOrFail($request->route('jobCheck'));
        }

        $canManage = $this->canManage($user);
        $search = $request->validate(['search' => ['nullable', 'string', 'max:120']])['search'] ?? '';

        return inertia('portal/immigration/EmployerJobChecks', [
            'jobCheck' => $case,
            'cases' => $case ? null : $this->cases($user)
                ->select('id', 'employer_id', 'assigned_to', 'role_title', 'stage')
                ->with(['employer:id,legal_name', 'assignee:id,name'])
                ->when($search !== '', function (Builder $query) use ($search) {
                    $term = '%'.$search.'%';
                    $query->where(function (Builder $matches) use ($term) {
                        $matches->where('role_title', 'like', $term)
                            ->orWhereHas('employer', fn (Builder $employer) => $employer->where('legal_name', 'like', $term));
                    });
                })
                ->latest('id')->paginate(25)->withQueryString(),
            'employers' => $case ? [] : $this->employers($user)->orderBy('legal_name')->get(['id', 'legal_name']),
            'staff' => $canManage ? $this->assignableStaff()->orderBy('name')->get(['id', 'name']) : [],
            'canManage' => $canManage,
            'stages' => ImmigrationJobCheck::STAGES,
            'search' => $search,
        ]);
    }

    public function storeEmployer(Request $request)
    {
        $user = $this->staff($request);
        abort_unless($this->canManage($user), 403);

        ImmigrationEmployer::create($request->validate($this->employerRules()));

        return back()->with('success', 'Employer added.');
    }

    public function updateEmployer(Request $request, int $employer)
    {
        abort_unless($this->canManage($this->staff($request)), 403);
        $record = ImmigrationEmployer::findOrFail($employer);
        $record->update($request->validate($this->employerRules($record)));

        return back()->with('success', 'Employer updated.');
    }

    private function employerRules(?ImmigrationEmployer $employer = null): array
    {
        $identifier = Rule::unique('immigration_employers', 'employer_identifier');
        if ($employer) {
            $identifier->ignore($employer);
        }

        return [
            'legal_name' => ['required', 'string', 'max:255'],
            'trading_name' => ['nullable', 'string', 'max:255'],
            'employer_identifier' => ['required', 'string', 'max:64', $identifier],
            'business_activity' => ['nullable', 'string', 'max:5000'],
            'accreditation_type' => ['nullable', Rule::in(['standard', 'high_volume', 'other'])],
            'accreditation_expires_on' => ['nullable', 'date_format:Y-m-d'],
            'approved_positions' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    public function storeCase(Request $request)
    {
        $user = $this->staff($request);
        $data = $request->validate($this->caseRules($user, true));
        $employer = $this->employers($user)->findOrFail($data['employer_id']);
        $case = ImmigrationJobCheck::create([
            'employer_id' => $employer->id,
            'created_by' => $user->id,
            'assigned_to' => $data['assigned_to'] ?? $user->id,
            'role_title' => $data['role_title'],
            'stage' => 'intake',
            'details' => [],
        ]);

        return to_route('portal.immigration.employer-job-checks.show', $case)->with('success', 'Job Check created.');
    }

    public function updateCase(Request $request, int $jobCheck)
    {
        $user = $this->staff($request);
        $cases = $this->cases($user);
        (clone $cases)->findOrFail($jobCheck);
        $data = $request->validate($this->caseRules($user, false));

        DB::transaction(function () use ($cases, $data, $jobCheck) {
            $case = $cases->lockForUpdate()->findOrFail($jobCheck);
            $case->fill([
                'role_title' => $data['role_title'],
                'stage' => $data['stage'],
                'details' => array_replace($case->details, $data['details']),
            ]);
            if (array_key_exists('assigned_to', $data)) {
                $case->assigned_to = $data['assigned_to'];
            }
            $case->save();
        });

        return back()->with('success', 'Job Check updated.');
    }

    private function staff(Request $request): User
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || in_array($user->role, self::STAFF_ROLES, true), 403);

        return $user;
    }

    private function canManage(User $user): bool
    {
        return $user->isAdmin() || $user->isImmigrationManager();
    }

    private function cases(User $user): Builder
    {
        return ImmigrationJobCheck::query()
            ->when(! $this->canManage($user), fn (Builder $query) => $query->where('assigned_to', $user->id));
    }

    private function employers(User $user): Builder
    {
        return ImmigrationEmployer::query()->when(! $this->canManage($user), fn (Builder $query) => $query
            ->whereIn('id', $this->cases($user)->select('employer_id')));
    }

    private function assignableStaff(): Builder
    {
        return User::query()->where('is_active', true)
            ->whereIn('role', self::ASSIGNEE_ROLES);
    }

    private function caseRules(User $user, bool $creating): array
    {
        $assignment = $this->canManage($user)
            ? ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)
                ->whereIn('role', self::ASSIGNEE_ROLES)->whereNull('deleted_at')]
            : ['prohibited'];

        if ($creating) {
            return [
                'employer_id' => ['required', 'integer'],
                'role_title' => ['required', 'string', 'max:255'],
                'assigned_to' => $assignment,
            ];
        }

        return [
            'employer_id' => ['prohibited'],
            'role_title' => ['required', 'string', 'max:255'],
            'stage' => ['required', Rule::in(array_keys(ImmigrationJobCheck::STAGES))],
            'assigned_to' => $assignment,
            'details' => ['present', 'array:occupation_code,positions,work_location,guaranteed_hours,maximum_hours,wage_min,wage_max,job_requirements,engagement_status,invoice_status,inz1279_status,ad_url,ad_starts_on,ad_closes_on,inz_reference,lodged_on,decision_on,token_expires_on,notes'],
            'details.occupation_code' => ['nullable', 'string', 'max:32'],
            'details.positions' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'details.work_location' => ['nullable', 'string', 'max:255'],
            'details.guaranteed_hours' => ['nullable', 'required_with:details.maximum_hours', 'numeric', 'between:0,168'],
            'details.maximum_hours' => ['nullable', 'numeric', 'between:0,168', 'gte:details.guaranteed_hours'],
            'details.wage_min' => ['nullable', 'required_with:details.wage_max', 'numeric', 'between:0,1000000'],
            'details.wage_max' => ['nullable', 'numeric', 'between:0,1000000', 'gte:details.wage_min'],
            'details.job_requirements' => ['nullable', 'string', 'max:5000'],
            'details.engagement_status' => ['nullable', Rule::in(['sent', 'signed'])],
            'details.invoice_status' => ['nullable', Rule::in(['unpaid', 'paid'])],
            'details.inz1279_status' => ['nullable', Rule::in(['requested', 'received', 'verified'])],
            'details.ad_url' => ['nullable', 'url:http,https', 'max:2048'],
            'details.ad_starts_on' => ['nullable', 'required_with:details.ad_closes_on', 'date_format:Y-m-d'],
            'details.ad_closes_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:details.ad_starts_on'],
            'details.inz_reference' => ['nullable', 'string', 'max:128'],
            'details.lodged_on' => ['nullable', 'date_format:Y-m-d'],
            'details.decision_on' => ['nullable', 'date_format:Y-m-d'],
            'details.token_expires_on' => ['nullable', 'date_format:Y-m-d'],
            'details.notes' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
