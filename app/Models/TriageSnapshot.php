<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TriageSnapshot extends Model
{
    protected $fillable = [
        'user_id',
        'license_key_hash',
        'profile',
        'tickets',
        'git_branches',
        'ticket_count',
        'captured_at',
        'share_token',
        'share_expires_at',
    ];

    protected $casts = [
        'tickets'          => 'array',
        'git_branches'     => 'array',
        'captured_at'      => 'datetime',
        'share_expires_at' => 'datetime',
    ];

    public static function generateToken(): string
    {
        return (string) Str::uuid();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hashKey(string $key): string
    {
        return hash('sha256', $key);
    }
}
