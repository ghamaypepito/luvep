<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrDtrAcknowledgement extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['snapshot' => 'array', 'changes' => 'array', 'version' => 'integer', 'report_version' => 'integer', 'submitted_at' => 'datetime', 'decided_at' => 'datetime'];

    public function entry()
    {
        return $this->belongsTo(DtrEntry::class, 'dtr_entry_id');
    }

    public function head()
    {
        return $this->belongsTo(User::class, 'head_user_id')->withTrashed();
    }

    protected static function booted(): void
    {
        static::updating(function (self $acknowledgement) {
            if ($acknowledgement->isDirty(['dtr_entry_id', 'head_user_id', 'report_version', 'reason', 'snapshot', 'changes', 'submitted_at'])) {
                throw new \LogicException('Submitted report content is immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Report acknowledgements are immutable.'));
    }
}
