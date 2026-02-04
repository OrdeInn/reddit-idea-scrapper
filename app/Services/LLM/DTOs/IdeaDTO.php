<?php

namespace App\Services\LLM\DTOs;

class IdeaDTO
{
    public function __construct(
        public readonly string $ideaTitle,
        public readonly string $problemStatement,
        public readonly string $proposedSolution,
        public readonly string $targetAudience,
        public readonly string $whySmallTeamViable,
        public readonly string $demandEvidence,
        public readonly string $monetizationModel,
        public readonly array $brandingSuggestions,
        public readonly array $marketingChannels,
        public readonly array $existingCompetitors,
        public readonly array $scores,
        public readonly string $sourceQuote,
    ) {}

    /**
     * Create from JSON array.
     */
    public static function fromJson(array $json): ?self
    {
        // Validate required fields
        if (empty($json['idea_title']) || empty($json['problem_statement'])) {
            return null;
        }

        return new self(
            ideaTitle: $json['idea_title'] ?? '',
            problemStatement: $json['problem_statement'] ?? '',
            proposedSolution: $json['proposed_solution'] ?? '',
            targetAudience: $json['target_audience'] ?? '',
            whySmallTeamViable: $json['why_small_team_viable'] ?? '',
            demandEvidence: $json['demand_evidence'] ?? '',
            monetizationModel: $json['monetization_model'] ?? '',
            brandingSuggestions: self::parseBrandingSuggestions($json['branding_suggestions'] ?? []),
            marketingChannels: is_array($json['marketing_channels'] ?? null) ? $json['marketing_channels'] : [],
            existingCompetitors: self::parseCompetitors($json['existing_competitors'] ?? []),
            scores: self::parseScores($json['scores'] ?? []),
            sourceQuote: $json['source_quote'] ?? '',
        );
    }

    /**
     * Parse branding suggestions to ensure proper structure.
     */
    private static function parseBrandingSuggestions(mixed $branding): array
    {
        if (! is_array($branding)) {
            return ['name_ideas' => [], 'positioning' => '', 'tagline' => ''];
        }

        $nameIdeas = $branding['name_ideas'] ?? [];

        return [
            'name_ideas' => is_array($nameIdeas) ? $nameIdeas : [],
            'positioning' => (string) ($branding['positioning'] ?? ''),
            'tagline' => (string) ($branding['tagline'] ?? ''),
        ];
    }

    /**
     * Parse competitors to array.
     */
    private static function parseCompetitors(mixed $competitors): array
    {
        if (is_string($competitors)) {
            return $competitors === 'None identified' ? [] : [$competitors];
        }

        return is_array($competitors) ? $competitors : [];
    }

    /**
     * Parse scores and extract individual values, normalizing red_flags as array of strings.
     */
    private static function parseScores(mixed $scores): array
    {
        if (! is_array($scores)) {
            return [
                'monetization' => 0,
                'monetization_reasoning' => '',
                'market_saturation' => 0,
                'saturation_reasoning' => '',
                'complexity' => 0,
                'complexity_reasoning' => '',
                'demand_evidence' => 0,
                'demand_reasoning' => '',
                'overall' => 0,
                'overall_reasoning' => '',
                'red_flags' => [],
            ];
        }

        // Extract known fields with proper type casting, ensuring strings for reasoning fields
        $parsedScores = [
            'monetization' => (int) ($scores['monetization'] ?? 0),
            'monetization_reasoning' => (string) ($scores['monetization_reasoning'] ?? ''),
            'market_saturation' => (int) ($scores['market_saturation'] ?? 0),
            'saturation_reasoning' => (string) ($scores['saturation_reasoning'] ?? ''),
            'complexity' => (int) ($scores['complexity'] ?? 0),
            'complexity_reasoning' => (string) ($scores['complexity_reasoning'] ?? ''),
            'demand_evidence' => (int) ($scores['demand_evidence'] ?? 0),
            'demand_reasoning' => (string) ($scores['demand_reasoning'] ?? ''),
            'overall' => (int) ($scores['overall'] ?? 0),
            'overall_reasoning' => (string) ($scores['overall_reasoning'] ?? ''),
            'red_flags' => self::parseRedFlags($scores['red_flags'] ?? []),
        ];

        return $parsedScores;
    }

    /**
     * Parse red_flags to ensure it's an array of strings, filtering out non-scalar values.
     */
    private static function parseRedFlags(mixed $redFlags): array
    {
        if (! is_array($redFlags)) {
            return [];
        }

        $filtered = array_filter($redFlags, fn ($flag) => is_scalar($flag));

        return array_values(
            array_map(fn ($flag) => (string) $flag, $filtered)
        );
    }

    /**
     * Convert to array for database insertion.
     */
    public function toArray(): array
    {
        return [
            'idea_title' => $this->ideaTitle,
            'problem_statement' => $this->problemStatement,
            'proposed_solution' => $this->proposedSolution,
            'target_audience' => $this->targetAudience,
            'why_small_team_viable' => $this->whySmallTeamViable,
            'demand_evidence' => $this->demandEvidence,
            'monetization_model' => $this->monetizationModel,
            'branding_suggestions' => $this->brandingSuggestions,
            'marketing_channels' => $this->marketingChannels,
            'existing_competitors' => $this->existingCompetitors,
            'scores' => $this->scores,
            'score_monetization' => $this->scores['monetization'] ?? 0,
            'score_saturation' => $this->scores['market_saturation'] ?? 0,
            'score_complexity' => $this->scores['complexity'] ?? 0,
            'score_demand' => $this->scores['demand_evidence'] ?? 0,
            'score_overall' => $this->scores['overall'] ?? 0,
            'source_quote' => $this->sourceQuote,
        ];
    }
}
