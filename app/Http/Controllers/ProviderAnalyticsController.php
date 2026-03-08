<?php

namespace App\Http\Controllers;

use App\Models\Idea;
use App\Models\Subreddit;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ProviderAnalyticsController extends Controller
{
    /**
     * Provider column prefix to canonical name mapping.
     * Matches Classification::PROVIDER_MAP.
     */
    private const PROVIDERS = [
        ['prefix' => 'haiku', 'name' => 'anthropic-haiku', 'label' => 'Haiku'],
        ['prefix' => 'gpt',   'name' => 'openai',           'label' => 'GPT'],
    ];

    public function show(Subreddit $subreddit): JsonResponse
    {
        return response()->json([
            'classification' => $this->buildClassificationStats($subreddit->id),
            'extraction'     => $this->buildExtractionStats($subreddit->id),
        ]);
    }

    private function buildClassificationStats(int $subredditId): array
    {
        // Use a join to avoid pulling all post IDs into PHP memory
        $agg = DB::table('classifications')
            ->join('posts', 'posts.id', '=', 'classifications.post_id')
            ->where('posts.subreddit_id', $subredditId)
            ->where('classifications.haiku_completed', true)
            ->where('classifications.gpt_completed', true)
            ->selectRaw('
                COUNT(*) as total_classified,
                SUM(CASE WHEN classifications.haiku_verdict = classifications.gpt_verdict THEN 1 ELSE 0 END) as both_agree,
                AVG(classifications.haiku_confidence) as avg_haiku_confidence,
                AVG(classifications.gpt_confidence) as avg_gpt_confidence,
                SUM(CASE WHEN classifications.haiku_verdict = "keep" THEN 1 ELSE 0 END) as haiku_keep,
                SUM(CASE WHEN classifications.haiku_verdict = "skip" THEN 1 ELSE 0 END) as haiku_skip,
                SUM(CASE WHEN classifications.gpt_verdict = "keep" THEN 1 ELSE 0 END) as gpt_keep,
                SUM(CASE WHEN classifications.gpt_verdict = "skip" THEN 1 ELSE 0 END) as gpt_skip
            ')
            ->first();

        $total = (int) ($agg->total_classified ?? 0);

        if ($total === 0) {
            return $this->emptyClassificationStats();
        }

        $bothAgree = (int) ($agg->both_agree ?? 0);
        $providers = [];

        foreach (self::PROVIDERS as $provider) {
            $prefix = $provider['prefix'];

            // Category distribution filtered to the same both-completed dataset as
            // total_completed / avg_confidence / verdict_distribution, ensuring counts are consistent.
            $categories = DB::table('classifications')
                ->join('posts', 'posts.id', '=', 'classifications.post_id')
                ->where('posts.subreddit_id', $subredditId)
                ->where('classifications.haiku_completed', true)
                ->where('classifications.gpt_completed', true)
                ->whereNotNull("classifications.{$prefix}_category")
                ->selectRaw("classifications.{$prefix}_category as category, COUNT(*) as count")
                ->groupBy("classifications.{$prefix}_category")
                ->orderByDesc('count')
                ->get()
                ->mapWithKeys(fn ($row) => [$row->category => (int) $row->count])
                ->toArray();

            $providers[] = [
                'name'  => $provider['name'],
                'label' => $provider['label'],
                'total_completed'      => (int) ($agg->{"{$prefix}_keep"} ?? 0) + (int) ($agg->{"{$prefix}_skip"} ?? 0),
                'avg_confidence'       => round((float) ($agg->{"avg_{$prefix}_confidence"} ?? 0), 3),
                'verdict_distribution' => [
                    'keep' => (int) ($agg->{"{$prefix}_keep"} ?? 0),
                    'skip' => (int) ($agg->{"{$prefix}_skip"} ?? 0),
                ],
                'category_distribution' => $categories,
            ];
        }

        return [
            'total_classified' => $total,
            'agreement' => [
                'both_agree'     => $bothAgree,
                'both_disagree'  => $total - $bothAgree,
                'agreement_rate' => round($bothAgree / $total, 3),
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
        $providers = array_map(fn ($p) => [
            'name'  => $p['name'],
            'label' => $p['label'],
            'total_completed'       => 0,
            'avg_confidence'        => 0.0,
            'verdict_distribution'  => ['keep' => 0, 'skip' => 0],
            'category_distribution' => [],
        ], self::PROVIDERS);

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
