<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PropertyRoomVerification extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['total_rooms' => 'integer', 'occupied' => 'integer', 'vacant' => 'integer', 'unavailable' => 'integer'];

    public static function available(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable('property_room_verifications');
    }
}
