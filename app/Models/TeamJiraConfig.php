<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamJiraConfig extends Model
{
    protected $fillable = [
        'group_id',
        'jira_base_url',
        'auth_type',
        'prefixes',
        'project_paths',
        'triage_statuses',
    ];

    protected $casts = [
        'prefixes'        => 'array',
        'project_paths'   => 'array',
        'triage_statuses' => 'array',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
