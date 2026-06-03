<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowRule extends Model
{
    protected $fillable = ['group_id', 'type', 'config', 'enabled'];

    protected function casts(): array
    {
        return [
            'config'  => 'array',
            'enabled' => 'boolean',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
