<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecallSettings extends Model
{
    /**
     * Platform defaults applied when a group has no override row —
     * matches the CLI's hardcoded fallback constants (recall-settings-sync.mjs)
     * so a solo/no-team user's effective values never silently diverge from
     * what a manager sees as the starting point before customizing.
     */
    public const DEFAULTS = [
        'flush_cooldown_ms' => 900_000,       // 15 minutes
        'timeout_ms'        => 4_000,         // 4 seconds
        'max_queue_size'    => 200,
        'max_entry_age_ms'  => 2_592_000_000, // 30 days
        'recall_strictness' => 'balanced',
    ];

    /** Inclusive [min, max] bounds enforced both server-side (validation) and CLI-side (clamp). */
    public const BOUNDS = [
        'flush_cooldown_ms' => [60_000, 86_400_000],       // 1m .. 24h
        'timeout_ms'        => [1_000, 30_000],            // 1s .. 30s
        'max_queue_size'    => [10, 2_000],
        'max_entry_age_ms'  => [3_600_000, 7_776_000_000], // 1h .. 90d
    ];

    /**
     * recall_strictness is an enum, not a numeric range — must match the CLI's
     * RECALL_STRICTNESS_LEVELS (skills/jtb/scripts/lib/recall-strictness.mjs).
     */
    public const ALLOWED_STRICTNESS = ['loose', 'balanced', 'strict'];

    protected $fillable = [
        'group_id',
        'flush_cooldown_ms',
        'timeout_ms',
        'max_queue_size',
        'max_entry_age_ms',
        'recall_strictness',
    ];

    protected $casts = [
        'flush_cooldown_ms' => 'integer',
        'timeout_ms'        => 'integer',
        'max_queue_size'    => 'integer',
        'max_entry_age_ms'  => 'integer',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
