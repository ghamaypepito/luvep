<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccommodationAgreementEmail extends Model
{
    protected $fillable = ['kind', 'status', 'sent_at'];

    protected $casts = ['sent_at' => 'datetime'];
}
