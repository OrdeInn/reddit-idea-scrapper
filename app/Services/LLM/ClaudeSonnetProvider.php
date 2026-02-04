<?php

namespace App\Services\LLM;

use App\Services\LLM\DTOs\ClassificationRequest;
use App\Services\LLM\DTOs\ClassificationResponse;
use App\Services\LLM\DTOs\ExtractionRequest;
use App\Services\LLM\DTOs\ExtractionResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ClaudeSonnetProvider extends BaseLLMProvider
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    /**
     * Get the provider name.
     */
    public function getProviderName(): string
    {
        return 'anthropic';
    }

    /**
     * Get the model name.
     */
    public function getModelName(): string
    {
        return $this->model;
    }

    /**
     * Get headers for Anthropic API.
     */
    protected function getHeaders(): array
    {
        return [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => self::API_VERSION,
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Get the API endpoint URL.
     */
    protected function getApiUrl(): string
    {
        return self::API_URL;
    }

    /**
     * Sonnet can classify but is not optimized for it.
     */
    public function classify(ClassificationRequest $request): ClassificationResponse
    {
        Log::warning('Using Claude Sonnet for classification is not cost-effective', [
            'provider' => $this->getProviderName(),
            'model' => $this->model,
        ]);

        $requestPayload = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $this->toAnthropicContent($request->getPromptContent()),
                ],
            ],
            'system' => 'You are a classification assistant. Analyze Reddit posts and classify them as potential SaaS idea sources. Respond only in valid JSON format.',
        ];

        $requestId = $this->getLogger()->logRequest(
            provider: $this->getProviderName(),
            model: $this->model,
            operation: 'classification',
            requestPayload: $requestPayload,
            postId: $request->postId
        );
        $startTime = microtime(true);

        try {
            $response = $this->client()->post($this->getApiUrl(), $requestPayload);
        } catch (ConnectionException $e) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            $this->getLogger()->logResponse(
                requestId: $requestId,
                provider: $this->getProviderName(),
                model: $this->model,
                operation: 'classification',
                parsedResult: [],
                durationMs: $durationMs,
                success: false,
                error: $e->getMessage(),
                postId: $request->postId
            );

            Log::error('Claude Sonnet API connection error', [
                'error' => $e->getMessage(),
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            return new ClassificationResponse(
                verdict: 'skip',
                confidence: 0.0,
                category: 'network-error',
                reasoning: 'Failed to connect to API',
                rawResponse: [],
            );
        }

        $durationMs = (microtime(true) - $startTime) * 1000;

        if ($response->failed()) {
            $status = $response->status();
            $error = $response->json('error.message') ?? $response->body();

            $this->getLogger()->logResponse(
                requestId: $requestId,
                provider: $this->getProviderName(),
                model: $this->model,
                operation: 'classification',
                parsedResult: [],
                durationMs: $durationMs,
                success: false,
                error: "HTTP {$status}: {$error}",
                postId: $request->postId
            );

            Log::error('Claude Sonnet API error', [
                'status' => $status,
                'error' => $error,
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            throw new RuntimeException("Claude Sonnet API error ({$status}): {$error}");
        }

        $data = $response->json();

        // Guard against non-JSON or invalid responses
        if (! is_array($data)) {
            $this->getLogger()->logResponse(
                requestId: $requestId,
                provider: $this->getProviderName(),
                model: $this->model,
                operation: 'classification',
                parsedResult: [],
                durationMs: $durationMs,
                success: false,
                error: 'Response is not valid JSON',
                postId: $request->postId
            );

            Log::warning('Claude Sonnet API returned invalid JSON response', [
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            return new ClassificationResponse(
                verdict: 'skip',
                confidence: 0.0,
                category: 'invalid-response',
                reasoning: 'API returned invalid response format',
                rawResponse: ['body' => substr($response->body(), 0, 2000)],
            );
        }

        $rawResponse = $data;

        // Extract content from Anthropic response format (handles multiple blocks)
        $content = $this->extractTextFromAnthropicContent($data['content'] ?? null);

        if (empty($content)) {
            $this->getLogger()->logResponse(
                requestId: $requestId,
                provider: $this->getProviderName(),
                model: $this->model,
                operation: 'classification',
                parsedResult: [],
                durationMs: $durationMs,
                success: false,
                error: 'Response content is empty',
                postId: $request->postId
            );

            Log::warning('Claude Sonnet API returned empty content', [
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            return new ClassificationResponse(
                verdict: 'skip',
                confidence: 0.0,
                category: 'error',
                reasoning: 'API returned empty response',
                rawResponse: $rawResponse,
            );
        }

        // Parse JSON response
        $parsed = $this->parseJsonResponse($content);

        if (empty($parsed)) {
            $this->getLogger()->logResponse(
                requestId: $requestId,
                provider: $this->getProviderName(),
                model: $this->model,
                operation: 'classification',
                parsedResult: [],
                durationMs: $durationMs,
                success: false,
                error: 'Failed to parse JSON response',
                postId: $request->postId
            );

            Log::warning('Failed to parse Claude Sonnet JSON response', [
                'content_length' => strlen($content),
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            return new ClassificationResponse(
                verdict: 'skip',
                confidence: 0.0,
                category: 'parse-error',
                reasoning: 'Failed to parse model response',
                rawResponse: $rawResponse,
            );
        }

        $this->getLogger()->logResponse(
            requestId: $requestId,
            provider: $this->getProviderName(),
            model: $this->model,
            operation: 'classification',
            parsedResult: $parsed,
            durationMs: $durationMs,
            success: true,
            postId: $request->postId
        );

        return ClassificationResponse::fromJson($parsed, $rawResponse);
    }

    /**
     * Extract SaaS ideas from a Reddit post using Claude Sonnet.
     */
    public function extract(ExtractionRequest $request): ExtractionResponse
    {
        $requestPayload = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $this->toAnthropicContent($request->getPromptContent()),
                ],
            ],
            'system' => $this->getExtractionSystemPrompt(),
        ];

        $requestId = $this->getLogger()->logRequest(
            provider: $this->getProviderName(),
            model: $this->model,
            operation: 'extraction',
            requestPayload: $requestPayload,
            postId: $request->postId
        );
        $startTime = microtime(true);

        try {
            $response = $this->client()->post($this->getApiUrl(), $requestPayload);
        } catch (ConnectionException $e) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            $this->getLogger()->logResponse(
                requestId: $requestId,
                provider: $this->getProviderName(),
                model: $this->model,
                operation: 'extraction',
                parsedResult: [],
                durationMs: $durationMs,
                success: false,
                error: $e->getMessage(),
                postId: $request->postId
            );

            Log::error('Claude Sonnet API connection error during extraction', [
                'error' => $e->getMessage(),
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            return new ExtractionResponse(
                ideas: collect(),
                rawResponse: ['error' => 'network-error', 'message' => 'Failed to connect to API'],
            );
        }

        $durationMs = (microtime(true) - $startTime) * 1000;

        if ($response->failed()) {
            $status = $response->status();
            $error = $response->json('error.message') ?? $response->body();

            $this->getLogger()->logResponse(
                requestId: $requestId,
                provider: $this->getProviderName(),
                model: $this->model,
                operation: 'extraction',
                parsedResult: [],
                durationMs: $durationMs,
                success: false,
                error: "HTTP {$status}: {$error}",
                postId: $request->postId
            );

            Log::error('Claude Sonnet API error during extraction', [
                'status' => $status,
                'error' => $error,
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            throw new RuntimeException("Claude Sonnet API error ({$status}): {$error}");
        }

        $data = $response->json();

        // Guard against non-JSON or invalid responses
        if (! is_array($data)) {
            $this->getLogger()->logResponse(
                requestId: $requestId,
                provider: $this->getProviderName(),
                model: $this->model,
                operation: 'extraction',
                parsedResult: [],
                durationMs: $durationMs,
                success: false,
                error: 'Response is not valid JSON',
                postId: $request->postId
            );

            Log::warning('Claude Sonnet API returned invalid JSON response during extraction', [
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            return new ExtractionResponse(
                ideas: collect(),
                rawResponse: ['body' => substr($response->body(), 0, 2000)],
            );
        }

        $rawResponse = $data;

        // Extract content from Anthropic response format (handles multiple blocks)
        $content = $this->extractTextFromAnthropicContent($data['content'] ?? null);

        if (empty($content)) {
            $this->getLogger()->logResponse(
                requestId: $requestId,
                provider: $this->getProviderName(),
                model: $this->model,
                operation: 'extraction',
                parsedResult: [],
                durationMs: $durationMs,
                success: false,
                error: 'Response content is empty',
                postId: $request->postId
            );

            Log::warning('Claude Sonnet API returned empty content during extraction', [
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            return new ExtractionResponse(
                ideas: collect(),
                rawResponse: $rawResponse,
            );
        }

        // Check for truncated output (max_tokens reached)
        $stopReason = $data['stop_reason'] ?? null;
        if ($stopReason === 'max_tokens') {
            Log::warning('Claude Sonnet response was truncated due to max_tokens', [
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);
        }

        $parsed = $this->parseJsonResponse($content);

        // Handle case where model returns an object with ideas array
        if (isset($parsed['ideas']) && is_array($parsed['ideas'])) {
            $parsed = $parsed['ideas'];
        }

        // If it's an associative array (single idea), wrap it
        if (! empty($parsed) && ! isset($parsed[0])) {
            $parsed = [$parsed];
        }

        $this->getLogger()->logResponse(
            requestId: $requestId,
            provider: $this->getProviderName(),
            model: $this->model,
            operation: 'extraction',
            parsedResult: $parsed,
            durationMs: $durationMs,
            success: true,
            postId: $request->postId
        );

        return ExtractionResponse::fromJson($parsed, $rawResponse);
    }

    /**
     * Get the system prompt for extraction.
     */
    private function getExtractionSystemPrompt(): string
    {
        return <<<'SYSTEM'
You are a SaaS opportunity analyst specializing in evaluating business ideas for small teams (1-3 developers max) with limited bootstrapped budgets (<$5k/month infrastructure) and no marketing or sales departments.

## CONTEXT: Who Is Building

- **Team Size**: 1-3 developers maximum
- **Budget**: Limited/bootstrapped (<$5k/month infrastructure)
- **Marketing**: NO marketing department, NO ad budget
- **Timeline**: MVP in weeks, not months
- **Sales**: No dedicated sales team, self-serve or simple outreach

## SCORING DEFINITIONS (Strict)

### Monetization (1-5) — Revenue model clarity and viability
- **1 POOR**: No clear path to revenue, needs massive scale. Examples: Social apps, content platforms
- **2 WEAK**: Revenue possible but difficult, requires heavy enterprise sales or long procurement cycles. Examples: Enterprise-only, complex B2B deals, high CAC
- **3 MODERATE**: Revenue path exists but competitive/unproven. Examples: Crowded SaaS markets, price-sensitive customers
- **4 GOOD**: Clear revenue model with identifiable paying customers. Examples: B2B with clear ROI, prosumer tools, self-serve SaaS
- **5 EXCELLENT**: Obvious willingness to pay, clear pricing anchors. Examples: Replacing expensive tools, solving costly problems

### Market Saturation (1-5) — 5 = wide open, 1 = extremely crowded
- **1 SATURATED**: Multiple well-funded competitors, big players dominate. Examples: CRM, project management, email marketing
- **2 CROWDED**: Many competitors, hard to differentiate. Examples: Most generic SaaS categories
- **3 COMPETITIVE**: Some competitors but room for differentiation. Examples: Niche verticals of crowded markets
- **4 EMERGING**: Few competitors, growing demand. Examples: New technology verticals
- **5 OPEN**: Clear underserved niche or unique integration gap. Examples: Specific workflow tools, novel platform integrations, workflows competitors haven't addressed
Note: If competitors are unknown from the post, state uncertainty rather than inventing competitors.

### Complexity (1-5) — 5 = easy to build, 1 = very complex (FROM SMALL TEAM PERSPECTIVE)
- **1 EXTREMELY COMPLEX**: Requires ML/AI expertise, massive data, real-time systems. Examples: Computer vision, recommendation engines
- **2 COMPLEX**: Significant challenges, multiple integrations, compliance. Examples: Financial platforms, healthcare tools
- **3 MODERATE**: Standard web app complexity, some integrations. Examples: Typical CRUD SaaS with third-party integrations
- **4 SIMPLE**: Straightforward implementation, minimal dependencies. Examples: Single-purpose tools, simple automation
- **5 TRIVIAL**: MVP in a weekend, minimal infrastructure. Examples: Chrome extensions, simple API wrappers

### Demand Evidence (1-5) — Strength of evidence from post/comments
- **1 NO EVIDENCE**: Speculation, no user validation in post
- **2 WEAK**: OP mentions problem but no corroboration
- **3 MODERATE**: Multiple people agree problem exists
- **4 STRONG**: People actively asking for solution or workarounds
- **5 EXPLICIT**: Comments saying "would pay for this" or "someone build this"

### Overall (1-5) — Holistic assessment for small team
- **1 Poor fit for small team (avoid)**
- **2 Challenging, significant risks**
- **3 Viable but requires careful execution**
- **4 Good opportunity for small team**
- **5 Exceptional fit — clear problem, audience, and path**

## PENALTY FACTORS (Lower Scores)

Apply these when present:
- Requires user-generated content or network effects to be useful
- Needs viral growth or social sharing to succeed
- Requires partnerships or platform approvals
- B2C mass market requiring paid acquisition or large scale (not B2C with SEO/community growth)
- Two-sided marketplace dynamics
- Requires trust/reputation system to function

## BONUS FACTORS (Higher Scores)

Apply these when present:
- Can market via SEO, content marketing, or direct outreach
- Clear niche community to target (subreddits, forums, Slack groups)
- Solves problem for a profession that pays for tools
- Integrates with existing paid ecosystems (Shopify, Salesforce, etc.)
- "Painkiller not vitamin" — solves urgent, costly problem

## SCORING GUIDANCE

- **DEFAULT TO LOWER SCORES** — a score of 4+ should be EXCEPTIONAL, not average
- If uncertain, round down not up
- Cite specific evidence from post/comments for each score
- Empty/weak evidence = lower score, never assume demand exists
- Posts with minimal comments: Demand evidence capped at 2 unless explicit signals
- Ambiguous market size: Default to 2-3 for saturation

## OUTPUT FORMAT

Always respond with a JSON array of ideas. Return an empty array [] if no viable ideas exist.

Each idea MUST include ALL fields from the schema provided in the user prompt, including:
- All top-level fields: idea_title, problem_statement, proposed_solution, target_audience, why_small_team_viable, demand_evidence, monetization_model, branding_suggestions, marketing_channels, existing_competitors, source_quote
- All scores with EXACT key names: monetization, monetization_reasoning, market_saturation, saturation_reasoning, complexity, complexity_reasoning, demand_evidence, demand_reasoning, overall, overall_reasoning
- red_flags: array of strings describing any concerns or caveats about the idea
- For existing_competitors: return as an array ([] if unknown from post)
- For source_quote: include exact quote from post/comments, or empty string if none applies

Do not omit fields, invent missing data, or create partial objects. Follow the user-provided JSON schema exactly.
SYSTEM;
    }

    /**
     * Convert text to Anthropic content blocks format.
     */
    private function toAnthropicContent(string $text): array
    {
        return [['type' => 'text', 'text' => $text]];
    }

    /**
     * Extract text from Anthropic content blocks.
     */
    private function extractTextFromAnthropicContent(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        if (! is_array($content)) {
            return '';
        }

        $texts = [];
        foreach ($content as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $texts[] = $block['text'];
            }
        }

        return trim(implode("\n", $texts));
    }
}
