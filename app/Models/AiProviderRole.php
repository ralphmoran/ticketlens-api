<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AiProviderRole extends Model
{
    use HasFactory;

    /** The only kind with real wired behavior today — see consensus-checker.mjs's sync consumer. */
    public const KIND_CONSENSUS = 'consensus';
    public const KIND_CUSTOM = 'custom';
    public const KINDS = [self::KIND_CONSENSUS, self::KIND_CUSTOM];

    protected $fillable = [
        'user_id',
        'label',
        'kind',
        'generated_prompt',
        'prompt_generated_at',
    ];

    protected function casts(): array
    {
        return [
            'prompt_generated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Ordered ascending by priority (1 = main) — the fallback chain order for single-output roles. */
    public function providers(): BelongsToMany
    {
        return $this->belongsToMany(AiProviderPool::class, 'ai_provider_role_pool')
            ->withPivot('priority')
            ->withTimestamps()
            ->orderByPivot('priority');
    }

    public function toDisplayArray(): array
    {
        return [
            'id'                   => $this->id,
            'label'                => $this->label,
            'kind'                 => $this->kind,
            'generated_prompt'     => $this->generated_prompt,
            'prompt_generated_at'  => $this->prompt_generated_at?->toIso8601String(),
            'providers'            => $this->providers->map(fn($p) => [
                ...$p->toDisplayArray(),
                'priority' => $p->pivot->priority,
            ])->values(),
        ];
    }
}
