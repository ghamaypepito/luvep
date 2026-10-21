<?php

namespace App\Http\Controllers;

use App\Models\SystemTicket;
use App\Models\SystemTicketEvent;
use App\Models\User;
use App\Notifications\TicketSubmitted;
use App\Notifications\TicketUpdated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SystemTicketController extends Controller
{
    /**
     * Any staff member (not an external lead) raises a request about the
     * system. Admins / super-admins are notified.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_if(! $user->is_active || in_array($user->role, ['lead', 'revoked_lead']), 403, 'Only staff can submit tickets.');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:5000'],
            'category' => ['required', Rule::in(SystemTicket::CATEGORIES)],
            'priority' => ['nullable', Rule::in(SystemTicket::PRIORITIES)],
            // Up to 5 screenshots so the admin can see the exact screen.
            'images' => ['nullable', 'array', 'max:5'],
            'images.*' => ['image', 'mimes:jpeg,jpg,png,webp,gif', 'max:5120'],
        ]);

        $ticket = SystemTicket::create([
            'title' => $data['title'],
            'description' => $data['description'],
            'category' => $data['category'],
            'priority' => $data['priority'] ?? 'normal',
            'status' => 'open',
            'tracking_started_at' => now(),
            'waiting_on' => 'support',
            'submitted_by' => $user->id,
            'department' => $user->role,
        ]);

        // Store screenshots on the private disk (they can contain client data);
        // they're served back through the auth-gated image() stream below.
        if ($request->hasFile('images')) {
            $paths = [];
            foreach ($request->file('images') as $img) {
                $paths[] = $img->store("system-tickets/{$ticket->id}", 'local');
            }
            $ticket->update(['images' => $paths]);
        }

        SystemTicketEvent::create(['ticket_id' => $ticket->id, 'actor_id' => $user->id, 'kind' => 'created', 'to_status' => 'open', 'created_at' => now()]);
        Notification::route('mail', 'support@luvep.com')->notify(new \App\Notifications\SupportTicketCreated($ticket));

        $recipients = User::where('is_active', true)->whereIn('role', [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN])->get();
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new TicketSubmitted($ticket, $user->name));
        }

        return back()->with('success', "Ticket {$ticket->ticket_ref} submitted — thank you!");
    }

    /**
     * Staff-facing list of the tickets the current user has raised, with
     * status + the admin's reply. Renders inside the user's own portal
     * layout (app.jsx auto-wraps by the component-name prefix). Admins /
     * super-admins don't have a personal list — they get the full board.
     */
    public function myTickets(Request $request)
    {
        $user = $request->user();
        abort_if(! $user->is_active || in_array($user->role, ['lead', 'revoked_lead']), 403, 'Only staff can view tickets.');

        if (in_array($user->role, [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN], true)) {
            return redirect('/admin/system-tickets');
        }

        $mine = SystemTicket::where('submitted_by', $user->id);

        $tickets = (clone $mine)
            ->when($request->query('tab') === 'archived', fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->whereNull('archived_at'))
            ->with(['resolver:id,name', 'assignee:id,name'])
            ->orderByRaw("CASE WHEN status IN ('done','declined') THEN 1 ELSE 0 END")
            ->orderByDesc('created_at')
            ->paginate(15)->withQueryString()
            ->through(fn (SystemTicket $t) => $this->serialize($t));

        return inertia($this->myTicketsPage($user), [
            'tickets' => $tickets,
            'filters' => ['tab' => $request->query('tab', 'active')],
            'counts' => [
                'open' => (clone $mine)->open()->count(),
                'total' => (clone $mine)->count(),
            ],
            'meta' => [
                'categories' => SystemTicket::CATEGORIES,
                'priorities' => SystemTicket::PRIORITIES,
            ],
        ]);
    }

    /** Pick the per-portal page so the shared list wraps in the right layout. */
    private function myTicketsPage($user): string
    {
        return match ($user->role) {
            'sales' => 'portal/sales/Tickets',
            'education' => 'portal/education/Tickets',
            'english' => 'portal/english/Tickets',
            'accommodation' => 'portal/accommodation/Tickets',
            'immigration', 'immigration_manager', 'immigration_adviser' => 'portal/immigration/Tickets',
            default => 'portal/sales/Tickets',
        };
    }

    /** Admin / super-admin triage board of every ticket. */
    public function adminIndex(Request $request)
    {
        $status = $request->query('status');
        $dept = $request->query('department');
        $search = trim((string) $request->query('search', ''));

        $base = SystemTicket::query()
            ->with(['submitter:id,name,role', 'resolver:id,name', 'assignee:id,name'])
            ->when($request->query('tab') === 'archived', fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->whereNull('archived_at'))
            ->when($status && in_array($status, SystemTicket::STATUSES, true), fn ($q) => $q->where('status', $status))
            ->when($dept, fn ($q) => $q->where('department', $dept))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('title', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")
                ->orWhere('ticket_ref', 'like', "%{$search}%")));

        $tickets = (clone $base)
            ->orderByRaw("CASE WHEN status IN ('done','declined') THEN 1 ELSE 0 END")
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (SystemTicket $t) => $this->serialize($t));

        return inertia('admin/SystemTickets', [
            'tickets' => $tickets,
            'assignees' => User::where('is_active', true)->whereIn('role', [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN])->orderBy('name')->get(['id', 'name']),
            'filters' => ['tab' => $request->query('tab', 'active'), 'status' => $status, 'department' => $dept, 'search' => $search],
            'meta' => [
                'categories' => SystemTicket::CATEGORIES,
                'priorities' => SystemTicket::PRIORITIES,
                'statuses' => SystemTicket::STATUSES,
            ],
            'counts' => ['open' => SystemTicket::open()->count()],
        ]);
    }

    public function adminUpdate(Request $request, $id)
    {
        abort_unless($request->user()->is_active && $request->user()->isAdmin(), 403);
        $data = $request->validate([
            'status' => ['required', Rule::in(SystemTicket::STATUSES)],
            'admin_response' => ['nullable', 'string', 'max:5000'],
            'assigned_to' => ['nullable', Rule::exists('users', 'id')->where(fn ($q) => $q->where('is_active', true)->whereNull('deleted_at')->whereIn('role', [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN]))],
            'waiting_on' => ['nullable', Rule::in(['support', 'requester', 'external'])],
            'topic' => ['nullable', 'string', 'max:100'],
            'response_target_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
        ]);
        $ticket = DB::transaction(function () use ($id, $request, $data) {
            $ticket = SystemTicket::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($ticket->archived_at, 422, 'Archived tickets are read-only.');
            $oldStatus = $ticket->status;
            if ($data['status'] === 'done' && $oldStatus !== 'done') {
                foreach (['issue_summary', 'root_cause', 'resolution', 'verification', 'documentation_summary'] as $field) {
                    if (! trim($ticket->resolution_report[$field] ?? '')) {
                        throw \Illuminate\Validation\ValidationException::withMessages(['status' => 'Complete and save the resolution report before marking Done.']);
                    }
                }
            }
            $event = fn ($kind, $metadata = []) => SystemTicketEvent::create(['ticket_id' => $ticket->id, 'actor_id' => $request->user()->id, 'kind' => $kind, 'from_status' => $oldStatus, 'to_status' => $data['status'], 'metadata' => $metadata, 'created_at' => now()]);
            if ($oldStatus !== $data['status']) {
                $event('status_changed');
                $data['resolved_at'] = in_array($data['status'], ['done', 'declined']) ? now() : null;
                $data['resolved_by'] = in_array($data['status'], ['done', 'declined']) ? $request->user()->id : null;
                if ($data['status'] !== 'done') {
                    $data['archived_at'] = null;
                }
            }
            if (array_key_exists('assigned_to', $data) && (string) $data['assigned_to'] !== (string) $ticket->assigned_to) {
                $event('assigned', ['from' => $ticket->assigned_to, 'to' => $data['assigned_to']]);
                $data['assigned_at'] = $ticket->assigned_at ?: ($data['assigned_to'] ? now() : null);
            }
            if (array_key_exists('waiting_on', $data) && $data['waiting_on'] !== $ticket->waiting_on) {
                $event('waiting_changed', ['from' => $ticket->waiting_on, 'to' => $data['waiting_on']]);
            }
            if (isset($data['admin_response']) && trim($data['admin_response']) !== '' && $data['admin_response'] !== $ticket->admin_response) {
                \App\Models\SystemTicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => $request->user()->id, 'body' => $data['admin_response'], 'is_support' => true]);
            }
            $ticket->fill($data)->save();

            return $ticket;
        });

        // Tell the department who raised it.
        if ($ticket->submitter) {
            $ticket->submitter->notify(new TicketUpdated($ticket->fresh()));
        }

        return back()->with('success', "Ticket {$ticket->ticket_ref} updated.");
    }

    /**
     * Stream one of a ticket's screenshots from the private disk. Gated to the
     * admins/super-admins (the triagers) and the staff member who raised it —
     * EnsurePortalAccess is role-level only, so ownership is re-checked here.
     */
    public function image(Request $request, int $id, int $index)
    {
        $user = $request->user();
        abort_if(! $user->is_active || in_array($user->role, ['lead', 'revoked_lead']), 403);

        $ticket = SystemTicket::findOrFail($id);
        abort_unless(
            $user->isAtLeast('admin') || $ticket->submitted_by === $user->id,
            403,
        );

        $images = $ticket->images ?? [];
        abort_unless(isset($images[$index]) && Storage::disk('local')->exists($images[$index]), 404);

        return response()->file(Storage::disk('local')->path($images[$index]));
    }

    private function serialize(SystemTicket $t): array
    {
        return [
            'assigned_to' => $t->assigned_to,
            'assignee' => $t->assignee?->name,
            'waiting_on' => $t->waiting_on,
            'topic' => $t->topic,
            'archived_at' => $t->archived_at?->toIso8601String(),
            'tracking_started_at' => $t->tracking_started_at?->toIso8601String(),
            'resolution_report' => $t->resolution_report,
            'response_target_hours' => $t->response_target_hours,
            'id' => $t->id,
            'ticket_ref' => $t->ticket_ref,
            'title' => $t->title,
            'description' => $t->description,
            'category' => $t->category,
            'priority' => $t->priority,
            'status' => $t->status,
            'department' => $t->department,
            'submitter' => optional($t->submitter)->name,
            'admin_response' => $t->admin_response,
            'resolver' => optional($t->resolver)->name,
            // Auth-gated stream URLs for each screenshot (never the raw path).
            'images' => collect($t->images ?? [])
                ->keys()
                ->map(fn ($i) => url("/system-tickets/{$t->id}/images/{$i}"))
                ->all(),
            'created_at' => optional($t->created_at)?->toIso8601String(),
            'resolved_at' => optional($t->resolved_at)?->toIso8601String(),
        ];
    }
}
