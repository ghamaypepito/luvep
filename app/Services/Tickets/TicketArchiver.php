<?php

namespace App\Services\Tickets;

use App\Models\SystemTicket;
use App\Models\SystemTicketEvent;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TicketArchiver
{
    public function manually(int $id, User $actor, string $expectedStatus): bool
    {
        abort_unless($actor->is_active && $actor->isAdmin(), 403);

        return DB::transaction(function () use ($id, $actor, $expectedStatus) {
            $ticket = SystemTicket::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($ticket->archived_at) {
                return false;
            }
            if ($ticket->status !== $expectedStatus) {
                throw ValidationException::withMessages(['status' => 'This ticket changed. Refresh the list before trying again.']);
            }

            return $this->record($ticket, $actor->id, 'manual');
        });
    }

    public function whenDue(int $id, CarbonInterface $asOf): bool
    {
        return DB::transaction(function () use ($id, $asOf) {
            $ticket = SystemTicket::whereKey($id)->lockForUpdate()->first();
            // Recheck under the lock: a ticket could have been reopened since selection.
            if (! $ticket || ! $this->isDue($ticket, $asOf)) {
                return false;
            }

            return $this->record($ticket, null, 'two_month_retention');
        });
    }

    public function isDue(SystemTicket $ticket, CarbonInterface $asOf): bool
    {
        return ! $ticket->archived_at && $ticket->status === 'done' && $ticket->resolved_at
            && $ticket->resolved_at->copy()->timezone('Asia/Manila')->addMonthsNoOverflow(2)->lessThanOrEqualTo($asOf);
    }

    private function record(SystemTicket $ticket, ?int $actorId, string $reason): bool
    {
        $ticket->update(['archived_at' => now()]);
        SystemTicketEvent::create([
            'ticket_id' => $ticket->id, 'actor_id' => $actorId, 'kind' => 'archived',
            'from_status' => $ticket->status, 'to_status' => $ticket->status,
            'metadata' => ['reason' => $reason], 'created_at' => now(),
        ]);

        return true;
    }
}
