<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrDocument extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['archived_at' => 'datetime', 'submitted_by' => 'integer'];

    protected static function booted(): void
    {
        static::updating(function ($document) {
            if ($document->isDirty(['hr_employee_id', 'classification', 'submitted_by'])) {
                throw new \LogicException('Document ownership, classification and provenance are immutable.');
            }
        });
    }

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'hr_employee_id');
    }

    public function versions()
    {
        return $this->hasMany(HrDocumentVersion::class);
    }
}
