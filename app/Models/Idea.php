<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Idea extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'post_id',
        'scan_id',
        'idea_title',
        'problem_statement',
        'proposed_solution',
        'target_audience',
        'why_small_team_viable',
        'demand_evidence',
        'monetization_model',
        'branding_suggestions',
        'marketing_channels',
        'existing_competitors',
        'scores',
        'score_monetization',
        'score_saturation',
        'score_complexity',
        'score_demand',
        'score_overall',
        'source_quote',
        'classification_status',
        'is_starred',
        'starred_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'branding_suggestions' => 'array',
        'marketing_channels' => 'array',
        'existing_competitors' => 'array',
        'scores' => 'array',
        'score_monetization' => 'integer',
        'score_saturation' => 'integer',
        'score_complexity' => 'integer',
        'score_demand' => 'integer',
        'score_overall' => 'integer',
        'is_starred' => 'boolean',
        'starred_at' => 'datetime',
    ];

    /**
     * Get the post this idea was extracted from.
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * Get the scan that found this idea.
     */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }

    /**
     * Get the subreddit through the post relationship.
     */
    public function getSubredditAttribute(): ?Subreddit
    {
        return $this->post?->subreddit;
    }

    /**
     * Toggle the starred status.
     */
    public function toggleStar(): void
    {
        $this->is_starred = ! $this->is_starred;
        $this->starred_at = $this->is_starred ? now() : null;
        $this->save();
    }

    /**
     * Star the idea.
     */
    public function star(): void
    {
        if (! $this->is_starred) {
            $this->is_starred = true;
            $this->starred_at = now();
            $this->save();
        }
    }

    /**
     * Unstar the idea.
     */
    public function unstar(): void
    {
        if ($this->is_starred) {
            $this->is_starred = false;
            $this->starred_at = null;
            $this->save();
        }
    }

    /**
     * Check if this is a borderline idea.
     */
    public function isBorderline(): bool
    {
        return $this->classification_status === 'borderline';
    }

    /**
     * Get branding name ideas.
     */
    public function getNameIdeasAttribute(): array
    {
        return $this->branding_suggestions['name_ideas'] ?? [];
    }

    /**
     * Get branding positioning statement.
     */
    public function getPositioningAttribute(): ?string
    {
        return $this->branding_suggestions['positioning'] ?? null;
    }

    /**
     * Get branding tagline.
     */
    public function getTaglineAttribute(): ?string
    {
        return $this->branding_suggestions['tagline'] ?? null;
    }

    /**
     * Get score reasoning for a specific score type.
     */
    public function getScoreReasoning(string $scoreType): ?string
    {
        $key = match ($scoreType) {
            'monetization' => 'monetization_reasoning',
            'saturation' => 'saturation_reasoning',
            'complexity' => 'complexity_reasoning',
            'demand' => 'demand_reasoning',
            'overall' => 'overall_reasoning',
            default => null,
        };

        return $key ? ($this->scores[$key] ?? null) : null;
    }

    /**
     * Scope for starred ideas.
     */
    public function scopeStarred(Builder $query)
    {
        return $query->where('is_starred', true);
    }

    /**
     * Scope for ideas with minimum overall score.
     */
    public function scopeMinScore(Builder $query, int $minScore)
    {
        return $query->where('score_overall', '>=', $minScore);
    }

    /**
     * Scope for ideas with minimum complexity score (higher = easier to build).
     */
    public function scopeMinComplexity(Builder $query, int $minComplexity)
    {
        return $query->where('score_complexity', '>=', $minComplexity);
    }

    /**
     * Scope for ideas from a specific subreddit.
     */
    public function scopeFromSubreddit(Builder $query, int $subredditId)
    {
        return $query->whereHas('post', function ($q) use ($subredditId) {
            $q->where('subreddit_id', $subredditId);
        });
    }

    /**
     * Scope for ideas from a specific scan.
     */
    public function scopeFromScan(Builder $query, int $scanId)
    {
        return $query->where('scan_id', $scanId);
    }

    /**
     * Scope to include or exclude borderline ideas.
     */
    public function scopeIncludeBorderline(Builder $query, bool $include = true)
    {
        if (! $include) {
            return $query->where('classification_status', '!=', 'borderline');
        }

        return $query;
    }

    /**
     * Scope to filter by date range.
     */
    public function scopeCreatedBetween(Builder $query, $from, $to)
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }

    /**
     * Scope to sort by a specific column.
     */
    public function scopeSortBy(Builder $query, string $column, string $direction = 'desc')
    {
        $allowedColumns = [
            'score_overall',
            'score_complexity',
            'score_monetization',
            'score_saturation',
            'score_demand',
            'created_at',
            'starred_at',
        ];

        if (in_array($column, $allowedColumns, true)) {
            return $query->orderBy($column, $direction);
        }

        return $query->orderByDesc('score_overall');
    }
}
