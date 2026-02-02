<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Post extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'subreddit_id',
        'scan_id',
        'reddit_id',
        'reddit_fullname',
        'title',
        'body',
        'author',
        'permalink',
        'url',
        'upvotes',
        'downvotes',
        'num_comments',
        'upvote_ratio',
        'flair',
        'is_self',
        'is_nsfw',
        'is_spoiler',
        'reddit_created_at',
        'fetched_at',
        'extracted_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'upvotes' => 'integer',
        'downvotes' => 'integer',
        'num_comments' => 'integer',
        'upvote_ratio' => 'float',
        'is_self' => 'boolean',
        'is_nsfw' => 'boolean',
        'is_spoiler' => 'boolean',
        'reddit_created_at' => 'datetime',
        'fetched_at' => 'datetime',
        'extracted_at' => 'datetime',
    ];

    /**
     * Get the subreddit this post belongs to.
     */
    public function subreddit(): BelongsTo
    {
        return $this->belongsTo(Subreddit::class);
    }

    /**
     * Get the scan that fetched this post.
     */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }

    /**
     * Get all comments for this post.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * Get top-level comments only.
     */
    public function topLevelComments(): HasMany
    {
        return $this->hasMany(Comment::class)->whereNull('parent_reddit_id');
    }

    /**
     * Get the classification for this post.
     */
    public function classification(): HasOne
    {
        return $this->hasOne(Classification::class);
    }

    /**
     * Get all ideas extracted from this post.
     */
    public function ideas(): HasMany
    {
        return $this->hasMany(Idea::class);
    }

    /**
     * Get the full Reddit URL.
     */
    public function getRedditUrlAttribute(): string
    {
        return 'https://www.reddit.com'.$this->permalink;
    }

    /**
     * Check if the post has been classified.
     */
    public function isClassified(): bool
    {
        return $this->classification !== null && $this->classification->classified_at !== null;
    }

    /**
     * Check if the post passed classification (keep or borderline).
     */
    public function passedClassification(): bool
    {
        return $this->classification !== null
            && in_array($this->classification->final_decision, ['keep', 'borderline'], true);
    }

    /**
     * Get display author (handles deleted users).
     */
    public function getDisplayAuthorAttribute(): string
    {
        return $this->author ?? '[deleted]';
    }

    /**
     * Scope for posts that meet minimum engagement thresholds.
     */
    public function scopeWithMinimumEngagement(Builder $query, int $minUpvotes = 5, int $minComments = 3)
    {
        return $query->where('upvotes', '>=', $minUpvotes)
            ->where('num_comments', '>=', $minComments);
    }

    /**
     * Scope for posts that need classification.
     */
    public function scopeNeedsClassification(Builder $query)
    {
        return $query->whereDoesntHave('classification');
    }

    /**
     * Scope for posts that passed classification and need extraction.
     */
    public function scopeNeedsExtraction(Builder $query)
    {
        return $query->whereHas('classification', function ($q) {
            $q->whereIn('final_decision', ['keep', 'borderline']);
        })->whereNull('extracted_at');
    }

    /**
     * Scope for posts that have been extracted.
     */
    public function scopeExtracted(Builder $query)
    {
        return $query->whereNotNull('extracted_at');
    }

    /**
     * Check if the post has been extracted.
     */
    public function isExtracted(): bool
    {
        return $this->extracted_at !== null;
    }

    /**
     * Mark the post as extracted.
     */
    public function markAsExtracted(): void
    {
        $this->forceFill(['extracted_at' => now()])->save();
    }
}
