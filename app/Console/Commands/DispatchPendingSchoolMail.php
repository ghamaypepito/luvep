<?php

namespace App\Console\Commands;

use App\Jobs\SendEducationSchoolMail;
use App\Models\EducationSchoolMailRecipient;
use Illuminate\Console\Command;

class DispatchPendingSchoolMail extends Command
{
    protected $signature = 'education:dispatch-school-mail {--mailing= : Restrict recovery to one mailing ID}';

    protected $description = 'Requeue only untouched pending school-mail recipients, never failed or uncertain sends';

    public function handle(): int
    {
        if ($this->option('mailing') !== null && ! ctype_digit((string) $this->option('mailing'))) {
            $this->error('Use a numeric mailing ID.');

            return self::FAILURE;
        }
        $sent = 0;
        $failed = 0;
        EducationSchoolMailRecipient::where('status', 'pending')->when($this->option('mailing'), fn ($q, $id) => $q->where('mailing_id', $id))
            ->chunkById(100, function ($rows) use (&$sent, &$failed) {
                foreach ($rows as $row) {
                    SendEducationSchoolMail::enqueue($row->id) ? $sent++ : $failed++;
                }
            });
        $this->info("{$sent} pending recipient jobs dispatched; {$failed} dispatch failures.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
