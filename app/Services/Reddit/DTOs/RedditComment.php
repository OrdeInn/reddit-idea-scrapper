<?php

namespace App\Services\Reddit\DTOs;

use Carbon\Carbon;

class RedditComment
{
    public function __construct(
        public readonly string $redditId,
        public readonly string $redditFullname,
        public readonly ?string $parentRedditId,
        public readonly string $body,
        public readonly ?string $author,
        public readonly int $upvotes,
        public readonly int $downvotes,
        public readonly int $depth,
        public readonly Carbon $redditCreatedAt,
    ) {}

    /**
     * Create from Reddit API response data.
     */
    public static function fromApiResponse(array $data, int $depth = 0): self
    {
        // Parent ID is in format "t1_xxx" or "t3_xxx" (for top-level comments on post)
        $parentId = $data['parent_id'] ?? null;
        $parentRedditId = null;

        if ($parentId && str_starts_with($parentId, 't1_')) {
            // Parent is another comment
            $parentRedditId = substr($parentId, 3);
        }
        // If parent starts with t3_, it's the post itself, so parentRedditId stays null

        return new self(
            redditId: $data['id'] ?? '',
            redditFullname: $data['name'] ?? '', // e.g., "t1_abc123"
            parentRedditId: $parentRedditId,
            body: $data['body'] ?? '',
            author: ($data['author'] ?? '[deleted]') !== '[deleted]' ? $data['author'] : null,
            upvotes: $data['ups'] ?? 0,
            downvotes: $data['downs'] ?? 0,
            depth: $depth,
            redditCreatedAt: Carbon::createFromTimestamp($data['created_utc'] ?? time()),
        );
    }

    /**
     * Convert to array for database insertion.
     *
     * @param int $postId Foreign key to posts table
     */
    public function toArray(int $postId): array
    {
        return [
            'post_id' => $postId,
            'reddit_id' => $this->redditId,
            'reddit_fullname' => $this->redditFullname,
            'parent_reddit_id' => $this->parentRedditId,
            'body' => $this->body,
            'author' => $this->author,
            'upvotes' => $this->upvotes,
            'downvotes' => $this->downvotes,
            'depth' => $this->depth,
            'reddit_created_at' => $this->redditCreatedAt,
            'fetched_at' => now(),
        ];
    }
}
