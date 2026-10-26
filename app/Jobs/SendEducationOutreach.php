<?php

namespace App\Jobs;

use App\Models\EducationOutreach;
use App\Services\EducationOutreachService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class SendEducationOutreach implements ShouldQueue
{
    use Dispatchable,Queueable;

    public int $timeout = 70;

    public int $tries = 3;

    public function __construct(public int $deliveryId) {}

    public function handle(EducationOutreachService $service): void
    {
        if ($d = EducationOutreach::find($this->deliveryId)) {
            $service->process($d);
        }
    }
}
