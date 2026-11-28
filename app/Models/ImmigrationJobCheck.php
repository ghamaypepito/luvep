<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImmigrationJobCheck extends Model
{
    public const STAGES = [
        'intake' => 'Intake and onboarding',
        'advertising' => 'Advertising',
        'lmt_review' => 'Labour market review',
        'submitted' => 'Submitted to INZ',
        'approved' => 'Approved',
        'declined' => 'Declined',
        'withdrawn' => 'Withdrawn',
    ];

    protected $fillable = ['employer_id', 'assigned_to', 'created_by', 'role_title', 'stage', 'details'];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    public function employer(): BelongsTo
    {
        return $this->belongsTo(ImmigrationEmployer::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
