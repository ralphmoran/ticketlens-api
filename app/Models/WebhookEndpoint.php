<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Outbound webhook target for the public API. Payloads are signed with `secret`
 * and carry metadata only; integrators read ticket data through /v1/public.
 */
class WebhookEndpoint extends Model
{
    public const MAX_PER_USER = 5;

    /** Consecutive exhausted deliveries before the endpoint is switched off. */
    public const DISABLE_AFTER_FAILURES = 10;

    protected $fillable = ['user_id', 'url', 'secret', 'failure_count', 'last_delivered_at', 'disabled_at'];

    protected $casts = [
        'secret'            => 'encrypted',
        'last_delivered_at' => 'datetime',
        'disabled_at'       => 'datetime',
    ];

    protected $hidden = ['secret'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('disabled_at');
    }

    public function recordSuccess(): void
    {
        $this->update(['failure_count' => 0, 'last_delivered_at' => now()]);
    }

    /** Atomic: concurrent failed deliveries must not undercount, and a stale model must not re-enable. */
    public function recordFailure(): void
    {
        $query = static::whereKey($this->id);
        $query->increment('failure_count');

        static::whereKey($this->id)
            ->where('failure_count', '>=', self::DISABLE_AFTER_FAILURES)
            ->whereNull('disabled_at')
            ->update(['disabled_at' => now()]);

        $this->refresh();
    }
}
