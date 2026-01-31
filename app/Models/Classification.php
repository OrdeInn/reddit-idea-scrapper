<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'haiku_verdict',
        'haiku_confidence',
        'haiku_category',
        'haiku_reasoning',
        'gpt_verdict',
        'gpt_confidence',
        'gpt_category',
        'gpt_reasoning',
        'combined_score',
        'final_decision',
        'haiku_completed',
        'gpt_completed',
        'classified_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'haiku_confidence' => 'float',
        'gpt_confidence' => 'float',
        'combined_score' => 'float',
        'haiku_completed' => 'boolean',
        'gpt_completed' => 'boolean',
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
     * Check if both models have completed classification.
     */
    public function isComplete(): bool
    {
        return $this->haiku_completed && $this->gpt_completed;
    }

    /**
     * Check if this is a borderline classification.
     */
    public function isBorderline(): bool
    {
        return $this->final_decision === self::DECISION_BORDERLINE;
    }

    /**
     * Calculate the combined consensus score.
     * Formula: (haiku_confidence × haiku_keep + gpt_confidence × gpt_keep) / 2
     */
    public static function calculateConsensusScore(
        string $haikuVerdict,
        float $haikuConfidence,
        string $gptVerdict,
        float $gptConfidence
    ): float {
        $haikuKeep = $haikuVerdict === 'keep' ? 1 : 0;
        $gptKeep = $gptVerdict === 'keep' ? 1 : 0;

        return ($haikuConfidence * $haikuKeep + $gptConfidence * $gptKeep) / 2;
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
     * Check if shortcut rule applies (both models agree with high confidence).
     */
    public static function checkShortcutRule(
        string $haikuVerdict,
        float $haikuConfidence,
        string $gptVerdict,
        float $gptConfidence,
        float $shortcutThreshold = 0.8
    ): ?string {
        // Both skip with high confidence = DISCARD
        if (
            $haikuVerdict === 'skip' && $gptVerdict === 'skip'
            && $haikuConfidence > $shortcutThreshold && $gptConfidence > $shortcutThreshold
        ) {
            return self::DECISION_DISCARD;
        }

        // Both keep with high confidence = KEEP
        if (
            $haikuVerdict === 'keep' && $gptVerdict === 'keep'
            && $haikuConfidence > $shortcutThreshold && $gptConfidence > $shortcutThreshold
        ) {
            return self::DECISION_KEEP;
        }

        return null; // No shortcut applies
    }

    /**
     * Process classification results and update the model.
     */
    public function processResults(): void
    {
        if (! $this->isComplete()) {
            return;
        }

        // Check shortcut rules first
        $shortcutDecision = self::checkShortcutRule(
            $this->haiku_verdict,
            $this->haiku_confidence,
            $this->gpt_verdict,
            $this->gpt_confidence
        );

        if ($shortcutDecision !== null) {
            $this->combined_score = $shortcutDecision === self::DECISION_KEEP ? 1.0 : 0.0;
            $this->final_decision = $shortcutDecision;
        } else {
            // Calculate consensus score
            $this->combined_score = self::calculateConsensusScore(
                $this->haiku_verdict,
                $this->haiku_confidence,
                $this->gpt_verdict,
                $this->gpt_confidence
            );

            $this->final_decision = self::determineFinalDecision($this->combined_score);
        }

        $this->classified_at = now();
        $this->save();
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
