<?php

namespace App\Http\Controllers;

use App\Models\SystemTicket;
use App\Models\User;
use App\Notifications\TicketMessageReceived;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class TicketConversationController extends Controller
{
    private function authorizeTicket(Request $request, SystemTicket $ticket, bool $adminOnly = false): void
    {
        $user = $request->user();
        abort_unless($user && $user->is_active && ! in_array($user->role, [User::ROLE_LEAD, User::ROLE_REVOKED_LEAD], true), 403);
        abort_unless($user->isAdmin() || (! $adminOnly && (int) $ticket->submitted_by === (int) $user->id), 403);
    }

    public function show(Request $request, SystemTicket $ticket)
    {
        $this->authorizeTicket($request, $ticket);
        $messages = $ticket->messages()->with('user:id,name')->orderByDesc('id')->paginate(30);
        $messages->setCollection($messages->getCollection()->reverse()->values());

        return response()->json([
            'ticket' => $ticket->only(['id', 'ticket_ref', 'title', 'status', 'archived_at']),
            'messages' => $messages,
            'resolution_report' => $ticket->resolution_report,
            'documentation' => $ticket->documentation_published_at ? ['title' => $ticket->documentation_title, 'body' => $ticket->documentation_body, 'published_at' => $ticket->documentation_published_at->toIso8601String()] : null,
        ]);
    }

    public function storeMessage(Request $request, SystemTicket $ticket)
    {
        $this->authorizeTicket($request, $ticket);
        $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);
        $user = $request->user();
        $message = DB::transaction(function () use ($ticket, $user, $data) {
            $locked = SystemTicket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->archived_at, 422, 'Archived tickets are read-only.');
            $message = $locked->messages()->create(['user_id' => $user->id, 'body' => $data['body'], 'is_support' => $user->isAdmin()]);
            if ($user->isAdmin() && (int) $locked->submitted_by !== (int) $user->id && ! $locked->events()->where('kind', 'first_response')->exists()) {
                $locked->events()->create(['actor_id' => $user->id, 'kind' => 'first_response', 'metadata' => ['message_id' => $message->id]]);
            }

            return $message;
        });
        $recipients = $user->isAdmin()
            ? User::whereKey($ticket->submitted_by)->where('id', '!=', $user->id)->where('is_active', true)->get()
            : User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN])->where('is_active', true)->where('id', '!=', $user->id)->get();
        Notification::send($recipients, new TicketMessageReceived($ticket));

        return response()->json(['message' => $message->load('user:id,name')], 201);
    }

    public function saveResolution(Request $request, SystemTicket $ticket)
    {
        $this->authorizeTicket($request, $ticket, true);
        $rules = ['report' => ['required', 'array:issue_summary,root_cause,resolution,verification,references,follow_up,documentation_summary']];
        foreach (['issue_summary', 'root_cause', 'resolution', 'verification', 'documentation_summary'] as $field) {
            $rules['report.'.$field] = ['required', 'string', 'max:10000'];
        }
        foreach (['references', 'follow_up'] as $field) {
            $rules['report.'.$field] = ['nullable', 'string', 'max:10000'];
        }
        $data = $request->validate($rules);
        DB::transaction(function () use ($ticket, $request, $data) {
            $locked = SystemTicket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->archived_at, 422, 'Archived tickets are read-only.');
            $locked->update(['resolution_report' => $data['report']]);
            $locked->events()->create(['actor_id' => $request->user()->id, 'kind' => 'report_saved']);
        });

        return $request->wantsJson() ? response()->json(['success' => true]) : back()->with('success', 'Resolution report saved.');
    }

    public function downloadResolution(Request $request, SystemTicket $ticket)
    {
        $this->authorizeTicket($request, $ticket);
        abort_unless($ticket->resolution_report, 404);
        $markdown = "# Resolution report: {$ticket->ticket_ref}\n\n";
        $ticket->load(['assignee:id,name', 'resolver:id,name']);
        $metadata = [
            'Title' => $ticket->title,
            'Assignee' => $ticket->assignee?->name ?? 'Unassigned',
            'Resolver' => $ticket->resolver?->name ?? 'Not resolved',
            'Created at' => $ticket->created_at?->toIso8601String() ?? 'Unknown',
            'Resolved at' => $ticket->resolved_at?->toIso8601String() ?? 'Not resolved',
        ];
        foreach ($metadata as $label => $value) {
            $markdown .= $label.': '.$value."\n\n";
        }
        foreach (['issue_summary', 'root_cause', 'resolution', 'verification', 'references', 'follow_up', 'documentation_summary'] as $field) {
            $markdown .= '## '.ucwords(str_replace('_', ' ', $field))."\n\n".($ticket->resolution_report[$field] ?? '—')."\n\n";
        }

        return response()->streamDownload(fn () => print ($markdown), "ticket-{$ticket->id}-resolution.md", ['Content-Type' => 'text/markdown; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function publishDocumentation(Request $request, SystemTicket $ticket)
    {
        $this->authorizeTicket($request, $ticket, true);
        $data = $request->validate(['title' => ['required', 'string', 'max:200'], 'body' => ['required', 'string', 'max:20000'], 'reviewed' => ['required', 'accepted']]);
        // Publication contains only the administrator's separately reviewed text.
        // Consumers render this as plain text, never as HTML.
        DB::transaction(function () use ($ticket, $request, $data) {
            $locked = SystemTicket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->archived_at, 422, 'Archived tickets are read-only.');
            $locked->update(['documentation_title' => $data['title'], 'documentation_body' => $data['body'], 'documentation_published_at' => now()]);
            $locked->events()->create(['actor_id' => $request->user()->id, 'kind' => 'documentation_published']);
        });

        return $request->wantsJson() ? response()->json(['success' => true]) : back()->with('success', 'Documentation published.');
    }

    public function documentation(Request $request)
    {
        abort_unless($request->user()?->is_active && $request->user()->isAdmin(), 403);

        return inertia('admin/TicketDocumentation', ['articles' => SystemTicket::whereNotNull('documentation_published_at')->orderByDesc('documentation_published_at')->paginate(20, ['id', 'documentation_title', 'documentation_body', 'documentation_published_at'])]);
    }
}
