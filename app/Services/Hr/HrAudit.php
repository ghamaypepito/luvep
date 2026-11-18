<?php

namespace App\Services\Hr;

use App\Models\HrAuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class HrAudit
{
    public function record(User $actor, string $action, Model $subject, array $safeMetadata = []): void
    {
        $metadata = array_intersect_key($safeMetadata, array_flip(['fields', 'version', 'employee_id', 'previous_status', 'status', 'grants', 'previous_grants']));
        HrAuditEvent::create(['actor_id' => $actor->id, 'action' => $action, 'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey(), 'metadata' => $metadata, 'created_at' => now()]);
    }
}
