<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ErrorReport extends Model
{
    public const UPDATED_AT = null; // append-only

    protected $fillable = [
        'cli_version',
        'os',
        'command',
        'message',
        'stack_trace',
        'profile_tier',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
