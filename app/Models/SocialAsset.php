<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SocialAsset extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = ['size' => 'integer', 'uploaded_by' => 'integer', 'source_version' => 'integer'];

    protected $hidden = ['file_path', 'sha256', 'upload_key'];
}
