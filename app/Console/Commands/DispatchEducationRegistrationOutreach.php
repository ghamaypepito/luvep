<?php

namespace App\Console\Commands;

use App\Models\EducationEventRegistration;
use App\Services\EducationRegistrationDelivery;
use Illuminate\Console\Command;

class DispatchEducationRegistrationOutreach extends Command
{
    protected $signature = 'education:dispatch-registration-outreach';

    protected $description = 'Recover pending Education registration welcome emails and staff notifications.';

    public function handle(EducationRegistrationDelivery $delivery): int
    {
        EducationEventRegistration::whereNull('outreach_completed_at')->chunkById(100, function ($registrations) use ($delivery) {
            foreach ($registrations as $registration) {
                try {
                    $delivery->deliver($registration);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        });

        return self::SUCCESS;
    }
}
