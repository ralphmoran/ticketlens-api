<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageLog extends Model
{
    public $timestamps = false;

    /**
     * tokens_used has dual semantics depending on the row origin:
     *   - metadata IS NULL  → BYOK AI action (digest/summarize/compliance): tokens *consumed*
     *   - metadata NOT NULL → CLI command row (PushController): tokens *saved* (estimated, brief.length/4)
     * Never aggregate both together. Use the cliOrigin() scope (has_metadata, indexed) to
     * discriminate — NOT whereNull/whereNotNull('metadata'), which can't use an index on MySQL.
     */
    protected $fillable = ['user_id', 'action', 'ticket_key', 'tokens_used', 'command_count', 'metadata'];
    protected $casts = ['created_at' => 'datetime', 'metadata' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeCliOrigin(Builder $query): Builder
    {
        return $query->where('has_metadata', 1);
    }

    /**
     * Single writer for every BYOK AI-action row (metadata always null here) —
     * keeps the dual-semantics invariant in one place instead of each caller
     * building its own array. $tokens is the real consumed count from the
     * provider's own response, never an estimate.
     */
    public static function recordAiAction(User $user, string $action, ?string $ticketKey, int $tokens): self
    {
        return static::create([
            'user_id'     => $user->id,
            'action'      => $action,
            'ticket_key'  => $ticketKey,
            'tokens_used' => $tokens,
            'metadata'    => null,
        ]);
    }
}
