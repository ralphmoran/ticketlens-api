<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TriageSnapshot extends Model
{
    protected $fillable = [
        'user_id',
        'license_key_hash',
        'profile',
        'tickets',
        'ticket_count',
        'captured_at',
    ];

    protected $casts = [
        'tickets'     => 'array',
        'captured_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hashKey(string $key): string
    {
        return hash('sha256', $key);
    }
}
