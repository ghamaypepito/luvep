<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MediaFile extends Model
{
    protected $guarded = [];

    protected $hidden = ['file_path', 'sha256', 'actor_key', 'upload_key'];

    protected $casts = ['size' => 'integer'];

    public function folder()
    {
        return $this->belongsTo(MediaFolder::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function documents()
    {
        return $this->hasMany(LeadDocument::class, 'media_file_id');
    }
}
