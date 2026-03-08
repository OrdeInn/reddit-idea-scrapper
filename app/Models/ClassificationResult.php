<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassificationResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'classification_id',
        'provider_name',
        'verdict',
        'confidence',
        'category',
        'reasoning',
        'completed',
        'completed_at',
    ];

    protected $casts = [
        'confidence' => 'float',
        'completed' => 'boolean',
        'completed_at' => 'datetime',
    ];

    public function classification(): BelongsTo
    {
        return $this->belongsTo(Classification::class);
    }

    public function isKeep(): bool
    {
        return $this->verdict === 'keep';
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('completed', true);
    }

    public function scopeForProvider(Builder $query, string $name): Builder
    {
        return $query->where('provider_name', $name);
    }
}
