<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlackIntegration extends Model
{
    protected $fillable = [
        'group_id', 'connected_by', 'workspace_id', 'workspace_name',
        'bot_token', 'channel_id', 'channel_name',
    ];

    protected function casts(): array
    {
        return ['bot_token' => 'encrypted'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function connector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }
}
