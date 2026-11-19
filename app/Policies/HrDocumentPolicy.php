<?php

namespace App\Policies;

use App\Models\HrDocument;
use App\Models\HrEmployee;
use App\Models\User;
use App\Services\Hr\HrAccess;

class HrDocumentPolicy
{
    public const CLASSES = ['employee_shared', 'hr_private', 'medical', 'compensation', 'case'];

    public function manageClass(User $actor, string $classification): bool
    {
        if ($classification === 'employee_shared' && $actor->isAdmin() && app(HrAccess::class)->internal($actor)) {
            return true;
        }

        return in_array($classification, self::CLASSES, true) && app(HrAccess::class)->allows($actor, match ($classification) {
            'medical' => 'documents.medical','compensation' => 'documents.compensation','case' => 'documents.case',default => 'documents.manage'
        });
    }

    public function selfUpload(User $actor, HrEmployee $employee): bool
    {
        return app(HrAccess::class)->employee($actor)?->id === $employee->id;
    }

    public function upload(User $actor, HrDocument $document): bool
    {
        return $this->manage($actor, $document) || ($this->selfUpload($actor, $document->employee)
            && $document->classification === 'employee_shared' && $document->submitted_by === $actor->id && ! $document->archived_at);
    }

    public function manage(User $actor, HrDocument $document): bool
    {
        return $this->manageClass($actor, $document->classification);
    }
}
