<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Scan extends Model
{
    use HasFactory;

    /**
     * Scan status constants.
     */
    public const STATUS_PENDING = 'pending';

    public const STATUS_FETCHING = 'fetching';

    public const STATUS_CLASSIFYING = 'classifying';

    public const STATUS_EXTRACTING = 'extracting';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /**
     * Scan type constants.
     */
    public const TYPE_INITIAL = 'initial';

    public const TYPE_RESCAN = 'rescan';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'subreddit_id',
        'scan_type',
        'status',
        'error_message',
        'date_from',
        'date_to',
        'posts_fetched',
        'posts_classified',
        'posts_extracted',
        'ideas_found',
        'checkpoint',
        'started_at',
        'completed_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'date_from' => 'datetime',
        'date_to' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'posts_fetched' => 'integer',
        'posts_classified' => 'integer',
        'posts_extracted' => 'integer',
        'ideas_found' => 'integer',
    ];

    /**
     * Get the subreddit this scan belongs to.
     */
    public function subreddit(): BelongsTo
    {
        return $this->belongsTo(Subreddit::class);
    }

    /**
     * Get all posts fetched in this scan.
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * Get all ideas found in this scan.
     */
    public function ideas(): HasMany
    {
        return $this->hasMany(Idea::class);
    }

    /**
     * Check if the scan is in progress.
     */
    public function isInProgress(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_FETCHING,
            self::STATUS_CLASSIFYING,
            self::STATUS_EXTRACTING,
        ], true);
    }

    /**
     * Check if the scan is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if the scan failed.
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Get progress percentage (0-100).
     */
    public function getProgressPercentAttribute(): int
    {
        return match ($this->status) {
            self::STATUS_PENDING => 0,
            self::STATUS_FETCHING => 25,
            self::STATUS_CLASSIFYING => 50,
            self::STATUS_EXTRACTING => 75,
            self::STATUS_COMPLETED => 100,
            self::STATUS_FAILED => 0,
            default => 0,
        };
    }

    /**
     * Get human-readable status message.
     */
    public function getStatusMessageAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Waiting to start...',
            self::STATUS_FETCHING => "Fetching posts... ({$this->posts_fetched} found)",
            self::STATUS_CLASSIFYING => "Classifying posts... ({$this->posts_classified}/{$this->posts_fetched})",
            self::STATUS_EXTRACTING => "Extracting ideas... ({$this->ideas_found} found)",
            self::STATUS_COMPLETED => "Completed - {$this->ideas_found} ideas found",
            self::STATUS_FAILED => "Failed: {$this->error_message}",
            default => 'Unknown status',
        };
    }

    /**
     * Mark the scan as started.
     */
    public function markAsStarted(): void
    {
        $this->update([
            'status' => self::STATUS_FETCHING,
            'started_at' => now(),
        ]);
    }

    /**
     * Mark the scan as completed.
     */
    public function markAsCompleted(): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        $this->subreddit->update([
            'last_scanned_at' => now(),
        ]);
    }

    /**
     * Mark the scan as failed.
     */
    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
            'completed_at' => now(),
        ]);
    }

    /**
     * Update status with progress.
     */
    public function updateStatus(string $status, array $progress = []): void
    {
        $this->update(array_merge(['status' => $status], $progress));
    }
}
