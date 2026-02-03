<?php

namespace App\Console\Commands;

use App\Models\Idea;
use App\Models\Post;
use App\Models\Scan;
use App\Services\LLM\DTOs\ExtractionRequest;
use App\Services\LLM\DTOs\IdeaDTO;
use App\Services\LLM\LLMProviderFactory;
use App\Services\LLM\LLMProviderInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScanExtractCommand extends Command
{
    protected $signature = 'scan:extract {--scan= : Scan ID to process posts from} {--post= : Single post ID to extract from} {--limit= : Max posts to process} {--dry-run : Run LLM but do not save ideas}';

    protected $description = 'Extract ideas from classified posts via LLM synchronously for debugging';

    public function handle(LLMProviderFactory $factory): int
    {
        try {
            $startTime = microtime(true);

            // Validate options
            $scanId = $this->option('scan');
            $postId = $this->option('post');
            $limit = $this->option('limit') ? (int) $this->option('limit') : null;
            $dryRun = $this->option('dry-run');

            // Validate limit is positive
            if ($limit !== null && $limit <= 0) {
                $this->error('--limit must be a positive integer');

                return self::FAILURE;
            }

            // Option validation: either --scan or --post required
            if (! $scanId && ! $postId) {
                $this->error('Either --scan or --post is required');

                return self::FAILURE;
            }

            // Resolve posts to extract
            $result = $this->resolvePostsToExtract($scanId, $postId, $limit);

            // Check for error (returns null on invalid ID)
            if ($result === null) {
                return self::FAILURE;
            }

            $posts = $result;

            if ($posts->isEmpty()) {
                $this->info('No posts need extraction');

                return self::SUCCESS;
            }

            $this->info("Extracting ideas from {$posts->count()} posts...");

            // Track extraction results
            $results = [
                'processed' => 0,
                'total_ideas' => 0,
                'errors' => 0,
            ];

            // Get extraction provider and validate it supports extraction
            $provider = $factory->extractionProvider();
            if (! $provider->supportsExtraction()) {
                $this->error('Configured extraction provider does not support extraction');

                return self::FAILURE;
            }

            $progressBar = $this->output->createProgressBar($posts->count());
            $progressBar->start();

            $isScanMode = ! empty($scanId);
            $scan = $isScanMode ? Scan::findOrFail($scanId) : null;

            foreach ($posts as $post) {
                $progressBar->advance();

                try {
                    // Double-check post hasn't been extracted already (concurrency safety)
                    if ($post->isExtracted()) {
                        $this->displayPostSkipped($post, 'Already extracted');

                        continue;
                    }

                    // Check if post has classification
                    if (! $post->classification) {
                        $this->displayPostSkipped($post, 'Post not yet classified, skipping');

                        continue;
                    }

                    // Check if classification is discarded
                    if ($post->classification->final_decision === 'discard') {
                        $this->displayPostSkipped($post, 'Post was discarded (final_decision=discard)');

                        continue;
                    }

                    // Run extraction
                    $response = $this->extractFromPost($post, $provider);

                    // Check for network errors that should be reported as errors
                    if (($response->rawResponse['error'] ?? null) === 'network-error') {
                        $this->displayPostError($post, 'Network error during extraction (will retry on next run)');
                        $results['errors']++;

                        continue;
                    }

                    // Display result
                    $this->displayPostResult($post, $response);

                    // Store ideas (or simulate in dry-run)
                    if ($dryRun) {
                        // In dry-run, respect max_ideas_per_post limit
                        $maxIdeas = config('llm.extraction.max_ideas_per_post', 5);
                        $ideasCount = min($response->count(), $maxIdeas);
                    } else {
                        // Store ideas and mark post as extracted in atomic transaction
                        $derivedScan = $isScanMode ? $scan : ($post->scan_id ? $post->scan : null);
                        $ideasCount = $this->storeIdeas($post, $derivedScan, $response, $isScanMode);
                    }

                    $results['total_ideas'] += $ideasCount;
                    $results['processed']++;
                } catch (\Exception $e) {
                    $this->displayPostError($post, $e->getMessage());
                    $results['errors']++;
                    Log::error('Extraction error', [
                        'post_id' => $post->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $progressBar->finish();
            $this->newLine(2);

            $elapsedTime = microtime(true) - $startTime;
            $this->displaySummary(
                $results['processed'],
                $results['total_ideas'],
                $dryRun,
                $elapsedTime,
                $results['errors']
            );

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error: '.$e->getMessage());
            Log::error('ScanExtractCommand failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return self::FAILURE;
        }
    }

    /**
     * Resolve posts to extract based on options.
     * Returns null on error (invalid ID), or Collection (possibly empty).
     */
    private function resolvePostsToExtract(?string $scanId, ?string $postId, ?int $limit): ?Collection
    {
        if ($postId) {
            $post = Post::with('classification')->find($postId);
            if (! $post) {
                $this->error("Post ID {$postId} not found");

                return null;
            }

            // In post mode, validate the post meets extraction eligibility
            if (! $post->classification) {
                $this->error("Post {$postId} has not been classified yet");

                return null;
            }

            if (! in_array($post->classification->final_decision, ['keep', 'borderline'], true)) {
                $this->error("Post {$postId} classification is '{$post->classification->final_decision}' (not eligible for extraction)");

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
                ->needsExtraction()
                ->with(['subreddit', 'comments' => fn ($q) => $q->orderByDesc('upvotes')->limit(100), 'classification'])
                ->orderBy('id', 'ASC');

            if ($limit) {
                $query->limit($limit);
            }

            return $query->get();
        }

        return collect();
    }

    /**
     * Run extraction on single post.
     */
    private function extractFromPost(Post $post, LLMProviderInterface $provider)
    {
        // Load relationships if not already loaded
        if (! $post->relationLoaded('comments')) {
            $post->load([
                'comments' => fn ($q) => $q->orderByDesc('upvotes')->limit(100),
            ]);
        }
        if (! $post->relationLoaded('subreddit')) {
            $post->load('subreddit');
        }

        // Create extraction request from post
        $request = ExtractionRequest::fromPost($post);

        // Run extraction
        return $provider->extract($request);
    }

    /**
     * Store extracted ideas in database, mark post as extracted, and update scan counter atomically.
     * Returns count of ideas stored.
     */
    private function storeIdeas(Post $post, ?Scan $scan, $response, bool $isScanMode): int
    {
        $count = 0;
        $classificationStatus = $post->classification?->final_decision ?? 'keep';
        $maxIdeas = config('llm.extraction.max_ideas_per_post', 5);

        DB::transaction(function () use ($response, $post, $scan, $classificationStatus, $maxIdeas, $isScanMode, &$count) {
            // Store ideas if any exist
            if ($response->hasIdeas()) {
                foreach ($response->ideas as $ideaDTO) {
                    $ideaData = $ideaDTO->toArray();

                    Idea::create(array_merge($ideaData, [
                        'post_id' => $post->id,
                        'scan_id' => $scan?->id,
                        'classification_status' => $classificationStatus,
                    ]));

                    $count++;

                    // Limit ideas per post
                    if ($count >= $maxIdeas) {
                        Log::debug('Reached max ideas per post limit', [
                            'post_id' => $post->id,
                            'limit' => $maxIdeas,
                        ]);
                        break;
                    }
                }
            }

            // Mark post as extracted (even if no ideas found)
            $post->markAsExtracted();

            // Update scan ideas counter within transaction for atomicity (scan mode only)
            if ($isScanMode && $scan) {
                $scan->increment('ideas_found', $count);
            }
        });

        if ($count === 0) {
            Log::debug('No ideas found in post', ['post_id' => $post->id]);
        }

        return $count;
    }

    /**
     * Display result for a single post with extracted ideas.
     */
    private function displayPostResult(Post $post, $response): void
    {
        $titleDisplay = substr($post->title, 0, 60).(strlen($post->title) > 60 ? '...' : '');

        // Display post header
        $this->line('');
        $this->line("  <info>{$titleDisplay}</info>");
        $this->line("  <fg=gray>{$post->reddit_url}</>");

        // Display classification status
        if ($post->classification) {
            $decisionColor = match ($post->classification->final_decision) {
                'keep' => 'green',
                'borderline' => 'yellow',
                'discard' => 'red',
                default => 'white',
            };
            $this->line("  Classification: <fg={$decisionColor}>{$post->classification->final_decision}</>");
        }

        // Display ideas
        if (! $response->hasIdeas()) {
            $this->line('  <fg=yellow>No viable ideas found</>');

            return;
        }

        $this->line("  <fg=green>✓ {$response->count()} ideas found</>");

        foreach ($response->ideas as $index => $idea) {
            $this->displayIdeaDetails($idea, $index + 1);
        }
    }

    /**
     * Display detailed information for a single idea.
     */
    private function displayIdeaDetails(IdeaDTO $idea, int $index): void
    {
        $this->newLine();
        $this->line("    <fg=cyan>[Idea {$index}]</>");
        $this->line("    <fg=white><strong>Title:</strong></> {$idea->ideaTitle}");
        $this->line("    <fg=white><strong>Problem:</strong></> {$idea->problemStatement}");
        $this->line("    <fg=white><strong>Solution:</strong></> {$idea->proposedSolution}");
        $this->line("    <fg=white><strong>Target Audience:</strong></> {$idea->targetAudience}");
        $this->line("    <fg=white><strong>Monetization:</strong></> {$idea->monetizationModel}");

        // Display scores
        $scores = $idea->scores;
        $scoreMonetization = $scores['monetization'] ?? 0;
        $scoreSaturation = $scores['market_saturation'] ?? 0;
        $scoreComplexity = $scores['complexity'] ?? 0;
        $scoreDemand = $scores['demand_evidence'] ?? 0;
        $scoreOverall = $scores['overall'] ?? 0;

        $this->line('    <fg=white><strong>Scores:</strong></>');
        $this->line("      Monetization: <fg=yellow>{$scoreMonetization}/10</>");
        $this->line("      Saturation: <fg=yellow>{$scoreSaturation}/10</>");
        $this->line("      Complexity: <fg=yellow>{$scoreComplexity}/10</>");
        $this->line("      Demand: <fg=yellow>{$scoreDemand}/10</>");
        $this->line("      Overall: <fg=cyan><strong>{$scoreOverall}/10</strong></>");
    }

    /**
     * Display when a post is skipped.
     */
    private function displayPostSkipped(Post $post, string $reason): void
    {
        $titleDisplay = substr($post->title, 0, 60).(strlen($post->title) > 60 ? '...' : '');
        $this->line("  <comment>{$titleDisplay}</comment> <fg=yellow>{$reason}</>");
    }

    /**
     * Display error for a post.
     */
    private function displayPostError(Post $post, string $error): void
    {
        $titleDisplay = substr($post->title, 0, 60).(strlen($post->title) > 60 ? '...' : '');
        $this->line("<fg=red>✗</> {$titleDisplay}");
        $this->line("  <fg=red>Error: {$error}</>");
    }

    /**
     * Display summary statistics.
     */
    private function displaySummary(int $postsProcessed, int $totalIdeas, bool $dryRun, float $elapsedTime, int $errors): void
    {
        $prefix = $dryRun ? '[DRY-RUN] ' : '';

        $this->info($prefix.'Extraction Summary');

        $summaryRows = [
            ['Posts processed', $postsProcessed],
            ['Total ideas found', "<fg=green>{$totalIdeas}</>"],
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
