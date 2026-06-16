<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsageLog extends Model
{
    public $timestamps = false;

    /**
     * tokens_used has dual semantics depending on the row origin:
     *   - metadata IS NULL  → BYOK AI action (digest/summarize/compliance): tokens *consumed*
     *   - metadata NOT NULL → CLI command row (PushController): tokens *saved* (estimated, brief.length/4)
     * Never aggregate both together. Use whereNull/whereNotNull('metadata') to discriminate.
     */
    protected $fillable = ['user_id', 'action', 'ticket_key', 'tokens_used', 'metadata'];
    protected $casts = ['created_at' => 'datetime', 'metadata' => 'array'];
}
