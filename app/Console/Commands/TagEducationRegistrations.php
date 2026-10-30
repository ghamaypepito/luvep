<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\EducationLeadReference;
use Illuminate\Console\Command;

class TagEducationRegistrations extends Command
{
    protected $signature = 'education:tag-registrations';

    protected $description = 'Idempotently tag contacts with saved Education programme registrations';

    public function handle(EducationLeadReference $references): int
    {
        $processed = 0;
        $added = 0;
        Lead::whereHas('educationEventRegistrations')->select('leads.id')
            ->chunkById(200, function ($leads) use ($references, &$processed, &$added) {
                foreach ($leads as $lead) {
                    $added += (int) $references->attach($lead);
                    $processed++;
                }
            });
        $this->info("Processed {$processed} registration contacts; added {$added} reference tags.");

        return self::SUCCESS;
    }
}
