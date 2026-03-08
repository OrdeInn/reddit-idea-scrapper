<?php

namespace App\Http\Controllers;

use App\Models\Subreddit;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ProviderAnalyticsController extends Controller
{
    public function show(Subreddit $subreddit): JsonResponse
    {
        return response()->json([
            'classification' => $this->buildClassificationStats($subreddit->id),
            'extraction'     => $this->buildExtractionStats($subreddit->id),
        ]);
    }

    private function buildClassificationStats(int $subredditId): array
    {
        // Total finalized classifications for this subreddit
        $total = DB::table('classifications')
            ->join('posts', 'posts.id', '=', 'classifications.post_id')
            ->where('posts.subreddit_id', $subredditId)
            ->whereNotNull('classifications.classified_at')
            ->count();

        if ($total === 0) {
            return $this->emptyClassificationStats();
        }

        // Agreement: only for classifications where ALL expected providers completed
        $agreementBase = DB::table('classifications')
            ->join('posts', 'posts.id', '=', 'classifications.post_id')
            ->where('posts.subreddit_id', $subredditId)
            ->whereNotNull('classifications.classified_at')
            ->whereRaw('(SELECT COUNT(*) FROM classification_results WHERE classification_results.classification_id = classifications.id AND classification_results.completed = 1) = classifications.expected_provider_count')
            ->whereRaw('classifications.expected_provider_count >= 2');

        $agreementTotal = $agreementBase->count();

        $bothAgree = 0;
        if ($agreementTotal > 0) {
            $bothAgree = (clone $agreementBase)
                ->whereRaw('(SELECT COUNT(DISTINCT verdict) FROM classification_results WHERE classification_results.classification_id = classifications.id AND classification_results.completed = 1) = 1')
                ->count();
        }

        // Per-provider stats from classification_results
        $providerStats = DB::table('classification_results')
            ->join('classifications', 'classifications.id', '=', 'classification_results.classification_id')
            ->join('posts', 'posts.id', '=', 'classifications.post_id')
            ->where('posts.subreddit_id', $subredditId)
            ->where('classification_results.completed', true)
            ->selectRaw('
                classification_results.provider_name,
                COUNT(*) as total_completed,
                AVG(classification_results.confidence) as avg_confidence,
                SUM(CASE WHEN classification_results.verdict = "keep" THEN 1 ELSE 0 END) as keep_count,
                SUM(CASE WHEN classification_results.verdict = "skip" THEN 1 ELSE 0 END) as skip_count
            ')
            ->groupBy('classification_results.provider_name')
            ->get()
            ->keyBy('provider_name');

        // Build provider list from union of config providers + historical providers
        $configuredProviders = config('llm.classification.providers', []);
        $historicalProviders = $providerStats->keys()->toArray();
        $allProviderNames = array_unique(array_merge($configuredProviders, $historicalProviders));

        $providers = [];
        foreach ($allProviderNames as $providerName) {
            $stats = $providerStats->get($providerName);

            // Category distribution for this provider
            $categories = DB::table('classification_results')
                ->join('classifications', 'classifications.id', '=', 'classification_results.classification_id')
                ->join('posts', 'posts.id', '=', 'classifications.post_id')
                ->where('posts.subreddit_id', $subredditId)
                ->where('classification_results.provider_name', $providerName)
                ->where('classification_results.completed', true)
                ->whereNotNull('classification_results.category')
                ->selectRaw('classification_results.category, COUNT(*) as count')
                ->groupBy('classification_results.category')
                ->orderByDesc('count')
                ->get()
                ->mapWithKeys(fn ($row) => [$row->category => (int) $row->count])
                ->toArray();

            $label = config("llm.providers.{$providerName}.provider_name", $providerName);

            $providers[] = [
                'name'  => $providerName,
                'label' => $label,
                'total_completed'      => $stats ? (int) $stats->total_completed : 0,
                'avg_confidence'       => $stats ? round((float) $stats->avg_confidence, 3) : 0.0,
                'verdict_distribution' => [
                    'keep' => $stats ? (int) $stats->keep_count : 0,
                    'skip' => $stats ? (int) $stats->skip_count : 0,
                ],
                'category_distribution' => $categories,
            ];
        }

        return [
            'total_classified' => $total,
            'agreement' => [
                'both_agree'     => $bothAgree,
                'both_disagree'  => $agreementTotal - $bothAgree,
                'agreement_rate' => $agreementTotal > 0 ? round($bothAgree / $agreementTotal, 3) : 0.0,
            ],
            'providers' => $providers,
        ];
    }

    private function buildExtractionStats(int $subredditId): array
    {
        $rows = DB::table('ideas')
            ->join('posts', 'posts.id', '=', 'ideas.post_id')
            ->where('posts.subreddit_id', $subredditId)
            ->selectRaw('COALESCE(ideas.extraction_provider, "unknown") as provider, COUNT(*) as count')
            ->groupBy('provider')
            ->get();

        $total = $rows->sum('count');
        $distribution = $rows->mapWithKeys(fn ($row) => [$row->provider => (int) $row->count])->toArray();

        return [
            'total_extracted'       => (int) $total,
            'provider_distribution' => $distribution,
        ];
    }

    private function emptyClassificationStats(): array
    {
        $configuredProviders = config('llm.classification.providers', []);
        $providers = array_map(fn ($name) => [
            'name'  => $name,
            'label' => config("llm.providers.{$name}.provider_name", $name),
            'total_completed'       => 0,
            'avg_confidence'        => 0.0,
            'verdict_distribution'  => ['keep' => 0, 'skip' => 0],
            'category_distribution' => [],
        ], $configuredProviders);

        return [
            'total_classified' => 0,
            'agreement' => [
                'both_agree'     => 0,
                'both_disagree'  => 0,
                'agreement_rate' => 0.0,
            ],
            'providers' => $providers,
        ];
    }
}
