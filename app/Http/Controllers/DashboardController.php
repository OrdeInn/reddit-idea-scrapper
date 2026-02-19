<?php

namespace App\Http\Controllers;

use App\Models\Idea;
use App\Models\Scan;
use App\Models\Subreddit;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        // Eager load active scans to avoid N+1
        $subreddits = Subreddit::query()
            ->withCount(['scans', 'posts'])
            ->with(['scans' => fn($q) => $q
                ->whereNotIn('status', [Scan::STATUS_COMPLETED, Scan::STATUS_FAILED])
                ->limit(1),
            ])
            ->orderBy('name')
            ->get()
            ->map(function ($subreddit) {
                return [
                    'id' => $subreddit->id,
                    'name' => $subreddit->name,
                    'full_name' => $subreddit->full_name,
                    'last_scanned_at' => $subreddit->last_scanned_at?->toIso8601String(),
                    'last_scanned_human' => $subreddit->last_scanned_at?->diffForHumans(),
                    'idea_count' => $subreddit->idea_count,
                    'top_score' => $subreddit->top_score,
                    'scans_count' => $subreddit->scans_count,
                    'has_active_scan' => $subreddit->scans->isNotEmpty(),
                ];
            });

        // Aggregate stats for the stats bar
        $subredditsWithScores = $subreddits->filter(fn($s) => $s['top_score'] !== null);
        $avgScore = $subredditsWithScores->isNotEmpty()
            ? round($subredditsWithScores->avg('top_score'), 1)
            : null;

        $stats = [
            'total_subreddits' => $subreddits->count(),
            'total_ideas' => $subreddits->sum('idea_count'),
            'avg_score' => $avgScore,
            'starred_count' => Idea::where('is_starred', true)->count(),
        ];

        return Inertia::render('Dashboard', [
            'subreddits' => $subreddits,
            'stats' => $stats,
        ]);
    }
}
