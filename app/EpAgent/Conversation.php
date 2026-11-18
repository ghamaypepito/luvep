<?php

namespace App\EpAgent;

use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    protected $table = 'ep_conversations';

    protected $guarded = ['id'];
}
