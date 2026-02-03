<?php

namespace App\Console\Commands;

use App\Models\Classification;
use App\Models\Post;
use App\Models\Scan;
use App\Services\LLM\DTOs\ClassificationRequest;
use App\Services\LLM\LLMProviderFactory;
use App\Services\LLM\LLMProviderInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScanClassifyCommand extends Command
{
    protected $signature = 'scan:classify {--scan= : Scan ID to process posts from} {--post= : Single post ID to classify} {--limit= : Max posts to classify} {--provider=both : Provider to use (kimi, gpt, both)} {--dry-run : Run LLM but do not save results}';

    protected $description = 'Classify posts via LLM synchronously for debugging';

    public function handle(LLMProviderFactory $factory): int
    {
        try {
            $startTime = microtime(true);

            // Validate options
            $scanId = $this->option('scan');
            $postId = $this->option('post');
            $limit = $this->option('limit') ? (int) $this->option('limit') : null;
            $provider = $this->option('provider');
            $dryRun = $this->option('dry-run');

            // Validate limit is positive
            if ($limit !== null && $limit <= 0) {
                $this->error('--limit must be a positive integer');

                return self::FAILURE;
            }

            // Validate provider option
            if (! in_array($provider, ['kimi', 'gpt', 'both'], true)) {
                $this->error('--provider must be one of: kimi, gpt, both');

                return self::FAILURE;
            }

            // Option validation: either --scan or --post required
            if (! $scanId && ! $postId) {
                $this->error('Either --scan or --post is required');

                return self::FAILURE;
            }

            // Resolve posts to classify
            $result = $this->resolvePostsToClassify($scanId, $postId, $limit);

            // Check for error (returns null on invalid ID)
            if ($result === null) {
                return self::FAILURE;
            }

            $posts = $result;

            if ($posts->isEmpty()) {
                $this->info('No posts to classify');

                return self::SUCCESS;
            }

            // Get providers based on option
            $providers = $this->getProviders($factory, $provider);

            $this->info("Classifying {$posts->count()} posts using {$provider} provider(s)...");

            // Classify each post
            $results = [
                'kept' => 0,
                'discarded' => 0,
                'borderline' => 0,
                'errors' => 0,
            ];

            $progressBar = $this->output->createProgressBar($posts->count());
            $progressBar->start();

            $isScanMode = ! empty($scanId);
            $scan = $isScanMode ? Scan::find($scanId) : null;

            foreach ($posts as $post) {
                $progressBar->advance();

                try {
                    // Check if already classified
                    if ($post->classification !== null && $post->classification->classified_at !== null) {
                        $this->displayPostResult($post, [], null, 'already-classified');

                        continue;
                    }

                    // Classify the post
                    $classificationResults = $this->classifyPost($post, $providers);

                    // Store and finalize classification (or simulate in dry-run)
                    $classification = null;
                    if ($dryRun) {
                        // In dry-run, simulate the classification without touching the database
                        $classification = $this->createSimulatedClassification($post, $classificationResults);
                        // Do NOT delete incomplete classifications in dry-run
                    } else {
                        // Clean up incomplete classifications before processing
                        Classification::where('post_id', $post->id)
                            ->whereNull('classified_at')
                            ->delete();

                        $classification = DB::transaction(function () use ($post, $classificationResults, $scan, $isScanMode) {
                            $classification = $this->storeClassification($post, $classificationResults);
                            $this->processAndFinalizeClassification($classification, $classificationResults);

                            // Update scan counter in scan mode only
                            if ($isScanMode && $scan) {
                                $scan->increment('posts_classified');
                            }

                            return $classification;
                        });
                    }

                    // Display result
                    $this->displayPostResult($post, $classificationResults, $classification);

                    // Update counters
                    if ($classification) {
                        match ($classification->final_decision) {
                            'keep' => $results['kept']++,
                            'discard' => $results['discarded']++,
                            'borderline' => $results['borderline']++,
                        };
                    }
                } catch (\Exception $e) {
                    $this->displayPostResult($post, [], null, 'error', $e->getMessage());
                    $results['errors']++;
                    Log::error('Classification error', [
                        'post_id' => $post->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $progressBar->finish();
            $this->newLine(2);

            $elapsedTime = microtime(true) - $startTime;
            $this->displaySummary(
                $posts->count(),
                $results['kept'],
                $results['discarded'],
                $results['borderline'],
                $dryRun,
                $elapsedTime,
                $results['errors']
            );

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Error: '.$e->getMessage());
            Log::error('ScanClassifyCommand failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return self::FAILURE;
        }
    }

    /**
     * Resolve posts to classify based on options.
     * Returns null on error (invalid ID), or Collection (possibly empty).
     */
    private function resolvePostsToClassify(?string $scanId, ?string $postId, ?int $limit): ?Collection
    {
        if ($postId) {
            $post = Post::find($postId);
            if (! $post) {
                $this->error("Post ID {$postId} not found");

                return null;
            }

            return collect([$post]);
        }

        if ($scanId) {
            $scan = Scan::find($scanId);
            if (! $scan) {
                $this->error("Scan ID {$scanId} not found");

                return null;
            }

            $query = $scan->posts()
                ->where(function ($q) {
                    $q->whereDoesntHave('classification')
                        ->orWhereHas('classification', function ($subQ) {
                            $subQ->whereNull('classified_at');
                        });
                })
                ->with(['subreddit', 'classification'])
                ->orderBy('id', 'ASC');

            if ($limit) {
                $query->limit($limit);
            }

            return $query->get();
        }

        return collect();
    }

    /**
     * Get providers based on provider option.
     *
     * @return array<LLMProviderInterface>
     */
    private function getProviders(LLMProviderFactory $factory, string $provider): array
    {
        return match ($provider) {
            'kimi' => [LLMProviderFactory::make('synthetic-kimi')],
            'gpt' => [LLMProviderFactory::make('openai-gpt4-mini')],
            'both' => $factory->classificationProviders(),
        };
    }

    /**
     * Classify a single post.
     */
    private function classifyPost(Post $post, array $providers): array
    {
        $request = ClassificationRequest::fromPost($post);
        $results = [];

        foreach ($providers as $provider) {
            $results[$provider->getProviderName()] = $this->runProvider($provider, $request);
        }

        return $results;
    }

    /**
     * Run a single provider and return response data.
     */
    private function runProvider(LLMProviderInterface $provider, ClassificationRequest $request): array
    {
        try {
            $response = $provider->classify($request);

            return [
                'response' => $response,
                'completed' => true,
                'error' => null,
            ];
        } catch (\Exception $e) {
            Log::warning('Provider classification failed', [
                'provider' => $provider->getProviderName(),
                'error' => $e->getMessage(),
            ]);

            return [
                'response' => null,
                'completed' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Store classification record.
     */
    private function storeClassification(Post $post, array $results): Classification
    {
        $data = ['post_id' => $post->id];

        foreach ($results as $providerName => $result) {
            $response = $result['response'];
            $completed = $result['completed'];

            // Map provider name to column prefix
            $prefix = match ($providerName) {
                'synthetic' => 'kimi',
                'openai' => 'gpt',
                default => throw new \RuntimeException("Unknown LLM provider: {$providerName}"),
            };

            if ($response) {
                $data["{$prefix}_verdict"] = $response->verdict;
                $data["{$prefix}_confidence"] = $response->confidence;
                $data["{$prefix}_category"] = $response->category;
                $data["{$prefix}_reasoning"] = $response->reasoning;
            }
            $data["{$prefix}_completed"] = $completed;
        }

        return Classification::create($data);
    }

    /**
     * Process and finalize classification.
     */
    private function processAndFinalizeClassification(Classification $classification, array $results): void
    {
        $kimiCompleted = $results['synthetic']['completed'] ?? false;
        $gptCompleted = $results['openai']['completed'] ?? false;
        $numProvidersRequested = count($results);

        // Case 1: Both providers completed - use consensus logic
        if ($kimiCompleted && $gptCompleted) {
            $classification->processResults();

            return;
        }

        // Case 2: Both failed
        if (! $kimiCompleted && ! $gptCompleted) {
            $classification->final_decision = Classification::DECISION_DISCARD;
            $classification->combined_score = 0.0;
            $classification->classified_at = now();
            $classification->save();

            return;
        }

        // Case 3: Single provider succeeded - use direct verdict
        if ($kimiCompleted && ! $gptCompleted) {
            $verdict = $results['synthetic']['response']->verdict;
            $confidence = $results['synthetic']['response']->confidence;

            // Single provider mode: direct verdict, not reduced confidence
            if ($numProvidersRequested === 1) {
                $classification->final_decision = Classification::determineFinalDecision(
                    $confidence * ($verdict === 'keep' ? 1 : 0)
                );
                $classification->combined_score = $verdict === 'keep' ? $confidence : 0.0;
            } else {
                // Partial failure: reduce confidence
                $classification->final_decision = $verdict === 'keep'
                    ? Classification::DECISION_BORDERLINE
                    : Classification::DECISION_DISCARD;
                $classification->combined_score = $verdict === 'keep' ? $confidence * 0.5 : 0.0;
            }
        } elseif ($gptCompleted && ! $kimiCompleted) {
            $verdict = $results['openai']['response']->verdict;
            $confidence = $results['openai']['response']->confidence;

            // Single provider mode: direct verdict, not reduced confidence
            if ($numProvidersRequested === 1) {
                $classification->final_decision = Classification::determineFinalDecision(
                    $confidence * ($verdict === 'keep' ? 1 : 0)
                );
                $classification->combined_score = $verdict === 'keep' ? $confidence : 0.0;
            } else {
                // Partial failure: reduce confidence
                $classification->final_decision = $verdict === 'keep'
                    ? Classification::DECISION_BORDERLINE
                    : Classification::DECISION_DISCARD;
                $classification->combined_score = $verdict === 'keep' ? $confidence * 0.5 : 0.0;
            }
        }

        $classification->classified_at = now();
        $classification->save();
    }

    /**
     * Create a simulated classification for dry-run mode.
     * This calculates the final decision without persisting to database.
     * Reuses the decision logic from processAndFinalizeClassification but without saving.
     */
    private function createSimulatedClassification(Post $post, array $results): Classification
    {
        $kimiCompleted = $results['synthetic']['completed'] ?? false;
        $gptCompleted = $results['openai']['completed'] ?? false;
        $numProvidersRequested = count($results);

        // Build a temporary classification object (not saved)
        $classification = new Classification([
            'post_id' => $post->id,
        ]);

        // Populate provider data
        foreach ($results as $providerName => $result) {
            $response = $result['response'];
            $completed = $result['completed'];

            $prefix = match ($providerName) {
                'synthetic' => 'kimi',
                'openai' => 'gpt',
                default => throw new \RuntimeException("Unknown LLM provider: {$providerName}"),
            };

            if ($response) {
                $classification->{"{$prefix}_verdict"} = $response->verdict;
                $classification->{"{$prefix}_confidence"} = $response->confidence;
                $classification->{"{$prefix}_category"} = $response->category;
                $classification->{"{$prefix}_reasoning"} = $response->reasoning;
            }
            $classification->{"{$prefix}_completed"} = $completed;
        }

        // Calculate final decision - use same logic as processAndFinalizeClassification but without DB calls
        if ($kimiCompleted && $gptCompleted) {
            // Both providers succeeded - use consensus logic (mimic what processResults() does without saving)
            $shortcutDecision = Classification::checkShortcutRule(
                $classification->kimi_verdict,
                $classification->kimi_confidence,
                $classification->gpt_verdict,
                $classification->gpt_confidence
            );

            if ($shortcutDecision !== null) {
                $classification->combined_score = $shortcutDecision === Classification::DECISION_KEEP ? 1.0 : 0.0;
                $classification->final_decision = $shortcutDecision;
            } else {
                $classification->combined_score = Classification::calculateConsensusScore(
                    $classification->kimi_verdict,
                    $classification->kimi_confidence,
                    $classification->gpt_verdict,
                    $classification->gpt_confidence
                );
                $classification->final_decision = Classification::determineFinalDecision($classification->combined_score);
            }
        } elseif (! $kimiCompleted && ! $gptCompleted) {
            // Both failed
            $classification->final_decision = Classification::DECISION_DISCARD;
            $classification->combined_score = 0.0;
        } elseif ($kimiCompleted && ! $gptCompleted) {
            // Only Kimi succeeded
            $verdict = $results['synthetic']['response']->verdict;
            $confidence = $results['synthetic']['response']->confidence;

            if ($numProvidersRequested === 1) {
                $classification->final_decision = Classification::determineFinalDecision(
                    $confidence * ($verdict === 'keep' ? 1 : 0)
                );
                $classification->combined_score = $verdict === 'keep' ? $confidence : 0.0;
            } else {
                $classification->final_decision = $verdict === 'keep'
                    ? Classification::DECISION_BORDERLINE
                    : Classification::DECISION_DISCARD;
                $classification->combined_score = $verdict === 'keep' ? $confidence * 0.5 : 0.0;
            }
        } elseif ($gptCompleted && ! $kimiCompleted) {
            // Only GPT succeeded
            $verdict = $results['openai']['response']->verdict;
            $confidence = $results['openai']['response']->confidence;

            if ($numProvidersRequested === 1) {
                $classification->final_decision = Classification::determineFinalDecision(
                    $confidence * ($verdict === 'keep' ? 1 : 0)
                );
                $classification->combined_score = $verdict === 'keep' ? $confidence : 0.0;
            } else {
                $classification->final_decision = $verdict === 'keep'
                    ? Classification::DECISION_BORDERLINE
                    : Classification::DECISION_DISCARD;
                $classification->combined_score = $verdict === 'keep' ? $confidence * 0.5 : 0.0;
            }
        }

        $classification->classified_at = now();

        return $classification;
    }

    /**
     * Display result for a single post.
     */
    private function displayPostResult(Post $post, array $results, ?Classification $classification, string $status = 'normal', ?string $errorMsg = null): void
    {
        $titleDisplay = substr($post->title, 0, 60).(strlen($post->title) > 60 ? '...' : '');

        if ($status === 'already-classified') {
            $this->line("<comment>  {$titleDisplay}</comment> <fg=cyan>Already classified</>");

            return;
        }

        if ($status === 'error') {
            $this->line("<fg=red>✗</> {$titleDisplay}");
            if ($errorMsg) {
                $this->line("  <fg=red>Error: {$errorMsg}</>");
            }

            return;
        }

        // Normal display of classification result
        if (empty($results)) {
            return;
        }

        // Display post title and URL
        $this->line("  <info>{$titleDisplay}</info>");
        $this->line("  <fg=gray>{$post->reddit_url}</>");

        // Display providers' responses
        foreach ($results as $providerName => $result) {
            // Map provider names to user-friendly display names
            $displayName = match ($providerName) {
                'synthetic' => 'Kimi',
                'openai' => 'GPT-4',
                default => $providerName,
            };

            if (! isset($result['response']) || ! $result['response']) {
                $this->line("  <fg=yellow>⚠</> {$displayName}: Failed");
                if (isset($result['error'])) {
                    $this->line("    Error: {$result['error']}");
                }

                continue;
            }

            $response = $result['response'];
            $verdictSymbol = $response->verdict === 'keep' ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $confidenceDisplay = round($response->confidence * 100);

            $this->line("  {$verdictSymbol} {$displayName}: {$response->verdict} ({$confidenceDisplay}% confidence)");
            $this->line("    Category: {$response->category}");

            // Truncate reasoning to ~80 chars
            $reasoning = substr($response->reasoning, 0, 80);
            if (strlen($response->reasoning) > 80) {
                $reasoning .= '...';
            }
            $this->line("    Reason: {$reasoning}");
        }

        // Display final decision if available
        if ($classification) {
            $decisionColor = match ($classification->final_decision) {
                'keep' => 'green',
                'discard' => 'red',
                'borderline' => 'yellow',
                default => 'white',
            };
            $score = round($classification->combined_score * 100, 1);
            $this->line("  <fg={$decisionColor}><strong>Final: {$classification->final_decision}</strong></> (score: {$score}%)");
        }

        $this->newLine();
    }

    /**
     * Display summary statistics.
     */
    private function displaySummary(int $total, int $kept, int $discarded, int $borderline, bool $dryRun, float $elapsedTime, int $errors): void
    {
        $prefix = $dryRun ? '[DRY-RUN] ' : '';

        $this->info($prefix.'Classification Summary');

        $summaryRows = [
            ['Total classified', $total],
            ['Kept', "<fg=green>{$kept}</>"],
            ['Discarded', "<fg=red>{$discarded}</>"],
            ['Borderline', "<fg=yellow>{$borderline}</>"],
        ];

        if ($errors > 0) {
            $summaryRows[] = ['Errors', "<fg=red>{$errors}</>"];
        }

        $summaryRows[] = ['Time elapsed', sprintf('%.2f seconds', $elapsedTime)];

        $this->table(
            ['Metric', 'Value'],
            $summaryRows
        );
    }
}
