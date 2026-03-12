<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Classification extends Model
{
    use HasFactory;

    /**
     * Decision constants.
     */
    public const DECISION_KEEP = 'keep';

    public const DECISION_DISCARD = 'discard';

    public const DECISION_BORDERLINE = 'borderline';

    /**
     * Category constants.
     */
    public const CATEGORY_GENUINE_PROBLEM = 'genuine-problem';

    public const CATEGORY_TOOL_REQUEST = 'tool-request';

    public const CATEGORY_ADVICE_THREAD = 'advice-thread';

    public const CATEGORY_SPAM = 'spam';

    public const CATEGORY_SELF_PROMO = 'self-promo';

    public const CATEGORY_RANT = 'rant-no-solution';

    public const CATEGORY_MEME = 'meme-joke';

    public const CATEGORY_OTHER = 'other';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'post_id',
        'combined_score',
        'final_decision',
        'expected_provider_count',
        'classified_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'combined_score' => 'float',
        'expected_provider_count' => 'integer',
        'classified_at' => 'datetime',
    ];

    /**
     * Get the post this classification belongs to.
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * Get all classification results for this classification.
     */
    public function results(): HasMany
    {
        return $this->hasMany(ClassificationResult::class);
    }

    /**
     * Check if all expected providers have completed classification.
     */
    public function isComplete(): bool
    {
        return $this->results()->where('completed', true)->count() >= $this->expected_provider_count;
    }

    /**
     * Check if this classification has been finalized (has a classified_at timestamp).
     */
    public function isFinalized(): bool
    {
        return $this->classified_at !== null;
    }

    /**
     * Check if this is a borderline classification.
     */
    public function isBorderline(): bool
    {
        return $this->final_decision === self::DECISION_BORDERLINE;
    }

    /**
     * Calculate the combined consensus score from a collection of completed results.
     * Formula: sum(confidence × keep_flag) / count
     *
     * @param Collection<ClassificationResult> $results
     */
    public static function calculateConsensusScore(Collection $results): float
    {
        if ($results->isEmpty()) {
            return 0.0;
        }

        $sum = $results->sum(fn (ClassificationResult $result) =>
            ($result->confidence ?? 0.0) * ($result->verdict === 'keep' ? 1 : 0)
        );

        return $sum / $results->count();
    }

    /**
     * Check if shortcut rule applies (ALL providers agree on same verdict AND all above threshold).
     *
     * @param Collection<ClassificationResult> $results
     */
    public static function checkShortcutRule(Collection $results, float $threshold = 0.8): ?string
    {
        if ($results->isEmpty()) {
            return null;
        }

        // All providers must agree on the same verdict
        $verdicts = $results->pluck('verdict')->unique();
        if ($verdicts->count() !== 1) {
            return null;
        }

        // All providers must be above the threshold
        $allAboveThreshold = $results->every(fn (ClassificationResult $result) =>
            ($result->confidence ?? 0.0) > $threshold
        );

        if (! $allAboveThreshold) {
            return null;
        }

        $verdict = $verdicts->first();

        if ($verdict === 'keep') {
            return self::DECISION_KEEP;
        }

        if ($verdict === 'skip') {
            return self::DECISION_DISCARD;
        }

        return null;
    }

    /**
     * If fully completed providers disagree on verdict, keep it as borderline regardless of score.
     *
     * @param Collection<ClassificationResult> $results
     */
    public static function hasProviderDisagreement(Collection $results): bool
    {
        return $results->pluck('verdict')->filter()->unique()->count() > 1;
    }

    /**
     * Determine the final decision based on consensus score.
     */
    public static function determineFinalDecision(
        float $consensusScore,
        float $keepThreshold = 0.6,
        float $discardThreshold = 0.4
    ): string {
        if ($consensusScore >= $keepThreshold) {
            return self::DECISION_KEEP;
        }

        if ($consensusScore < $discardThreshold) {
            return self::DECISION_DISCARD;
        }

        return self::DECISION_BORDERLINE;
    }

    /**
     * Process classification results and update the model.
     * Loads completed results, checks shortcut, calculates consensus, saves.
     */
    public function processResults(): void
    {
        $completedResults = $this->results()->where('completed', true)->get();

        if ($completedResults->isEmpty()) {
            return;
        }

        $this->combined_score = self::calculateConsensusScore($completedResults);

        if (self::hasProviderDisagreement($completedResults)) {
            $this->final_decision = self::DECISION_BORDERLINE;
            $this->classified_at = now();
            $this->save();

            return;
        }

        // Check shortcut rules first
        $shortcutThreshold = (float) config('llm.classification.shortcut_confidence', 0.8);
        $shortcutDecision = self::checkShortcutRule($completedResults, $shortcutThreshold);

        if ($shortcutDecision !== null) {
            $this->combined_score = $shortcutDecision === self::DECISION_KEEP ? 1.0 : 0.0;
            $this->final_decision = $shortcutDecision;
        } else {
            $keepThreshold = (float) config('llm.classification.consensus_threshold_keep', 0.6);
            $discardThreshold = (float) config('llm.classification.consensus_threshold_discard', 0.4);

            $this->final_decision = self::determineFinalDecision(
                $this->combined_score,
                $keepThreshold,
                $discardThreshold
            );
        }

        $this->classified_at = now();
        $this->save();
    }

    /**
     * Full provider output including reasoning and category (for detail views).
     */
    public function getProvidersAttribute(): array
    {
        $providers = [];

        foreach ($this->results as $result) {
            $displayName = config("llm.providers.{$result->provider_name}.display_name", $result->provider_name);

            $providers[] = [
                'name'         => $result->provider_name,
                'display_name' => $displayName,
                'model_id'     => $result->model_id,
                'verdict'      => $result->verdict,
                'confidence'   => $result->confidence,
                'category'     => $result->category,
                'reasoning'    => $result->reasoning,
                'details'      => $result->details,
                'completed'    => (bool) $result->completed,
            ];
        }

        return $providers;
    }

    /**
     * Lightweight provider output without reasoning or category (for list views).
     */
    public function getProvidersSummaryAttribute(): array
    {
        $providers = [];

        foreach ($this->results as $result) {
            $displayName = config("llm.providers.{$result->provider_name}.display_name", $result->provider_name);

            $providers[] = [
                'name'         => $result->provider_name,
                'display_name' => $displayName,
                'model_id'     => $result->model_id,
                'verdict'      => $result->verdict,
                'confidence'   => $result->confidence,
                'completed'    => (bool) $result->completed,
            ];
        }

        return $providers;
    }

    /**
     * Scope for classifications that passed (keep or borderline).
     */
    public function scopePassed($query)
    {
        return $query->whereIn('final_decision', [self::DECISION_KEEP, self::DECISION_BORDERLINE]);
    }

    /**
     * Scope for classifications by decision.
     */
    public function scopeByDecision($query, string $decision)
    {
        return $query->where('final_decision', $decision);
    }
}
