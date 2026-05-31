<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BriefTemplate extends Model
{
    protected $fillable = [
        'group_id',
        'slug',
        'name',
        'description',
        'sections',
        'created_by',
    ];

    protected $casts = [
        'sections'  => 'array',
        'is_system' => 'bool',
    ];

    /** Returns system templates + the given group's own templates. */
    public function scopeForGroup(Builder $query, ?int $groupId): Builder
    {
        return $query->where(function (Builder $q) use ($groupId) {
            $q->whereNull('group_id')
              ->orWhere('group_id', $groupId);
        });
    }

    /** Returns only system (built-in) templates. */
    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('is_system', true);
    }
}
