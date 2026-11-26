<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerActivityRecord extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['details' => 'array', 'activity_on' => 'date', 'follow_up_on' => 'date'];
    }

    public function version(): string
    {
        $raw = $this->getRawOriginal();
        ksort($raw);

        return hash('sha256', json_encode($raw, JSON_THROW_ON_ERROR));
    }
}
