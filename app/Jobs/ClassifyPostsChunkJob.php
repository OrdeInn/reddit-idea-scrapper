<?php

namespace App\Jobs;

use App\Exceptions\PermanentClassificationException;
use App\Exceptions\TransientClassificationException;
use App\Models\Classification;
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

                        $discardData = [
                            'haiku_verdict' => 'skip',
                            'haiku_confidence' => 0.0,
                            'haiku_category' => 'chunk-job-failed',
                            'haiku_reasoning' => 'Classification chunk job failed after retries',
                            'haiku_completed' => false,
                            'gpt_verdict' => 'skip',
                            'gpt_confidence' => 0.0,
                            'gpt_category' => 'chunk-job-failed',
                            'gpt_reasoning' => 'Classification chunk job failed after retries',
                            'gpt_completed' => false,
                            'final_decision' => Classification::DECISION_DISCARD,
                            'combined_score' => 0.0,
                            'classified_at' => now(),
                        ];

                        if ($existing) {
                            $existing->update($discardData);
                        } else {
                            Classification::create(array_merge($discardData, ['post_id' => $post->id]));
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
     * Run dual-provider classification for one post with per-post retry loop.
     *
     * @param Post $post
     * @param Scan $scan
     * @param array $providers
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
     * @param array $providers
     * @param ClassificationRequest $request
     * @return array<string, array{response: ?ClassificationResponse, completed: bool, error: ?string}>
     */
    private function runProviders(array $providers, ClassificationRequest $request): array
    {
        $tasks = [];

        foreach ($providers as $provider) {
            $providerName = $provider->getProviderName();

            $tasks[$providerName] = function () use ($provider, $request, $providerName) {
                try {
                    Log::debug('Running classification provider', [
                        'provider' => $providerName,
                        'model' => $provider->getModelName(),
                    ]);

                    $response = $provider->classify($request);

                    Log::debug('Provider classification complete', [
                        'provider' => $providerName,
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
                        'provider' => $providerName,
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
                        'provider' => $providerName,
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
                        'provider' => $providerName,
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
     * @param array<string, array{response: ?ClassificationResponse, completed: bool}> $results
     * @return Classification
     */
    private function storeClassification(Post $post, array $results): Classification
    {
        $data = [
            'post_id' => $post->id,
        ];

        foreach ($results as $providerName => $result) {
            $response = $result['response'];
            $completed = $result['completed'];

            $prefix = match ($providerName) {
                'anthropic-haiku' => 'haiku',
                'openai' => 'gpt',
                default => throw new RuntimeException("Unknown provider: {$providerName}"),
            };

            $data["{$prefix}_verdict"] = $response->verdict;
            $data["{$prefix}_confidence"] = $response->confidence;
            $data["{$prefix}_category"] = $response->category;
            $data["{$prefix}_reasoning"] = $response->reasoning;
            $data["{$prefix}_completed"] = $completed;
        }

        $classification = Classification::create($data);

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
     * @param array<string, array{response: ?ClassificationResponse, completed: bool, error: ?string}> $results
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
        $haikuCompleted = $results['anthropic-haiku']['completed'] ?? false;
        $gptCompleted = $results['openai']['completed'] ?? false;

        // Case 1: Both models succeeded — use model's consensus logic
        if ($haikuCompleted && $gptCompleted) {
            $classification->processResults();
            return;
        }

        // Case 2: Retry logic for partial failures with transient errors
        $bothProvidersRequested = array_key_exists('anthropic-haiku', $results) && array_key_exists('openai', $results);

        if ($bothProvidersRequested && (! $haikuCompleted || ! $gptCompleted)) {
            $haikuError = $results['anthropic-haiku']['error'] ?? null;
            $gptError = $results['openai']['error'] ?? null;

            $hasTransientError = $haikuError === 'transient' || $gptError === 'transient';
            $isNotFinalAttempt = $attempt < $maxAttempts;

            if ($hasTransientError && $isNotFinalAttempt) {
                Log::warning('Partial classification failure with transient error, will retry', [
                    'post_id' => $classification->post_id,
                    'scan_id' => $this->scanId,
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'haiku_completed' => $haikuCompleted,
                    'haiku_error' => $haikuError,
                    'gpt_completed' => $gptCompleted,
                    'gpt_error' => $gptError,
                ]);

                // Delete the partial classification record (will be recreated on retry)
                $classification->delete();

                // Throw RuntimeException as retry signal to classifySinglePost()
                throw new RuntimeException('Classification partially failed with transient error');
            }

            if ($hasTransientError) {
                Log::warning('Partial classification failure on final attempt, using fallback', [
                    'post_id' => $classification->post_id,
                    'scan_id' => $this->scanId,
                    'attempt' => $attempt,
                    'haiku_completed' => $haikuCompleted,
                    'gpt_completed' => $gptCompleted,
                ]);
            } else {
                Log::info('Partial classification failure with permanent error, using fallback', [
                    'post_id' => $classification->post_id,
                    'scan_id' => $this->scanId,
                    'haiku_completed' => $haikuCompleted,
                    'gpt_completed' => $gptCompleted,
                ]);
            }
        } else if (! $bothProvidersRequested && (! $haikuCompleted || ! $gptCompleted)) {
            Log::debug('Single provider mode, using fallback logic', [
                'post_id' => $classification->post_id,
                'haiku_completed' => $haikuCompleted,
                'gpt_completed' => $gptCompleted,
            ]);
        }

        // Both models failed — mark as discard
        if (! $haikuCompleted && ! $gptCompleted) {
            $classification->final_decision = Classification::DECISION_DISCARD;
            $classification->combined_score = 0.0;
            $classification->classified_at = now();
            $classification->save();

            Log::debug('Both classification providers failed, marked as discard', [
                'classification_id' => $classification->id,
                'post_id' => $classification->post_id,
            ]);
            return;
        }

        // Only one model succeeded — apply confidence threshold fallback
        $confidenceThreshold = 0.7;

        if ($haikuCompleted && ! $gptCompleted) {
            $verdict = $results['anthropic-haiku']['response']->verdict;
            $confidence = $results['anthropic-haiku']['response']->confidence;

            if ($verdict === 'keep') {
                $classification->final_decision = $confidence >= $confidenceThreshold
                    ? Classification::DECISION_KEEP
                    : Classification::DECISION_BORDERLINE;
                $classification->combined_score = $confidence;
            } else {
                $classification->final_decision = Classification::DECISION_DISCARD;
                $classification->combined_score = 0.0;
            }
        } elseif ($gptCompleted && ! $haikuCompleted) {
            $verdict = $results['openai']['response']->verdict;
            $confidence = $results['openai']['response']->confidence;

            if ($verdict === 'keep') {
                $classification->final_decision = $confidence >= $confidenceThreshold
                    ? Classification::DECISION_KEEP
                    : Classification::DECISION_BORDERLINE;
                $classification->combined_score = $confidence;
            } else {
                $classification->final_decision = Classification::DECISION_DISCARD;
                $classification->combined_score = 0.0;
            }
        }

        $classification->classified_at = now();
        $classification->save();

        Log::debug('Single classification provider succeeded, applied fallback logic', [
            'classification_id' => $classification->id,
            'post_id' => $classification->post_id,
            'haiku_completed' => $haikuCompleted,
            'gpt_completed' => $gptCompleted,
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

        foreach ($this->postIds as $postId) {
            try {
                // Use a locked transaction to prevent overwriting a concurrent successful classification.
                // Lock the post row as a stable lock target to serialize all workers for this post.
                DB::transaction(function () use ($postId, $scan) {
                    $lockedPost = Post::lockForUpdate()->find($postId);

                    if (! $lockedPost) {
                        return; // Post deleted mid-process
                    }

                    $existing = Classification::where('post_id', $postId)->first();

                    // Skip if already has a completed classification
                    if ($existing && $existing->classified_at !== null) {
                        return;
                    }

                    $discardData = [
                        'haiku_verdict' => 'skip',
                        'haiku_confidence' => 0.0,
                        'haiku_category' => 'chunk-job-failed',
                        'haiku_reasoning' => 'Classification chunk job failed permanently',
                        'haiku_completed' => false,
                        'gpt_verdict' => 'skip',
                        'gpt_confidence' => 0.0,
                        'gpt_category' => 'chunk-job-failed',
                        'gpt_reasoning' => 'Classification chunk job failed permanently',
                        'gpt_completed' => false,
                        'final_decision' => Classification::DECISION_DISCARD,
                        'combined_score' => 0.0,
                        'classified_at' => now(),
                    ];

                    if ($existing) {
                        $existing->update($discardData);
                    } else {
                        Classification::create(array_merge($discardData, ['post_id' => $postId]));
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
