<?php

namespace App\Services\LLM\DTOs;

class ClassificationRequest
{
    public function __construct(
        public readonly string $postTitle,
        public readonly ?string $postBody,
        public readonly array $comments,
        public readonly int $upvotes,
        public readonly int $numComments,
        public readonly string $subreddit,
    ) {}

    /**
     * Create from a Post model with loaded comments.
     */
    public static function fromPost(\App\Models\Post $post): self
    {
        $comments = $post->comments
            ->sortByDesc('upvotes')
            ->take(50) // Limit comments to avoid token limits
            ->map(fn ($c) => [
                'author' => $c->display_author,
                'body' => $c->body,
                'upvotes' => $c->upvotes,
            ])
            ->values()
            ->toArray();

        return new self(
            postTitle: $post->title,
            postBody: $post->body,
            comments: $comments,
            upvotes: $post->upvotes,
            numComments: $post->num_comments,
            subreddit: $post->subreddit->name,
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
     * Get the full prompt content.
     */
    public function getPromptContent(): string
    {
        $body = $this->postBody ?: '(No body text - link post)';

        return <<<PROMPT
Analyze this Reddit post and its comments. Determine if it contains a genuine problem, pain point, or tool request that could inspire a SaaS product idea suitable for solo developers or small teams.

Decision rules:
- Default to "skip" unless there's a clear, specific pain point or an explicit request for a tool to solve it.
- "keep" is ONLY allowed for category "genuine-problem" or "tool-request".
- If category is "advice-thread", "spam", "self-promo", "rant-no-solution", "meme-joke", or "other", verdict MUST be "skip".
- If unsure, choose "skip" with low confidence.

POST:
Subreddit: r/{$this->subreddit}
Title: {$this->postTitle}
Body: {$body}
Upvotes: {$this->upvotes}
Comments: {$this->numComments}

COMMENTS:
{$this->getFormattedComments()}

Respond in JSON format:
{
  "verdict": "keep" | "skip",
  "confidence": 0.0 - 1.0,
  "category": "genuine-problem" | "tool-request" | "advice-thread" | "spam" | "self-promo" | "rant-no-solution" | "meme-joke" | "other",
  "reasoning": "brief explanation"
}
PROMPT;
    }

    /**
     * Convert to array for serialization.
     */
    public function toArray(): array
    {
        return [
            'post_title' => $this->postTitle,
            'post_body' => $this->postBody,
            'comments' => $this->comments,
            'upvotes' => $this->upvotes,
            'num_comments' => $this->numComments,
            'subreddit' => $this->subreddit,
        ];
    }
}
