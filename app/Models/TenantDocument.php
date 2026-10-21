<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantDocument extends Model
{
    public const CATEGORIES = ['passport', 'tenancy_agreement', 'inspection_report', 'other'];

    protected $fillable = ['category', 'name', 'path', 'size', 'uploaded_by'];

    protected $hidden = ['path'];

    public static function authorizeStaff(User $user): void
    {
        abort_unless($user->isAdmin() || $user->hasRole('accommodation'), 403);
    }
}
