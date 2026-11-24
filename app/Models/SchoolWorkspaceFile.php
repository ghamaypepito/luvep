<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchoolWorkspaceFile extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['path'];
}
