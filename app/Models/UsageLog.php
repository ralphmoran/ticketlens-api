<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsageLog extends Model
{
    public $timestamps = false;
    protected $fillable = ['user_id', 'action', 'ticket_key', 'tokens_used'];
    protected $casts = ['created_at' => 'datetime'];
}
