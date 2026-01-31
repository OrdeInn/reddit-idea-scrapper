<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Comment extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'post_id',
        'reddit_id',
        'reddit_fullname',
        'parent_reddit_id',
        'body',
        'author',
        'upvotes',
        'downvotes',
        'depth',
        'reddit_created_at',
        'fetched_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'upvotes' => 'integer',
        'downvotes' => 'integer',
        'depth' => 'integer',
        'reddit_created_at' => 'datetime',
        'fetched_at' => 'datetime',
    ];

    /**
     * Get the post this comment belongs to.
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * Get child replies to this comment.
     */
    public function replies(): HasMany
    {
        return $this->hasMany(Comment::class, 'parent_reddit_id', 'reddit_id');
    }

    /**
     * Check if this is a top-level comment.
     */
    public function isTopLevel(): bool
    {
        return $this->parent_reddit_id === null;
    }

    /**
     * Get display author (handles deleted users).
     */
    public function getDisplayAuthorAttribute(): string
    {
        return $this->author ?? '[deleted]';
    }

    /**
     * Scope for top-level comments only.
     */
    public function scopeTopLevel($query)
    {
        return $query->whereNull('parent_reddit_id');
    }

    /**
     * Scope for comments sorted by upvotes.
     */
    public function scopeByUpvotes($query)
    {
        return $query->orderByDesc('upvotes');
    }
}
