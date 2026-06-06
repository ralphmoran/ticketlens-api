<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAiProvider extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'provider',
        'api_key',
        'priority',
        'timeout_seconds',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'api_key'         => 'encrypted',
            'priority'        => 'integer',
            'timeout_seconds' => 'integer',
            'enabled'         => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Display-safe representation for API and Console responses (never exposes the raw key). */
    public function toDisplayArray(): array
    {
        return [
            'id'              => $this->id,
            'provider'        => $this->provider,
            'masked_key'      => $this->maskedKey(),
            'priority'        => $this->priority,
            'timeout_seconds' => $this->timeout_seconds,
            'enabled'         => $this->enabled,
        ];
    }

    /** Returns the key masked for display: gsk_***xyz (last 4 chars only). */
    public function maskedKey(): string
    {
        $key = $this->api_key;
        $visible = mb_substr($key, -4);
        $prefix = match ($this->provider) {
            'groq'      => 'gsk_',
            'anthropic' => 'sk-ant-',
            'openai'    => 'sk-',
            default     => '',
        };

        return "{$prefix}***{$visible}";
    }
}
