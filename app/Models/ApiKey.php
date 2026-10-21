<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-integrator key for the public API (/v1/public/*).
 * Distinct from CliToken: a key carries scopes that only narrow its owner's access.
 */
class ApiKey extends Model
{
    public const SCOPES = ['triage:read', 'recall:read', 'recall:write', 'digest:send'];

    public const MAX_ACTIVE_PER_USER = 5;

    /** Owners bypass this; everyone else needs one of these tiers at creation and at use. */
    public const ALLOWED_TIERS = ['pro', 'team'];

    /** "tl_pk_" plus 8 random characters. */
    public const PREFIX_LENGTH = 14;

    protected $fillable = ['user_id', 'name', 'key_prefix', 'key_hash', 'scopes', 'last_used_at', 'revoked_at'];

    protected $casts = [
        'scopes'       => 'array',
        'last_used_at' => 'datetime',
        'revoked_at'   => 'datetime',
    ];

    protected $hidden = ['key_hash'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    public static function hashToken(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    public static function findActiveByPlaintext(string $plaintext): ?self
    {
        $hash = self::hashToken($plaintext);

        // Indexed prefix narrows the candidates; hash_equals avoids timing leaks and
        // lets a same-prefix key from another user coexist.
        return self::active()
            ->where('key_prefix', substr($plaintext, 0, self::PREFIX_LENGTH))
            ->get()
            ->first(fn (self $row) => hash_equals($row->key_hash, $hash));
    }
}
