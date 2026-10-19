<?php

namespace App\Services\Hr;

use App\Models\HrEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class HrEvents
{
    public function record(string $type, Model $subject, User $actor, string $deduplicationKey): HrEvent
    {
        return HrEvent::firstOrCreate(['deduplication_key' => $deduplicationKey], ['type' => $type, 'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey(), 'actor_id' => $actor->id, 'created_at' => now()]);
    }
}
