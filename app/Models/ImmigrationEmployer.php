<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImmigrationEmployer extends Model
{
    protected $fillable = [
        'legal_name', 'trading_name', 'employer_identifier', 'business_activity',
        'accreditation_type', 'accreditation_expires_on', 'approved_positions',
    ];

    protected function casts(): array
    {
        return ['accreditation_expires_on' => 'date:Y-m-d'];
    }
}
