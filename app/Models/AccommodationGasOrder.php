<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccommodationGasOrder extends Model
{
    protected $fillable = ['property_id', 'ordered_on', 'bottles', 'paid_cents', 'submission_key'];

    protected $casts = ['bottles' => 'integer', 'paid_cents' => 'integer'];
}
