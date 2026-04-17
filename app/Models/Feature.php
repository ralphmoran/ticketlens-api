<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Feature extends Model
{
    protected $fillable = ['name', 'bit_value', 'label', 'description', 'sort_order'];

    protected function casts(): array
    {
        return ['bit_value' => 'integer', 'sort_order' => 'integer'];
    }

    public function tiers(): BelongsToMany
    {
        return $this->belongsToMany(
            related: Feature::class,
            table: 'tier_features',
            foreignPivotKey: 'feature_id',
            relatedPivotKey: 'tier',
        );
    }
}
