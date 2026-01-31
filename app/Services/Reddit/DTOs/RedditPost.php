<?php

namespace App\Services\Reddit\DTOs;

use Carbon\Carbon;

class RedditPost
{
    public function __construct(
        public readonly string $redditId,
        public readonly string $redditFullname,
        public readonly string $subreddit,
        public readonly string $title,
        public readonly ?string $body,
        public readonly ?string $author,
        public readonly string $permalink,
        public readonly ?string $url,
        public readonly int $upvotes,
        public readonly int $downvotes,
        public readonly int $numComments,
        public readonly float $upvoteRatio,
        public readonly ?string $flair,
        public readonly bool $isSelf,
        public readonly bool $isNsfw,
        public readonly bool $isSpoiler,
        public readonly Carbon $redditCreatedAt,
    ) {}

    /**
     * Create from Reddit API response data.
     */
    public static function fromApiResponse(array $data): self
    {
        return new self(
            redditId: $data['id'] ?? '',
            redditFullname: $data['name'] ?? '', // e.g., "t3_abc123"
            subreddit: $data['subreddit'] ?? '',
            title: $data['title'] ?? '',
            body: ($data['selftext'] ?? '') ?: null,
            author: ($data['author'] ?? '[deleted]') !== '[deleted]' ? $data['author'] : null,
            permalink: $data['permalink'] ?? '',
            url: $data['url'] ?? null,
            upvotes: $data['ups'] ?? 0,
            downvotes: $data['downs'] ?? 0,
            numComments: $data['num_comments'] ?? 0,
            upvoteRatio: (float) ($data['upvote_ratio'] ?? 0),
            flair: $data['link_flair_text'] ?? null,
            isSelf: $data['is_self'] ?? true,
            isNsfw: $data['over_18'] ?? false,
            isSpoiler: $data['spoiler'] ?? false,
            redditCreatedAt: Carbon::createFromTimestamp($data['created_utc'] ?? time()),
        );
    }

    /**
     * Convert to array for database insertion.
     *
     * @param int $subredditId Foreign key to subreddits table
     * @param int|null $scanId Foreign key to scans table (nullable)
     */
    public function toArray(int $subredditId, ?int $scanId = null): array
    {
        return [
            'subreddit_id' => $subredditId,
            'scan_id' => $scanId,
            'reddit_id' => $this->redditId,
            'reddit_fullname' => $this->redditFullname,
            'title' => $this->title,
            'body' => $this->body,
            'author' => $this->author,
            'permalink' => $this->permalink,
            'url' => $this->url,
            'upvotes' => $this->upvotes,
            'downvotes' => $this->downvotes,
            'num_comments' => $this->numComments,
            'upvote_ratio' => $this->upvoteRatio,
            'flair' => $this->flair,
            'is_self' => $this->isSelf,
            'is_nsfw' => $this->isNsfw,
            'is_spoiler' => $this->isSpoiler,
            'reddit_created_at' => $this->redditCreatedAt,
            'fetched_at' => now(),
        ];
    }
}
