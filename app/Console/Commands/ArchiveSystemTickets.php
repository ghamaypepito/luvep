<?php

namespace App\Console\Commands;

use App\Models\SystemTicket;
use App\Services\Tickets\TicketArchiver;
use Illuminate\Console\Command;

class ArchiveSystemTickets extends Command
{
    protected $signature = 'tickets:archive {--dry-run : Count eligible tickets without archiving}';

    protected $description = 'Archive Done tickets resolved at least two calendar months ago';

    public function handle(TicketArchiver $archiver): int
    {
        $asOf = now('Asia/Manila');
        // Month-end clamping must use completion + two months (Dec 31 is due Feb 28).
        // Select a broad candidate range, then check each exact anniversary.
        $candidates = SystemTicket::where('status', 'done')->whereNull('archived_at')->whereNotNull('resolved_at')
            ->where('resolved_at', '<=', $asOf->copy()->subMonthsNoOverflow(2)->endOfMonth()->utc());
        $count = 0;
        foreach ($candidates->select(['id', 'status', 'archived_at', 'resolved_at'])->lazyById(100) as $ticket) {
            $count += (int) ($this->option('dry-run') ? $archiver->isDue($ticket, $asOf) : $archiver->whenDue($ticket->id, $asOf));
        }
        $this->info($this->option('dry-run') ? "Eligible completed tickets: {$count}." : "Archived {$count} completed tickets.");

        return self::SUCCESS;
    }
}
