<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertSetting extends Model
{
    protected $fillable = [
        'group_id',
        'needs_response_enabled', 'needs_response_cooldown_hours',
        'aging_enabled',          'aging_cooldown_hours',
    ];

    protected function casts(): array
    {
        return [
            'needs_response_enabled'        => 'boolean',
            'needs_response_cooldown_hours' => 'integer',
            'aging_enabled'                 => 'boolean',
            'aging_cooldown_hours'          => 'integer',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
