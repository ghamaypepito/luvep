<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchoolWorkspaceRecord extends Model
{
    public const STATUSES = [
        'enrolment' => ['Enrolled', 'Currently Studying', 'Completed', 'Deferred', 'Withdrawn'],
        'communication' => ['Pending', 'Completed', 'Awaiting Response'],
        'intake' => ['Open', 'Closing Soon', 'Closed', 'Fully Booked', 'Postponed'],
    ];

    protected $guarded = ['id'];

    protected $casts = ['data' => 'array', 'version' => 'integer'];

    public static function available(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable('school_workspace_records')
            && \Illuminate\Support\Facades\Schema::hasTable('school_workspace_files');
    }

    public function files()
    {
        return $this->hasMany(SchoolWorkspaceFile::class);
    }
}
