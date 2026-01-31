<?php

namespace App\Services\LLM\DTOs;

class ExtractionRequest
{
    public function __construct(
        public readonly string $subreddit,
        public readonly string $postTitle,
        public readonly ?string $postBody,
        public readonly array $comments,
        public readonly int $upvotes,
        public readonly int $numComments,
        public readonly string $classificationStatus,
    ) {}

    /**
     * Create from a Post model with loaded comments.
     */
    public static function fromPost(\App\Models\Post $post): self
    {
        $comments = $post->comments
            ->sortByDesc('upvotes')
            ->take(100) // More comments for extraction
            ->map(fn ($c) => [
                'author' => $c->display_author,
                'body' => $c->body,
                'upvotes' => $c->upvotes,
            ])
            ->values()
            ->toArray();

        return new self(
            subreddit: $post->subreddit->name,
            postTitle: $post->title,
            postBody: $post->body,
            comments: $comments,
            upvotes: $post->upvotes,
            numComments: $post->num_comments,
            classificationStatus: $post->classification?->final_decision ?? 'keep',
        );
    }

    /**
     * Format comments as a string for the prompt.
     */
    public function getFormattedComments(): string
    {
        if (empty($this->comments)) {
            return '(No comments)';
        }

        return collect($this->comments)
            ->map(fn ($c) => "[{$c['upvotes']} upvotes] {$c['author']}: {$c['body']}")
            ->implode("\n\n");
    }

    /**
     * Get the full extraction prompt.
     */
    public function getPromptContent(): string
    {
        $body = $this->postBody ?: '(No body text - link post)';

        return <<<PROMPT
You are a SaaS opportunity analyst. Analyze this Reddit post and comments to extract viable SaaS business ideas suitable for solo developers or small teams (2-3 people).

CONTEXT:
- Ideas should be buildable in weeks/months, not years
- Target audience must be identifiable and reachable
- Clear monetization path required
- Marketing should be feasible without large budget

POST:
Subreddit: r/{$this->subreddit}
Title: {$this->postTitle}
Body: {$body}
Upvotes: {$this->upvotes}
Comments: {$this->numComments}

COMMENTS:
{$this->getFormattedComments()}

If you identify viable SaaS idea(s), respond with a JSON array. If no viable ideas exist, return an empty array [].

Each idea should contain:
{
  "idea_title": "Short catchy name for the concept",
  "problem_statement": "What specific pain point does this solve?",
  "proposed_solution": "High-level product description (2-3 sentences)",
  "target_audience": "Who pays for this? Be specific.",
  "why_small_team_viable": "Why this doesn't need a large company to build",
  "demand_evidence": "What in the post/comments suggests people want this?",
  "monetization_model": "How would this make money? (SaaS subscription, usage-based, etc.)",
  "branding_suggestions": {
    "name_ideas": ["Name1", "Name2", "Name3"],
    "positioning": "One-line positioning statement",
    "tagline": "Marketing tagline"
  },
  "marketing_channels": ["Channel 1", "Channel 2", "Channel 3"],
  "existing_competitors": ["Competitor 1", "Competitor 2"] or [],
  "scores": {
    "monetization": 1-5,
    "monetization_reasoning": "Why this score",
    "market_saturation": 1-5,
    "saturation_reasoning": "Why this score (5 = wide open, 1 = crowded)",
    "complexity": 1-5,
    "complexity_reasoning": "Why this score (5 = easy to build, 1 = very complex)",
    "demand_evidence": 1-5,
    "demand_reasoning": "Why this score",
    "overall": 1-5,
    "overall_reasoning": "Holistic assessment"
  },
  "source_quote": "The specific text from post/comment that inspired this idea"
}
PROMPT;
    }

    /**
     * Convert to array for serialization.
     */
    public function toArray(): array
    {
        return [
            'subreddit' => $this->subreddit,
            'post_title' => $this->postTitle,
            'post_body' => $this->postBody,
            'comments' => $this->comments,
            'upvotes' => $this->upvotes,
            'num_comments' => $this->numComments,
            'classification_status' => $this->classificationStatus,
        ];
    }
}
