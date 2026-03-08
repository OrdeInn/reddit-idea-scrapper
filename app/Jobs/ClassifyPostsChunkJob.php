<?php

namespace App\Jobs;

use App\Exceptions\PermanentClassificationException;
use App\Exceptions\TransientClassificationException;
use App\Models\Classification;
use App\Models\ClassificationResult;
use App\Models\Post;
use App\Models\Scan;
use App\Services\LLM\DTOs\ClassificationRequest;
use App\Services\LLM\DTOs\ClassificationResponse;
use App\Services\LLM\LLMProviderFactory;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ClassifyPostsChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted.
     * Set to 1 because retries are handled per-post internally.
     * Re-running at queue level would re-process already-classified posts.
     */
    public int $tries = 1;

    /**
     * Job timeout in seconds.
     * Accommodates internal per-post retries with backoff.
     * Worst case: 10 posts × (120s LLM timeout + 3 × 30s backoff) ≈ 2100s.
     *
     * DEPLOYMENT REQUIREMENT: The queue connection's retry_after must exceed this value.
     * Use the dedicated 'redis-classify' connection (retry_after=2500) for these jobs.
     * Horizon's classify-chunk-supervisor must set timeout > 2400.
     */
    public int $timeout = 2400;

    /**
     * Create a new job instance.
     *
     * @param int $scanId The scan ID (scalar, NOT Eloquent model — safer for batch serialization)
     * @param array<int> $postIds Array of post IDs to classify in this chunk
     */
    public function __construct(
        public int $scanId,
        public array $postIds,
    ) {
        $this->onQueue('classify-chunk');
    }

    /**
     * Execute the job.
     */
    public function handle(LLMProviderFactory $providerFactory): void
    {
        // Check if the batch has been cancelled before processing
        if ($this->batch()?->cancelled()) {
            Log::info('Batch cancelled, skipping classification chunk', [
                'scan_id' => $this->scanId,
                'post_count' => count($this->postIds),
            ]);
            return;
        }

        // Empty chunk — nothing to do
        if (empty($this->postIds)) {
            return;
        }

        // Re-hydrate scan from scalar ID
        $scan = Scan::find($this->scanId);

        if (! $scan) {
            Log::warning('Scan no longer exists, skipping classification chunk', [
                'scan_id' => $this->scanId,
            ]);
            return;
        }

        if ($scan->isFailed() || $scan->isCompleted()) {
            Log::info('Scan already finished, skipping classification chunk', [
                'scan_id' => $scan->id,
                'status' => $scan->status,
            ]);
            return;
        }

        if ($scan->status !== Scan::STATUS_CLASSIFYING) {
            Log::info('Scan is not in classifying stage, skipping chunk', [
                'scan_id' => $scan->id,
                'status' => $scan->status,
            ]);
            return;
        }

        $providers = $providerFactory->classificationProviders();
        $maxAttempts = (int) config('llm.retry.max_attempts', 3);

        // Load posts by IDs with classification relationship to avoid N+1 on idempotency check
        $posts = Post::with('classification')->whereIn('id', $this->postIds)->get()->keyBy('id');

        Log::info('Processing classification chunk', [
            'scan_id' => $scan->id,
            'chunk_size' => count($this->postIds),
            'posts_found' => $posts->count(),
        ]);

        foreach ($this->postIds as $postId) {
            $post = $posts->get($postId);

            // Post deleted between dispatch and execution — skip gracefully
            if (! $post) {
                Log::warning('Post not found, skipping classification', [
                    'post_id' => $postId,
                    'scan_id' => $scan->id,
                ]);
                continue;
            }

            // Idempotency: skip if post already has a completed classification
            if ($post->classification !== null && $post->classification->classified_at !== null) {
                Log::debug('Post already classified, skipping', [
                    'post_id' => $post->id,
                    'scan_id' => $scan->id,
                ]);
                continue;
            }

            // Clean up any incomplete classification records from previous crashes
            // This prevents unique constraint violations on retry
            $deleted = Classification::where('post_id', $post->id)
                ->whereNull('classified_at')
                ->delete();

            if ($deleted > 0) {
                Log::warning('Cleaning up incomplete classification record from previous failure', [
                    'post_id' => $post->id,
                    'scan_id' => $scan->id,
                    'deleted_count' => $deleted,
                ]);
            }

            try {
                $this->classifySinglePost($post, $scan, $providers, $maxAttempts);

                Log::info('Classification complete for post', [
                    'post_id' => $post->id,
                    'scan_id' => $scan->id,
                ]);
            } catch (Throwable $e) {
                // Per-post failure after all retries exhausted — create discard record and continue
                Log::error('Classification failed for post after all retries, creating discard record', [
                    'post_id' => $post->id,
                    'scan_id' => $scan->id,
                    'error' => $e->getMessage(),
                ]);

                try {
                    // Use a locked transaction to prevent overwriting a good classification
                    // from a concurrent worker. Lock the post row as a stable lock target
                    // (always exists) to serialize all workers processing this post.
                    DB::transaction(function () use ($post, $scan) {
                        // Lock the post row as a stable, always-existing lock target
                        $lockedPost = Post::lockForUpdate()->find($post->id);

                        if (! $lockedPost) {
                            return; // Post deleted mid-process
                        }

                        $existing = Classification::where('post_id', $post->id)->first();

                        if ($existing && $existing->classified_at !== null) {
                            // A concurrent worker already completed classification — skip
                            Log::debug('Concurrent worker already classified post, skipping discard creation', [
                                'post_id' => $post->id,
                                'scan_id' => $scan->id,
                            ]);
                            return;
                        }

                        $configuredProviders = config('llm.classification.providers', []);

                        if ($existing) {
                            $existing->update([
                                'final_decision' => Classification::DECISION_DISCARD,
                                'combined_score' => 0.0,
                                'classified_at' => now(),
                            ]);
                            $classification = $existing;
                        } else {
                            $classification = Classification::create([
                                'post_id' => $post->id,
                                'final_decision' => Classification::DECISION_DISCARD,
                                'combined_score' => 0.0,
                                'expected_provider_count' => count($configuredProviders),
                                'classified_at' => now(),
                            ]);
                        }

                        foreach ($configuredProviders as $providerName) {
                            ClassificationResult::firstOrCreate(
                                [
                                    'classification_id' => $classification->id,
                                    'provider_name' => $providerName,
                                ],
                                [
                                    'verdict' => 'skip',
                                    'confidence' => 0.0,
                                    'category' => 'chunk-job-failed',
                                    'reasoning' => 'Classification chunk job failed after retries',
                                    'completed' => false,
                                ]
                            );
                        }

                        $scan->increment('posts_classified');
                    });
                } catch (Throwable $discardException) {
                    Log::error('Failed to create discard classification record', [
                        'post_id' => $post->id,
                        'scan_id' => $scan->id,
                        'error' => $discardException->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Run classification for one post with per-post retry loop.
     *
     * @param Post $post
     * @param Scan $scan
     * @param array<string, \App\Services\LLM\LLMProviderInterface> $providers Keyed by config key
     * @param int $maxAttempts
     * @throws Throwable When all retry attempts are exhausted
     */
    private function classifySinglePost(Post $post, Scan $scan, array $providers, int $maxAttempts): void
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $request = ClassificationRequest::fromPost($post);
                $results = $this->runProviders($providers, $request);

                DB::transaction(function () use ($post, $results, $scan, $attempt, $maxAttempts) {
                    $classification = $this->storeClassification($post, $results);
                    $this->processClassificationResults($classification, $results, $attempt, $maxAttempts);
                    $scan->increment('posts_classified');
                });

                // Success — return without throwing
                return;
            } catch (RuntimeException $e) {
                // RuntimeException is the transient retry signal from processClassificationResults
                $lastException = $e;

                if ($attempt < $maxAttempts) {
                    $backoff = min(2 ** $attempt, 30);
                    Log::warning('Transient classification failure, retrying', [
                        'post_id' => $post->id,
                        'scan_id' => $scan->id,
                        'attempt' => $attempt,
                        'max_attempts' => $maxAttempts,
                        'backoff_seconds' => $backoff,
                        'error' => $e->getMessage(),
                    ]);
                    sleep($backoff);
                    continue;
                }
                // All attempts exhausted — fall through to throw
            } catch (Throwable $e) {
                $lastException = $e;
                // Non-transient error — do not retry
                break;
            }
        }

        throw $lastException ?? new RuntimeException('Classification failed after all attempts');
    }

    /**
     * Run all classification providers in parallel and collect results.
     *
     * @param array<string, \App\Services\LLM\LLMProviderInterface> $providers Keyed by config key
     * @param ClassificationRequest $request
     * @return array<string, array{response: ?ClassificationResponse, completed: bool, error: ?string}>
     */
    private function runProviders(array $providers, ClassificationRequest $request): array
    {
        $tasks = [];

        foreach ($providers as $configKey => $provider) {
            $tasks[$configKey] = function () use ($provider, $request, $configKey) {
                try {
                    Log::debug('Running classification provider', [
                        'config_key' => $configKey,
                        'model' => $provider->getModelName(),
                    ]);

                    $response = $provider->classify($request);

                    Log::debug('Provider classification complete', [
                        'config_key' => $configKey,
                        'verdict' => $response->verdict,
                        'confidence' => $response->confidence,
                    ]);

                    return [
                        'response' => $response,
                        'completed' => true,
                        'error' => null,
                    ];
                } catch (TransientClassificationException $e) {
                    Log::warning('Transient provider error', [
                        'config_key' => $configKey,
                        'error' => $e->getMessage(),
                    ]);

                    return [
                        'response' => new ClassificationResponse(
                            verdict: 'skip',
                            confidence: 0.0,
                            category: 'transient-error',
                            reasoning: 'Classification provider temporarily unavailable',
                            rawResponse: [],
                        ),
                        'completed' => false,
                        'error' => 'transient',
                    ];
                } catch (PermanentClassificationException $e) {
                    Log::error('Permanent provider error', [
                        'config_key' => $configKey,
                        'error' => $e->getMessage(),
                    ]);

                    return [
                        'response' => new ClassificationResponse(
                            verdict: 'skip',
                            confidence: 0.0,
                            category: 'permanent-error',
                            reasoning: 'Classification provider unavailable',
                            rawResponse: [],
                        ),
                        'completed' => false,
                        'error' => 'permanent',
                    ];
                } catch (Throwable $e) {
                    Log::error('Unknown provider error', [
                        'config_key' => $configKey,
                        'error' => $e->getMessage(),
                        'exception_class' => get_class($e),
                    ]);

                    return [
                        'response' => new ClassificationResponse(
                            verdict: 'skip',
                            confidence: 0.0,
                            category: 'unknown-error',
                            reasoning: 'Classification provider error',
                            rawResponse: [],
                        ),
                        'completed' => false,
                        'error' => 'transient',
                    ];
                }
            };
        }

        return Concurrency::run($tasks);
    }

    /**
     * Store classification results in the database.
     *
     * @param Post $post
     * @param array<string, array{response: ?ClassificationResponse, completed: bool}> $results Keyed by config key
     * @return Classification
     */
    private function storeClassification(Post $post, array $results): Classification
    {
        $classification = Classification::create([
            'post_id' => $post->id,
            'expected_provider_count' => count(config('llm.classification.providers', [])),
        ]);

        foreach ($results as $configKey => $result) {
            $response = $result['response'];
            $completed = $result['completed'];

            ClassificationResult::create([
                'classification_id' => $classification->id,
                'provider_name' => $configKey,
                'verdict' => $response->verdict,
                'confidence' => $response->confidence,
                'category' => $response->category,
                'reasoning' => $response->reasoning,
                'completed' => $completed,
                'completed_at' => $completed ? now() : null,
            ]);
        }

        // Load results relationship after creating
        $classification->load('results');

        Log::debug('Classification record created', [
            'classification_id' => $classification->id,
            'post_id' => $post->id,
        ]);

        return $classification;
    }

    /**
     * Process classification results and determine final decision.
     * Handles per-post retry logic using attempt context instead of Laravel queue-level retries.
     *
     * @param Classification $classification
     * @param array<string, array{response: ?ClassificationResponse, completed: bool, error: ?string}> $results Keyed by config key
     * @param int $attempt Current attempt number
     * @param int $maxAttempts Maximum allowed attempts
     * @throws RuntimeException When transient retry is needed
     */
    private function processClassificationResults(
        Classification $classification,
        array $results,
        int $attempt,
        int $maxAttempts
    ): void {
        $completedCount = count(array_filter($results, fn ($r) => $r['completed']));
        $totalCount = count($results);
        $hasTransientError = collect($results)->contains(fn ($r) => $r['error'] === 'transient');
        $isNotFinalAttempt = $attempt < $maxAttempts;

        // Case 1: All completed — use model's consensus logic
        if ($completedCount === $totalCount) {
            $classification->processResults();
            return;
        }

        // Case 2: Has transient error and not final attempt → retry
        if ($hasTransientError && $isNotFinalAttempt) {
            Log::warning('Partial classification failure with transient error, will retry', [
                'post_id' => $classification->post_id,
                'scan_id' => $this->scanId,
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts,
                'completed_count' => $completedCount,
                'total_count' => $totalCount,
            ]);

            // Delete the partial classification record (will be recreated on retry)
            $classification->delete();

            // Throw RuntimeException as retry signal to classifySinglePost()
            throw new RuntimeException('Classification partially failed with transient error');
        }

        // Case 3: All failed → discard
        if ($completedCount === 0) {
            $classification->final_decision = Classification::DECISION_DISCARD;
            $classification->combined_score = 0.0;
            $classification->classified_at = now();
            $classification->save();

            Log::debug('All classification providers failed, marked as discard', [
                'classification_id' => $classification->id,
                'post_id' => $classification->post_id,
            ]);
            return;
        }

        // Case 4: Partial completion (final attempt or permanent error) → fallback
        // Use same formula as full consensus but denominator is expected_provider_count (not completed)
        if ($hasTransientError) {
            Log::warning('Partial classification failure on final attempt, using fallback', [
                'post_id' => $classification->post_id,
                'scan_id' => $this->scanId,
                'attempt' => $attempt,
                'completed_count' => $completedCount,
                'total_count' => $totalCount,
            ]);
        } else {
            Log::info('Partial classification failure with permanent error, using fallback', [
                'post_id' => $classification->post_id,
                'scan_id' => $this->scanId,
                'completed_count' => $completedCount,
                'total_count' => $totalCount,
            ]);
        }

        $completedResults = collect($results)->filter(fn ($r) => $r['completed']);
        $sum = $completedResults->sum(fn ($r) =>
            ($r['response']->confidence ?? 0.0) * ($r['response']->verdict === 'keep' ? 1 : 0)
        );
        $combinedScore = $sum / $classification->expected_provider_count;

        $keepThreshold = (float) config('llm.classification.consensus_threshold_keep', 0.6);
        $discardThreshold = (float) config('llm.classification.consensus_threshold_discard', 0.4);

        $classification->combined_score = $combinedScore;
        $classification->final_decision = Classification::determineFinalDecision($combinedScore, $keepThreshold, $discardThreshold);
        $classification->classified_at = now();
        $classification->save();

        Log::debug('Partial provider completion, applied fallback logic', [
            'classification_id' => $classification->id,
            'post_id' => $classification->post_id,
            'completed_count' => $completedCount,
            'total_count' => $totalCount,
            'final_decision' => $classification->final_decision,
        ]);
    }

    /**
     * Handle permanent job failure.
     * Creates discard records for all unprocessed posts in the chunk to prevent scan from getting stuck.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('ClassifyPostsChunkJob failed permanently', [
            'scan_id' => $this->scanId,
            'post_count' => count($this->postIds),
            'error' => $exception->getMessage(),
        ]);

        $scan = Scan::find($this->scanId);
        $configuredProviders = config('llm.classification.providers', []);

        foreach ($this->postIds as $postId) {
            try {
                // Use a locked transaction to prevent overwriting a concurrent successful classification.
                // Lock the post row as a stable lock target to serialize all workers for this post.
                DB::transaction(function () use ($postId, $scan, $configuredProviders) {
                    $lockedPost = Post::lockForUpdate()->find($postId);

                    if (! $lockedPost) {
                        return; // Post deleted mid-process
                    }

                    $existing = Classification::where('post_id', $postId)->first();

                    // Skip if already has a completed classification
                    if ($existing && $existing->classified_at !== null) {
                        return;
                    }

                    if ($existing) {
                        $existing->update([
                            'final_decision' => Classification::DECISION_DISCARD,
                            'combined_score' => 0.0,
                            'classified_at' => now(),
                        ]);
                        $classification = $existing;
                    } else {
                        $classification = Classification::create([
                            'post_id' => $postId,
                            'final_decision' => Classification::DECISION_DISCARD,
                            'combined_score' => 0.0,
                            'expected_provider_count' => count($configuredProviders),
                            'classified_at' => now(),
                        ]);
                    }

                    foreach ($configuredProviders as $providerName) {
                        ClassificationResult::firstOrCreate(
                            [
                                'classification_id' => $classification->id,
                                'provider_name' => $providerName,
                            ],
                            [
                                'verdict' => 'skip',
                                'confidence' => 0.0,
                                'category' => 'chunk-job-failed',
                                'reasoning' => 'Classification chunk job failed permanently',
                                'completed' => false,
                            ]
                        );
                    }

                    if ($scan) {
                        $scan->increment('posts_classified');
                    }
                });
            } catch (Throwable $e) {
                Log::error('Failed to create discard classification record in chunk failed handler', [
                    'post_id' => $postId,
                    'scan_id' => $this->scanId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
