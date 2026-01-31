<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subreddit extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'last_scanned_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'last_scanned_at' => 'datetime',
    ];

    /**
     * Get the full subreddit name with r/ prefix.
     */
    public function getFullNameAttribute(): string
    {
        return 'r/'.$this->name;
    }

    /**
     * Get all scans for this subreddit.
     */
    public function scans(): HasMany
    {
        return $this->hasMany(Scan::class);
    }

    /**
     * Get all posts for this subreddit.
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * Get the latest completed scan.
     */
    public function latestCompletedScan(): ?Scan
    {
        return $this->scans()
            ->where('status', 'completed')
            ->latest('completed_at')
            ->first();
    }

    /**
     * Get the currently running scan (if any).
     */
    public function activeScan(): ?Scan
    {
        return $this->scans()
            ->whereNotIn('status', ['completed', 'failed'])
            ->latest()
            ->first();
    }

    /**
     * Check if a scan is currently in progress.
     */
    public function isScanInProgress(): bool
    {
        return $this->activeScan() !== null;
    }

    /**
     * Get total idea count for this subreddit.
     */
    public function getIdeaCountAttribute(): int
    {
        return Idea::whereHas('post', function ($query) {
            $query->where('subreddit_id', $this->id);
        })->count();
    }

    /**
     * Get the highest overall score among ideas.
     */
    public function getTopScoreAttribute(): ?int
    {
        return Idea::whereHas('post', function ($query) {
            $query->where('subreddit_id', $this->id);
        })->max('score_overall');
    }
}
