<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CliToken extends Model
{
    protected $fillable = ['user_id', 'name', 'token_hash', 'token_prefix', 'last_used_at'];

    protected $casts = ['last_used_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hashToken(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    public static function findByPlaintext(string $plaintext): ?self
    {
        $hash   = self::hashToken($plaintext);
        $prefix = substr($plaintext, 0, 8);

        // Narrow the candidate set via indexed prefix (new tokens), iterate all matching
        // rows so a same-prefix collision from another token does not shadow the right one.
        // Fall back to full-hash lookup for legacy tokens that have no prefix stored yet.
        return self::where('token_prefix', $prefix)
            ->get()
            ->first(fn ($row) => hash_equals($row->token_hash, $hash))
            ?? self::whereNull('token_prefix')->where('token_hash', $hash)->first();
    }
}
