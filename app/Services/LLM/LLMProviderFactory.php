<?php

namespace App\Services\LLM;

use InvalidArgumentException;

class LLMProviderFactory
{
    /**
     * Create an LLM provider instance by name.
     *
     * @param string $providerName Provider key from config (e.g., 'claude-haiku')
     * @return LLMProviderInterface
     * @throws InvalidArgumentException If provider is not configured
     */
    public static function make(string $providerName): LLMProviderInterface
    {
        $config = config("llm.providers.{$providerName}");

        if (! is_array($config)) {
            throw new InvalidArgumentException("LLM provider '{$providerName}' is not configured or config is invalid");
        }

        $class = $config['class'] ?? null;

        if (! $class || ! class_exists($class)) {
            throw new InvalidArgumentException("LLM provider class for '{$providerName}' does not exist");
        }

        if (! is_subclass_of($class, LLMProviderInterface::class)) {
            throw new InvalidArgumentException("LLM provider class '{$class}' must implement LLMProviderInterface");
        }

        return new $class($config);
    }

    /**
     * Get classification providers based on config.
     *
     * @return array<LLMProviderInterface>
     */
    public static function getClassificationProviders(): array
    {
        $providerNames = config('llm.classification.providers', []);

        if (! is_array($providerNames)) {
            throw new InvalidArgumentException("Config 'llm.classification.providers' must be an array");
        }

        return array_map(
            fn ($name) => self::make($name),
            $providerNames
        );
    }

    /**
     * Get the extraction provider based on config.
     */
    public static function getExtractionProvider(): LLMProviderInterface
    {
        $providerName = config('llm.extraction.provider');

        if (! is_string($providerName) || empty($providerName)) {
            throw new InvalidArgumentException("Config 'llm.extraction.provider' must be a non-empty string");
        }

        return self::make($providerName);
    }

    /**
     * Get all available provider names.
     *
     * @return array<string>
     */
    public static function availableProviders(): array
    {
        $providers = config('llm.providers', []);

        if (! is_array($providers)) {
            return [];
        }

        return array_keys($providers);
    }
}
