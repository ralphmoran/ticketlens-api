<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AiProviderPool extends Model
{
    use HasFactory;

    protected $fillable = [
        'group_id',
        'created_by',
        'title',
        'provider_type',
        'api_key',
        'endpoint',
        'model',
        'notes',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'enabled' => 'boolean',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(AiProviderRole::class, 'ai_provider_role_pool')
            ->withPivot('priority')
            ->withTimestamps();
    }

    /** Display-safe representation for Console/API responses (never exposes the raw key). */
    public function toDisplayArray(): array
    {
        return [
            'id'            => $this->id,
            'title'         => $this->title,
            'provider_type' => $this->provider_type,
            'masked_key'    => $this->maskedKey(),
            'endpoint'      => $this->endpoint,
            'model'         => $this->model,
            'notes'         => $this->notes,
            'enabled'       => $this->enabled,
        ];
    }

    /** Returns the key masked for display: first 4 + last 4 chars only, whatever the vendor. */
    public function maskedKey(): string
    {
        $key = $this->api_key;
        if (mb_strlen($key) <= 8) {
            return str_repeat('*', mb_strlen($key));
        }

        return mb_substr($key, 0, 4) . str_repeat('*', 4) . mb_substr($key, -4);
    }
}
