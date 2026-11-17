<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EducationNoteActivity extends Model
{
    protected $fillable = ['note_id', 'lead_id', 'user_id', 'author_name', 'activity', 'is_reply', 'posted_at'];

    protected $casts = ['is_reply' => 'boolean', 'posted_at' => 'immutable_datetime'];

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function note()
    {
        return $this->belongsTo(LeadNote::class);
    }
}
