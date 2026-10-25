<?php

namespace App\Mail;

use App\Models\EducationEventRegistration;
use App\Models\MessageLog;
use Throwable;

class EducationRegistrationWelcome extends TemplatedMessage
{
    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function failed(Throwable $exception): void
    {
        MessageLog::whereKey($this->logId)->update(['status' => MessageLog::STATUS_FAILED, 'failed_at' => now(), 'error_message' => mb_substr($exception->getMessage(), 0, 2000)]);
        EducationEventRegistration::where('welcome_log_id', $this->logId)->update(['outreach_completed_at' => null]);
    }
}
