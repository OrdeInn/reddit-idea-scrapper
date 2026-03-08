<?php

namespace App\Http\Controllers;

use App\Models\Idea;
use App\Services\LLM\LLMProviderFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ProviderMetadataController extends Controller
{
    public function __invoke(Request $request, LLMProviderFactory $factory): JsonResponse
    {
        $providers = $factory->providerMetadata();

        $classificationProviders = config('llm.classification.providers', []);
        $extractionProvider = config('llm.extraction.provider', null);

        // Build extraction filter providers: union of current extraction-capable providers
        // and any historical extraction_provider values in the ideas table.
        // This ensures historical records remain filterable even if the provider's
        // current capabilities no longer include extraction.
        $extractionFilterProviders = $this->buildExtractionFilterProviders(collect($providers));

        return response()->json([
            'providers'                    => $providers,
            'classification_providers'     => $classificationProviders,
            'extraction_provider'          => $extractionProvider,
            'extraction_filter_providers'  => $extractionFilterProviders,
        ]);
    }

    /**
     * Build the list of providers to show in the extraction filter dropdown.
     * Includes current extraction-capable providers + historical ones from DB.
     */
    private function buildExtractionFilterProviders(Collection $providers): array
    {
        // Start with config keys of extraction-capable providers
        $capableKeys = $providers
            ->filter(fn ($p) => in_array('extraction', $p['capabilities'] ?? [], true))
            ->pluck('config_key')
            ->toArray();

        // Add historical extraction provider values from ideas table
        $historicalKeys = Idea::query()
            ->whereNotNull('extraction_provider')
            ->distinct()
            ->pluck('extraction_provider')
            ->toArray();

        // Union: unique config keys that should appear in the filter
        $allKeys = array_unique(array_merge($capableKeys, $historicalKeys));

        // Build metadata objects (use existing provider metadata or a fallback shape)
        $providerMap = $providers->keyBy('config_key');

        return array_values(array_map(function (string $key) use ($providerMap) {
            if ($providerMap->has($key)) {
                return $providerMap->get($key);
            }

            // Fallback for orphaned/unknown historical keys
            return [
                'config_key'   => $key,
                'display_name' => ucwords(str_replace('-', ' ', $key)),
                'model'        => null,
                'vendor'       => null,
                'color'        => null,
                'capabilities' => [],
            ];
        }, $allKeys));
    }
}
