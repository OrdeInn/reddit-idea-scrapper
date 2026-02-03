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

class ClassifyPostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Number of seconds to wait before retrying.
     */
    public int $backoff = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Scan $scan,
        public Post $post,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(LLMProviderFactory $providerFactory): void
    {
        $scan = $this->scan->fresh();
        $post = $this->post->fresh();

        // Guard: Skip if scan no longer exists or is in a terminal state
        if (! $scan) {
            Log::warning('Scan no longer exists, skipping classification', ['scan_id' => $this->scan->id]);
            return;
        }

        if (! $post) {
            Log::warning('Post no longer exists, skipping classification', ['post_id' => $this->post->id]);
            return;
        }

        if ($scan->isFailed() || $scan->isCompleted()) {
            Log::info('Scan already finished, skipping classification', [
                'scan_id' => $scan->id,
                'status' => $scan->status,
            ]);
            return;
        }

        // Only proceed if scan is in classifying stage
        if ($scan->status !== Scan::STATUS_CLASSIFYING) {
            Log::info('Scan is not in classifying stage, skipping', [
                'scan_id' => $scan->id,
                'status' => $scan->status,
            ]);
            return;
        }

        // Idempotency: Skip if post already has COMPLETED classification
        // Note: During retry, we delete partial classifications, so only check for completed ones
        if ($post->classification !== null && $post->classification->classified_at !== null) {
            Log::debug('Post already classified, skipping', [
                'post_id' => $post->id,
                'scan_id' => $scan->id,
                'classification_id' => $post->classification->id,
            ]);
            return;
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

        Log::info('Starting classification for post', [
            'post_id' => $post->id,
            'scan_id' => $scan->id,
            'reddit_id' => $post->reddit_id,
        ]);

        // Get classification providers from factory
        $providers = $providerFactory->classificationProviders();

        // Create classification request from post
        $request = ClassificationRequest::fromPost($post);

        // Run providers and collect results
        $results = $this->runProviders($providers, $request);

        // Wrap classification storage and scan increment in transaction for atomicity
        // This prevents crash window where classification is saved but scan is not incremented
        $classification = DB::transaction(function () use ($post, $results, $scan) {
            // Store classification results
            $classification = $this->storeClassification($post, $results);

            // Process results to calculate consensus or handle partial failures
            $this->processClassificationResults($classification, $results);

            // Update scan progress
            $scan->increment('posts_classified');

            return $classification;
        });

        Log::info('Classification complete for post', [
            'post_id' => $post->id,
            'scan_id' => $scan->id,
            'final_decision' => $classification->final_decision,
            'combined_score' => $classification->combined_score,
        ]);
    }

    /**
     * Run all providers in parallel and collect results.
     *
     * @param array $providers
     * @param ClassificationRequest $request
     * @return array<string, array{response: ?ClassificationResponse, completed: bool, error: ?string}>
     */
    private function runProviders(array $providers, ClassificationRequest $request): array
    {
        // Build tasks array keyed by provider name
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
                    // Transient error - should retry
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
                    // Permanent error - use fallback immediately
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
                    // Unknown error - treat as transient to be safe
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

        // Run all providers in parallel
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

            // Map provider name to column prefix
            $prefix = match ($providerName) {
                'synthetic' => 'kimi',
                'openai' => 'gpt',
                default => throw new RuntimeException("Unknown provider: {$providerName}"),
            };

            // Map response data to columns
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
     *
     * Handles four cases:
     * 1. Both models succeeded: Use model's processResults() for consensus
     * 2. Partial failure with transient error on non-final attempt: Retry job
     * 3. Both models failed: Mark as discard
     * 4. Only one model succeeded (or final attempt): Use single model result
     *
     * @param Classification $classification
     * @param array<string, array{response: ?ClassificationResponse, completed: bool, error: ?string}> $results
     * @throws RuntimeException When retry is needed
     */
    private function processClassificationResults(Classification $classification, array $results): void
    {
        $kimiCompleted = $results['synthetic']['completed'] ?? false;
        $gptCompleted = $results['openai']['completed'] ?? false;

        // Case 1: Both models succeeded - use model's consensus logic
        if ($kimiCompleted && $gptCompleted) {
            $classification->processResults();
            return;
        }

        // Case 2: Retry logic for partial failures with transient errors
        if (! $kimiCompleted || ! $gptCompleted) {
            $kimiError = $results['synthetic']['error'] ?? null;
            $gptError = $results['openai']['error'] ?? null;

            // Check if any error is transient
            $hasTransientError = $kimiError === 'transient' || $gptError === 'transient';

            // Check if we're on a non-final attempt
            $currentAttempt = $this->attempts();
            $isNotFinalAttempt = $currentAttempt < $this->tries;

            // If we have a transient error and retries remain, trigger retry
            if ($hasTransientError && $isNotFinalAttempt) {
                Log::warning('Partial classification failure with transient error, will retry', [
                    'post_id' => $classification->post_id,
                    'scan_id' => $this->scan->id,
                    'attempt' => $currentAttempt,
                    'max_attempts' => $this->tries,
                    'kimi_completed' => $kimiCompleted,
                    'kimi_error' => $kimiError,
                    'gpt_completed' => $gptCompleted,
                    'gpt_error' => $gptError,
                ]);

                // Delete the partial classification record (will be recreated on retry)
                $classification->delete();

                // Throw exception to trigger Laravel queue retry
                throw new RuntimeException(
                    'Classification partially failed with transient error, retrying'
                );
            }

            // Log which path we're taking (retry exhausted or permanent error)
            if ($hasTransientError) {
                Log::warning('Partial classification failure on final attempt, using fallback', [
                    'post_id' => $classification->post_id,
                    'scan_id' => $this->scan->id,
                    'attempt' => $currentAttempt,
                    'kimi_completed' => $kimiCompleted,
                    'gpt_completed' => $gptCompleted,
                ]);
            } else {
                Log::info('Partial classification failure with permanent error, using fallback', [
                    'post_id' => $classification->post_id,
                    'scan_id' => $this->scan->id,
                    'kimi_completed' => $kimiCompleted,
                    'gpt_completed' => $gptCompleted,
                ]);
            }
        }

        // Case 2: Both models failed - mark as discard
        if (! $kimiCompleted && ! $gptCompleted) {
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

        // Case 3: Only one model succeeded - use that model's result
        // If the successful model says keep, mark as borderline (reduced confidence)
        // If the successful model says skip, mark as discard
        if ($kimiCompleted && ! $gptCompleted) {
            $verdict = $results['synthetic']['response']->verdict;
            $confidence = $results['synthetic']['response']->confidence;

            if ($verdict === 'keep') {
                $classification->final_decision = Classification::DECISION_BORDERLINE;
                $classification->combined_score = $confidence * 0.5; // Reduced confidence
            } else {
                $classification->final_decision = Classification::DECISION_DISCARD;
                $classification->combined_score = 0.0;
            }
        } elseif ($gptCompleted && ! $kimiCompleted) {
            $verdict = $results['openai']['response']->verdict;
            $confidence = $results['openai']['response']->confidence;

            if ($verdict === 'keep') {
                $classification->final_decision = Classification::DECISION_BORDERLINE;
                $classification->combined_score = $confidence * 0.5; // Reduced confidence
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
            'kimi_completed' => $kimiCompleted,
            'gpt_completed' => $gptCompleted,
            'final_decision' => $classification->final_decision,
        ]);
    }

    /**
     * Handle job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('ClassifyPostJob failed permanently', [
            'scan_id' => $this->scan->id,
            'post_id' => $this->post->id,
            'error' => $exception->getMessage(),
        ]);

        // Create or update discard classification record so the post is not reprocessed
        try {
            $post = $this->post->fresh();
            if ($post) {
                // Check if there's an incomplete classification before updateOrCreate
                $existingClassification = Classification::where('post_id', $post->id)->first();
                $wasIncomplete = $existingClassification && $existingClassification->classified_at === null;

                // Use updateOrCreate to handle both new records and incomplete records from crashes
                $classification = Classification::updateOrCreate(
                    ['post_id' => $post->id],
                    [
                        'kimi_verdict' => 'skip',
                        'kimi_confidence' => 0.0,
                        'kimi_category' => 'job-failed',
                        'kimi_reasoning' => 'Classification job failed permanently',
                        'kimi_completed' => false,
                        'gpt_verdict' => 'skip',
                        'gpt_confidence' => 0.0,
                        'gpt_category' => 'job-failed',
                        'gpt_reasoning' => 'Classification job failed permanently',
                        'gpt_completed' => false,
                        'final_decision' => Classification::DECISION_DISCARD,
                        'combined_score' => 0.0,
                        'classified_at' => now(),
                    ]
                );

                // Increment scan progress if newly created OR completing an incomplete record
                if ($classification->wasRecentlyCreated || $wasIncomplete) {
                    $scan = $this->scan->fresh();
                    if ($scan) {
                        $scan->increment('posts_classified');
                    }
                }
            }
        } catch (Throwable $e) {
            Log::error('Failed to create/update discard classification record', [
                'post_id' => $this->post->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
