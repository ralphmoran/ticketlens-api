<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertSetting extends Model
{
    protected $fillable = ['group_id', 'needs_response_enabled', 'aging_enabled'];

    protected function casts(): array
    {
        return [
            'needs_response_enabled' => 'boolean',
            'aging_enabled'          => 'boolean',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
