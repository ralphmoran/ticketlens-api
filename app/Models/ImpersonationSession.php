<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ImpersonationSession extends Model
{
    public $timestamps = false;

    protected $fillable = ['actor_id', 'target_user_id', 'started_at', 'ended_at', 'ip_address'];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at'   => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }
}
