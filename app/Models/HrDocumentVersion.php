<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrDocumentVersion extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['storage_key', 'sha256'];

    protected $casts = ['version' => 'integer', 'size' => 'integer', 'created_at' => 'datetime', 'released_at' => 'datetime'];

    public function document()
    {
        return $this->belongsTo(HrDocument::class, 'hr_document_id');
    }

    protected static function booted(): void
    {
        static::updating(function ($v) {
            if (array_diff(array_keys($v->getDirty()), ['released_at', 'released_by'])) {
                throw new \LogicException('Document versions are immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Document versions cannot be deleted.'));
    }
}
