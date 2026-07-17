<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RecallNote extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'group_id', 'author_id', 'tracker_profile_id', 'external_id', 'title',
        'aliases', 'tickets', 'tags', 'sources', 'body', 'status',
        'published_at', 'verified_at', 'verified_by',
    ];

    protected $casts = [
        'aliases'      => 'array',
        'tickets'      => 'array',
        'tags'         => 'array',
        'sources'      => 'array',
        'published_at' => 'datetime',
        'verified_at'  => 'datetime',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function trackerProfile(): BelongsTo
    {
        return $this->belongsTo(TrackerProfile::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
