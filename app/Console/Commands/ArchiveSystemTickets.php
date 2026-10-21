<?php

namespace App\Console\Commands;

use App\Models\SystemTicket;
use Illuminate\Console\Command;

class ArchiveSystemTickets extends Command
{
    protected $signature = 'tickets:archive';

    protected $description = 'Archive Done tickets resolved at least three calendar months ago';

    public function handle(): int
    {
        $count = SystemTicket::where('status', 'done')->whereNull('archived_at')->whereNotNull('resolved_at')->where('resolved_at', '<=', now('Asia/Manila')->subMonthsNoOverflow(3)->utc())->update(['archived_at' => now()]);
        $this->info("Archived {$count} completed tickets.");

        return self::SUCCESS;
    }
}
