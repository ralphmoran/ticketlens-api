<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'tier', 'permissions', 'is_owner', 'suspended_at', 'anthropic_key', 'openai_key'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    protected static function booted(): void
    {
        // Singleton invariant: at most one row may have is_owner=true.
        // Enforced in PHP because MySQL 8 has no filtered unique index. The check
        // skips when the row being saved is itself the existing owner, so renames /
        // password resets / suspended_at toggles on the owner row do not trip.
        static::saving(function (User $user): void {
            if (! $user->is_owner) {
                return;
            }

            $existsAnotherOwner = static::query()
                ->where('is_owner', true)
                ->when($user->exists, fn ($q) => $q->where('id', '!=', $user->id))
                ->exists();

            if ($existsAnotherOwner) {
                throw new \RuntimeException(
                    'Only a single platform owner account may exist.',
                );
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'permissions'       => 'integer',
            'is_owner'          => 'boolean',
            'suspended_at'      => 'datetime',
            'anthropic_key'     => 'encrypted',
            'openai_key'        => 'encrypted',
        ];
    }

    public function groups(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Group::class);
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    /**
     * The group this user OWNS (Team-tier manager role).
     * Null for regular members and solo Pro/Free users.
     */
    public function ownedGroup(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Group::class, 'owner_id');
    }

    /**
     * Primary team — first group via pivot. Team-tier members belong to exactly one.
     */
    public function team(): ?Group
    {
        return $this->groups()->first();
    }

    public function isTeamManager(): bool
    {
        return $this->ownedGroup()->exists();
    }
}
