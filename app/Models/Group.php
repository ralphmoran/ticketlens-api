<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Group extends Model
{
    protected $fillable = ['name', 'owner_id', 'permissions'];

    protected $casts = ['permissions' => 'integer'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Alias for users() — reads naturally in Team-manager code paths.
     */
    public function members(): BelongsToMany
    {
        return $this->users();
    }

    /**
     * Idempotent: returns the owner's existing group if one exists,
     * otherwise creates one and attaches the owner as its first member.
     * Never mutates the owner's tier or permissions — callers layer that on.
     */
    public static function createForOwner(User $owner): self
    {
        $group = $owner->ownedGroup;

        if ($group === null) {
            $groupName = trim(($owner->name ?? $owner->email) . "'s Team");
            $group     = self::create(['name' => $groupName, 'owner_id' => $owner->id]);
        }

        if (! $group->members()->where('users.id', $owner->id)->exists()) {
            $group->members()->attach($owner->id);
        }

        return $group;
    }
}
