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
}
