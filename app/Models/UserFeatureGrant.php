<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserFeatureGrant extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'feature_id', 'granted_by', 'expires_at', 'note'];

    protected function casts(): array
    {
        return [
            'expires_at'  => 'datetime',
            'revoked_at'  => 'datetime',
            'created_at'  => 'datetime',
        ];
    }

    /**
     * Active = not revoked AND (no expiry OR expiry is in the future).
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at')
              ->where(function (Builder $q): void {
                  $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
              });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
