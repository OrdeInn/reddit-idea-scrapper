<?php

namespace App\Services\LLM;

use InvalidArgumentException;

class LLMProviderFactory
{
    /**
     * Instance wrapper around {@see self::getClassificationProviders()}.
     *
     * This allows jobs/services to depend on the container for testability.
     *
     * @return array<string, LLMProviderInterface> Keyed by config key
     */
    public function classificationProviders(): array
    {
        return self::getClassificationProviders();
    }

    /**
     * Instance wrapper around {@see self::getExtractionProvider()}.
     */
    public function extractionProvider(): LLMProviderInterface
    {
        return self::getExtractionProvider();
    }

    /**
     * Create an LLM provider instance by name.
     *
     * @param string $providerName Provider key from config (e.g., 'anthropic-haiku')
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

        // Inject the config key so BaseLLMProvider can use it as canonical identifier
        $config['config_key'] = $providerName;

        return new $class($config);
    }

    /**
     * Get classification providers based on config.
     *
     * @return array<string, LLMProviderInterface> Keyed by config key
     * @throws InvalidArgumentException If a configured provider does not support classification
     */
    public static function getClassificationProviders(): array
    {
        $providerNames = config('llm.classification.providers', []);

        if (! is_array($providerNames)) {
            throw new InvalidArgumentException("Config 'llm.classification.providers' must be an array");
        }

        $providers = [];
        foreach ($providerNames as $name) {
            $provider = self::make($name);

            if (! $provider->supportsClassification()) {
                throw new InvalidArgumentException(
                    "LLM provider '{$name}' does not support classification. " .
                    "Check the 'capabilities' config for this provider."
                );
            }

            $providers[$name] = $provider;
        }

        return $providers;
    }

    /**
     * Get the extraction provider based on config.
     *
     * @throws InvalidArgumentException If the configured provider does not support extraction
     */
    public static function getExtractionProvider(): LLMProviderInterface
    {
        $providerName = config('llm.extraction.provider');

        if (! is_string($providerName) || empty($providerName)) {
            throw new InvalidArgumentException("Config 'llm.extraction.provider' must be a non-empty string");
        }

        $provider = self::make($providerName);

        if (! $provider->supportsExtraction()) {
            throw new InvalidArgumentException(
                "LLM provider '{$providerName}' does not support extraction. " .
                "Check the 'capabilities' config for this provider."
            );
        }

        return $provider;
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

    /**
     * Return metadata for all configured providers.
     *
     * @return array<int, array{config_key: string, display_name: string, model: string, vendor: string, color: string|null, capabilities: array}>
     */
    public function providerMetadata(): array
    {
        $providerConfigs = config('llm.providers', []);

        if (! is_array($providerConfigs)) {
            return [];
        }

        $metadata = [];
        foreach ($providerConfigs as $configKey => $config) {
            $metadata[] = [
                'config_key'   => $configKey,
                'display_name' => $config['display_name'] ?? $configKey,
                'model'        => $config['model'] ?? null,
                'vendor'       => $config['vendor'] ?? null,
                'color'        => $config['color'] ?? null,
                'capabilities' => $config['capabilities'] ?? [],
            ];
        }

        return $metadata;
    }
}
