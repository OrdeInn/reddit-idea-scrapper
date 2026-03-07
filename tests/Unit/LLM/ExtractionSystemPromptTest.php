<?php

namespace Tests\Unit\LLM;

use App\Services\LLM\Prompts\ExtractionSystemPrompt;
use Tests\TestCase;

class ExtractionSystemPromptTest extends TestCase
{
    public function test_returns_non_empty_string(): void
    {
        $prompt = ExtractionSystemPrompt::get();

        $this->assertIsString($prompt);
        $this->assertNotEmpty($prompt);
    }

    public function test_prompt_exceeds_minimum_length(): void
    {
        $prompt = ExtractionSystemPrompt::get();

        $this->assertGreaterThan(500, strlen($prompt));
    }

    public function test_contains_required_section_headers(): void
    {
        $prompt = ExtractionSystemPrompt::get();

        $this->assertStringContainsString('SCORING DEFINITIONS', $prompt);
        $this->assertStringContainsString('OUTPUT FORMAT', $prompt);
    }
}
