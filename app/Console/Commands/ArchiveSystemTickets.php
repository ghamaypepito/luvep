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
        $cutoff = now('Asia/Manila')->subMonthsNoOverflow(2)->utc();
        $eligible = SystemTicket::where('status', 'done')->whereNull('archived_at')->whereNotNull('resolved_at')->where('resolved_at', '<=', $cutoff);
        if ($this->option('dry-run')) {
            $this->info('Eligible completed tickets: '.$eligible->count().'.');

            return self::SUCCESS;
        }
        $count = 0;
        foreach ($eligible->select('id')->lazyById(100) as $ticket) {
            $count += (int) $archiver->completedBefore($ticket->id, $cutoff);
        }
        $this->info("Archived {$count} completed tickets.");

        return self::SUCCESS;
    }
}
