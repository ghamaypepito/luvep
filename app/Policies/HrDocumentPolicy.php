<?php

namespace App\Policies;

use App\Models\HrDocument;
use App\Models\User;
use App\Services\Hr\HrAccess;

class HrDocumentPolicy
{
    public const CLASSES = ['employee_shared', 'hr_private', 'medical', 'compensation', 'case'];

    public function manageClass(User $actor, string $classification): bool
    {
        return in_array($classification, self::CLASSES, true) && app(HrAccess::class)->allows($actor, match ($classification) {
            'medical' => 'documents.medical','compensation' => 'documents.compensation','case' => 'documents.case',default => 'documents.manage'
        });
    }

    public function manage(User $actor, HrDocument $document): bool
    {
        return $this->manageClass($actor, $document->classification);
    }
}
