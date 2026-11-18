<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

class HrEmployee extends Model
{
    protected $fillable = ['user_id', 'employee_number', 'status', 'department', 'position', 'office', 'employment_type', 'employment_start', 'employment_end', 'manager_user_id', 'reviewer_user_id', 'emergency_contact', 'version'];

    protected $hidden = ['emergency_contact'];

    protected $casts = ['emergency_contact' => 'encrypted', 'employment_start' => 'date', 'employment_end' => 'date', 'version' => 'integer'];

    protected static function booted(): void
    {
        static::saving(function (self $employee) {
            $user = User::find($employee->user_id);
            if (! $user || ! in_array($user->role, config('hr.internal_roles'), true)) {
                throw ValidationException::withMessages(['user_id' => 'Only internal staff can be enrolled.']);
            }
            if (! in_array($employee->status, ['candidate', 'active', 'archived'], true)) {
                throw ValidationException::withMessages(['status' => 'Choose a valid employment status.']);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    public function schedule(): HasOne
    {
        return $this->hasOne(DtrSetting::class, 'user_id', 'user_id');
    }
}
