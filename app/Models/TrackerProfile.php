<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrackerProfile extends Model
{
    protected $fillable = [
        'user_id', 'name', 'tracker_type', 'base_url', 'auth_method',
        'email', 'ticket_prefixes', 'project_paths', 'triage_statuses',
        'stale_rule', 'known_statuses', 'statuses_cached_at',
    ];

    protected $casts = [
        'ticket_prefixes'    => 'array',
        'project_paths'      => 'array',
        'triage_statuses'    => 'array',
        'stale_rule'         => 'array',
        'known_statuses'     => 'array',
        'statuses_cached_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Shape returned to the CLI — never includes any credential field. */
    public function toCliArray(): array
    {
        return [
            'name'               => $this->name,
            'tracker_type'       => $this->tracker_type,
            'base_url'           => $this->base_url,
            'auth_method'        => $this->auth_method,
            'email'              => $this->email,
            'ticket_prefixes'    => $this->ticket_prefixes ?? [],
            'project_paths'      => $this->project_paths ?? [],
            'triage_statuses'    => $this->triage_statuses ?? [],
            'stale_rule'         => $this->stale_rule,
            'known_statuses'     => $this->known_statuses ?? [],
            'statuses_cached_at' => $this->statuses_cached_at?->toIso8601String(),
        ];
    }
}
