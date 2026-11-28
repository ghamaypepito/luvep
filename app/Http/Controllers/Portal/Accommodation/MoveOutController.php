<?php

namespace App\Http\Controllers\Portal\Accommodation;

use App\Http\Controllers\Controller;
use App\Models\AccommodationAgreement;
use App\Models\AccommodationMoveOut;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accommodation\MoveOutWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class MoveOutController extends Controller
{
    private const MONEY = ['required', 'regex:/^\d{1,7}(?:\.\d{1,2})?$/'];

    private function authorizeStaff(Request $request): void
    {
        $user = $request->user();
        abort_unless($user->is_active && ($user->isAdmin() || $user->hasRole('accommodation'))
            && ! $request->attributes->get('user_view_actor'), 403);
    }

    private function owners(): array
    {
        $owners = config('accommodation_move_out.owners');
        $accounts = User::where('is_active', true)
            ->whereIn('role', ['accommodation', User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN])
            ->whereIn('email', array_values($owners))->get(['id', 'email'])->keyBy('email');

        return collect($owners)->map(fn ($email, $name) => [
            'id' => $accounts->get($email)?->id,
            'name' => $name,
        ])->values()->all();
    }

    public function index(Request $request)
    {
        $this->authorizeStaff($request);
        $filters = $request->validate([
            'search' => 'nullable|string|max:150',
            'stage' => ['nullable', Rule::in(array_keys(AccommodationMoveOut::STAGES))],
            'assignee' => ['nullable', Rule::in(['mine', 'unassigned', 'Alaine', 'Janille'])],
            'view' => 'nullable|in:table,pipeline',
        ]);
        $owners = $this->owners();
        $assignee = $filters['assignee'] ?? null;
        $query = AccommodationMoveOut::with([
            'tenant:id,email,phone,first_name,family_name,display_name_override',
            'property:id,name,address',
        ])->orderByRaw('CASE WHEN closed_at IS NULL THEN 0 ELSE 1 END')->orderBy('agreement_ends_on')->orderBy('id');
        if (filled($filters['search'] ?? null)) {
            $search = '%'.trim($filters['search']).'%';
            $query->where(fn ($matches) => $matches->where('tenant_label', 'like', $search)
                ->orWhereHas('tenant', fn ($tenant) => $tenant->where(fn ($names) => $names
                    ->where('first_name', 'like', $search)->orWhere('family_name', 'like', $search)
                    ->orWhere('display_name_override', 'like', $search)->orWhere('email', 'like', $search)
                    ->orWhere('phone', 'like', $search)
                    ->orWhereRaw("CONCAT(COALESCE(first_name, ''), ' ', COALESCE(family_name, '')) LIKE ?", [$search]))));
        }
        if (filled($filters['stage'] ?? null)) {
            $query->where('stage', $filters['stage']);
        }
        if ($assignee === 'unassigned') {
            $query->whereNull('assignee_id');
        } elseif ($assignee === 'mine') {
            $query->where('assignee_id', $request->user()->id);
        } elseif ($assignee) {
            $ownerId = collect($owners)->firstWhere('name', $assignee)['id'] ?? null;
            $query->where('assignee_id', $ownerId ?? 0);
        }

        return inertia('portal/accommodation/MoveOutIndex', [
            'records' => $query->paginate(30)->withQueryString()->through(fn ($record) => $this->row($record)),
            'filters' => $filters, 'stages' => AccommodationMoveOut::STAGES, 'owners' => $owners,
            'automaticEmail' => Setting::get(AccommodationMoveOut::AUTOMATION_SETTING, false),
            'canManageAutomation' => $this->canManageAutomation($request, $owners),
        ]);
    }

    public function create(Request $request)
    {
        $this->authorizeStaff($request);
        $tenants = Tenant::active()->with('property:id,name,address')->whereNotNull('contract_end')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('accommodation_move_outs')
                ->whereColumn('tenant_id', 'accommodation_tenants.id')
                ->whereColumn('agreement_ends_on', 'accommodation_tenants.contract_end'))
            ->orderBy('contract_end')->orderBy('id')->get();

        return inertia('portal/accommodation/MoveOutStart', [
            'tenants' => $tenants->map(fn ($tenant) => [
                'id' => $tenant->id, 'name' => $tenant->display_name, 'property' => $tenant->property?->address ?: $tenant->property?->name,
                'ends' => $tenant->contract_end->toDateString(),
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeStaff($request);
        $data = $request->validate(['tenant_id' => 'required|integer']);
        $record = DB::transaction(function () use ($data, $request) {
            $tenant = Tenant::active()->whereNotNull('contract_end')->whereKey($data['tenant_id'])->lockForUpdate()->first();
            if (! $tenant || ! $tenant->property_id) {
                throw ValidationException::withMessages(['tenant_id' => 'Choose a current tenant with a property and agreement end date.']);
            }
            $existing = AccommodationMoveOut::where('tenant_id', $tenant->id)->whereDate('agreement_ends_on', $tenant->contract_end)->first();
            if ($existing) {
                return $existing;
            }
            $record = AccommodationMoveOut::create([
                'tenant_id' => $tenant->id,
                'tenant_label' => $tenant->display_name,
                'agreement_ends_on' => $tenant->contract_end->toDateString(),
                'property_id' => $tenant->property_id, 'stage' => 'check_in',
                'property_label' => $tenant->property->address ?: $tenant->property->name,
                'check_in_on' => $tenant->contract_end->toImmutable()->subMonthNoOverflow()->toDateString(),
            ]);
            $agreements = $this->agreements($record);
            if ($agreements->count() === 1) {
                $record->update(['agreement_id' => $agreements->first()->id]);
            }
            $record->events()->create(['action' => 'started', 'actor' => $request->user()->name,
                'details' => ['agreement_id' => $record->agreement_id]]);

            return $record;
        });

        return to_route('portal.accommodation.move-out.show', $record);
    }

    public function show(Request $request, AccommodationMoveOut $moveOut)
    {
        $this->authorizeStaff($request);
        $moveOut->load(['tenant', 'property']);
        $events = $moveOut->events()->orderBy('id')->get();
        $actions = collect(MoveOutWorkflow::ACTIONS)->filter(fn ($stages) => in_array($moveOut->stage, $stages, true))->keys();
        $done = $events->pluck('action');

        return inertia('portal/accommodation/MoveOutShow', [
            'record' => $this->row($moveOut) + [
                'tenantEmail' => $moveOut->tenant?->email, 'tenantArchived' => $moveOut->tenant?->trashed(),
                'agreementId' => $moveOut->agreement_id, 'bond' => $moveOut->tenant?->bond_paid_nzd,
                'checkInOn' => $moveOut->check_in_on->toDateString(),
                'reminderOn' => $moveOut->reminder_on?->toDateString(),
                'automaticEmail' => $moveOut->automatic_email,
            ],
            'today' => now('Pacific/Auckland')->toDateString(),
            'pipelineAutomation' => Setting::get(AccommodationMoveOut::AUTOMATION_SETTING, false),
            'stages' => AccommodationMoveOut::STAGES, 'owners' => $this->owners(),
            'agreements' => $this->agreements($moveOut)->map(fn ($agreement) => ['id' => $agreement->id, 'name' => $agreement->typeLabel()]),
            'actions' => $moveOut->closed_at || ! $moveOut->tenant ? [] : $actions->filter(fn ($action) => match ($action) {
                'renew' => $moveOut->decision !== 'renewal',
                'confirm_move' => true,
                'complete_renewal' => $moveOut->decision === 'renewal',
                'remind', 'inspect' => $moveOut->decision === 'move_out',
                'resolve' => ! $done->contains('resolve') && (bool) ($events->firstWhere('action', 'feedback')?->details['work_needed'] ?? false),
                'statement' => ! ($events->firstWhere('action', 'feedback')?->details['work_needed'] ?? false) || $done->contains('resolve'),
                'refund' => $done->contains('accept'),
                default => ! $done->contains($action),
            })->values(),
            'events' => $events->map(function ($event) use ($moveOut) {
                $details = $event->details;
                $details['files'] = collect($details['files'] ?? [])->map(fn ($file, $index) => [
                    'name' => $file['name'],
                    'url' => route('portal.accommodation.move-out.file', [$moveOut, $event->id, $index]),
                ]);

                return ['id' => $event->id, 'action' => $event->action, 'actor' => $event->actor,
                    'at' => $event->created_at->timezone('Pacific/Auckland')->format('j M Y, g:i a'), 'details' => $details];
            }),
        ]);
    }

    private function canManageAutomation(Request $request, array $owners): bool
    {
        return $request->user()->isAdmin() || in_array($request->user()->id, array_column($owners, 'id'), true);
    }

    public function automation(Request $request)
    {
        $this->authorizeStaff($request);
        abort_unless($this->canManageAutomation($request, $this->owners()), 403);
        $request->validate(['enabled' => 'required|boolean']);
        Setting::set(AccommodationMoveOut::AUTOMATION_SETTING, $request->boolean('enabled'), 'bool');

        return back();
    }

    public function recordAutomation(Request $request, AccommodationMoveOut $moveOut)
    {
        $this->authorizeStaff($request);
        $request->validate(['enabled' => 'required|boolean']);
        DB::transaction(function () use ($request, $moveOut) {
            $record = AccommodationMoveOut::whereKey($moveOut->id)->lockForUpdate()->firstOrFail();
            abort_if($record->closed_at, 422, 'This record is closed.');
            $enabled = $request->boolean('enabled');
            if ($enabled !== $record->automatic_email) {
                $record->update(['automatic_email' => $enabled]);
                $record->events()->create(['action' => 'automation', 'actor' => $request->user()->name,
                    'details' => ['enabled' => $enabled]]);
            }
        });

        return back();
    }

    public function assign(Request $request, AccommodationMoveOut $moveOut)
    {
        $this->authorizeStaff($request);
        $owners = collect($this->owners())->filter(fn ($owner) => $owner['id'])->keyBy('id');
        $data = $request->validate(['assignee_id' => ['present', 'nullable', 'integer', Rule::in($owners->keys()->all())]]);
        DB::transaction(function () use ($request, $moveOut, $data, $owners) {
            $record = AccommodationMoveOut::whereKey($moveOut->id)->lockForUpdate()->firstOrFail();
            abort_if($record->closed_at, 422, 'This record is closed.');
            $next = isset($data['assignee_id']) ? (int) $data['assignee_id'] : null;
            if ($record->assignee_id !== $next) {
                $from = $record->assignee_id;
                $record->update(['assignee_id' => $next]);
                $record->events()->create(['action' => 'assignment', 'actor' => $request->user()->name, 'details' => [
                    'from_owner' => $owners->get($from)['name'] ?? ($from ? User::find($from)?->name : null),
                    'to_owner' => $owners->get($next)['name'] ?? null,
                ]]);
            }
        });

        return back();
    }

    public function link(Request $request, AccommodationMoveOut $moveOut)
    {
        $this->authorizeStaff($request);
        $data = $request->validate(['agreement_id' => 'required|integer']);
        DB::transaction(function () use ($request, $moveOut, $data) {
            $record = AccommodationMoveOut::whereKey($moveOut->id)->lockForUpdate()->firstOrFail();
            abort_if($record->closed_at, 422, 'This record is closed.');
            $agreement = $this->agreements($record)->firstWhere('id', $data['agreement_id']);
            if (! $agreement) {
                throw ValidationException::withMessages(['agreement_id' => 'Choose a signed agreement linked to this tenant, property and term.']);
            }
            if ($record->agreement_id !== $agreement->id) {
                $record->update(['agreement_id' => $agreement->id]);
                $record->events()->create(['action' => 'agreement_linked', 'actor' => $request->user()->name,
                    'details' => ['agreement_id' => $agreement->id]]);
            }
        });

        return back();
    }

    public function advance(Request $request, AccommodationMoveOut $moveOut, MoveOutWorkflow $workflow)
    {
        $this->authorizeStaff($request);
        $action = $request->validate(['action' => ['required', Rule::in(array_keys(MoveOutWorkflow::ACTIONS))]])['action'];
        $today = now('Pacific/Auckland')->toDateString();
        $data = $request->validate(match ($action) {
            'renew' => ['notes' => 'nullable|string|max:4000'],
            'confirm_move' => ['move_out_on' => 'required|date_format:Y-m-d', 'notes' => 'nullable|string|max:4000'],
            'complete_renewal' => [
                'reference' => 'required|string|max:300',
                'files' => 'required|array|size:1',
                'files.*' => 'required|file|mimes:pdf|max:8192',
            ],
            'inspect' => [
                'notes' => 'required|string|max:4000', 'reference' => 'nullable|string|max:300',
                'files' => 'nullable|array|max:5',
                'files.*' => 'required|image|mimes:jpg,jpeg,png,webp|max:4096',
            ],
            'feedback' => [
                'notes' => 'required|string|max:4000', 'work_needed' => 'required|boolean',
                'return_at' => ['nullable', 'date_format:Y-m-d\TH:i', 'after:'.$today],
                'proposed_charge' => ['nullable', ...array_slice(self::MONEY, 1)],
            ],
            'resolve' => ['response' => 'required|string|max:4000', 'resolution' => 'required|string|max:4000'],
            'statement' => [
                'bond' => self::MONEY, 'deductions' => 'nullable|array|max:20',
                'deductions.*.item' => 'required|string|max:200', 'deductions.*.amount' => self::MONEY,
            ],
            'accept' => ['response' => 'required|string|max:4000'],
            'refund' => [
                'reference' => 'required|string|max:300', 'files' => 'nullable|array|max:1',
                'files.*' => 'required|file|mimes:pdf,jpg,jpeg,png|max:8192',
            ],
            default => [],
        });
        $stored = [];
        try {
            DB::transaction(function () use ($request, $moveOut, $workflow, $action, $data, &$stored) {
                $record = AccommodationMoveOut::whereKey($moveOut->id)->lockForUpdate()->firstOrFail();
                $workflow->apply($record, $action, $data, $request->user()->name, $stored);
            });
        } catch (Throwable $error) {
            Storage::disk('tenant_private')->delete($stored);
            throw $error;
        }

        return back()->with('success', 'Move-out record updated.');
    }

    public function file(Request $request, AccommodationMoveOut $moveOut, int $event, int $index)
    {
        $this->authorizeStaff($request);
        $file = $moveOut->events()->findOrFail($event)->details['files'][$index] ?? null;
        $disk = Storage::disk('tenant_private');
        abort_unless($file && $disk->exists($file['path']), 404);

        return $disk->download($file['path'], $file['name']);
    }

    private function row(AccommodationMoveOut $record): array
    {
        return [
            'id' => $record->id, 'tenantId' => $record->tenant_id, 'tenant' => $record->tenant?->display_name ?? $record->tenant_label,
            'propertyId' => $record->property_id, 'property' => $record->property?->address ?: ($record->property?->name ?? $record->property_label),
            'ends' => $record->agreement_ends_on->toDateString(), 'moveOut' => $record->move_out_on?->toDateString(),
            'stage' => $record->stage, 'assigneeId' => $record->assignee_id, 'decision' => $record->decision,
            'closed' => (bool) $record->closed_at,
        ];
    }

    private function agreements(AccommodationMoveOut $record)
    {
        if (! $record->tenant_id || ! $record->property_id) {
            return collect();
        }

        return AccommodationAgreement::where('tenant_id', $record->tenant_id)->where('property_id', $record->property_id)
            ->where('status', AccommodationAgreement::STATUS_SIGNED)->whereNotNull('signed_pdf_path')
            ->get()->filter(fn ($agreement) => data_get($agreement->data, 'tenancy.end_date') === $record->agreement_ends_on->toDateString()
                && Storage::disk('local')->exists($agreement->signed_pdf_path))->values();
    }
}
