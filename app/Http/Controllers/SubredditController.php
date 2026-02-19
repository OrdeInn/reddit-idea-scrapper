<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSubredditRequest;
use App\Models\Idea;
use App\Models\Subreddit;
use App\Services\ScanService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Str;

class SubredditController extends Controller
{
    public function __construct(
        private ScanService $scanService,
    ) {}

    /**
     * Display the subreddit detail page with ideas.
     */
    public function show(Subreddit $subreddit): Response
    {
        $status = $this->scanService->getSubredditStatus($subreddit);

        $avgScore = Idea::whereHas('post', fn ($q) => $q->where('subreddit_id', $subreddit->id))
            ->avg('score_overall');

        return Inertia::render('Subreddit/Show', [
            'subreddit' => [
                'id' => $subreddit->id,
                'name' => $subreddit->name,
                'full_name' => $subreddit->full_name,
                'last_scanned_at' => $subreddit->last_scanned_at?->toIso8601String(),
                'last_scanned_human' => $subreddit->last_scanned_at?->diffForHumans(),
                'idea_count' => $subreddit->idea_count,
                'avg_score' => $avgScore !== null ? round((float) $avgScore, 1) : null,
            ],
            'status' => $status,
            'scan_history' => $this->scanService->getScanHistory($subreddit),
            'scan_defaults' => [
                'default_timeframe_weeks' => config('reddit.fetch.default_timeframe_weeks'),
                'rescan_timeframe_weeks' => config('reddit.fetch.rescan_timeframe_weeks'),
            ],
        ]);
    }

    /**
     * Store a new subreddit.
     */
    public function store(StoreSubredditRequest $request): RedirectResponse
    {
        $name = $request->validated()['name'];

        // Normalize: trim, lowercase, remove r/ prefix
        $name = Str::of($name)
            ->trim()
            ->lower()
            ->replaceStart('r/', '')
            ->toString();

        $subreddit = Subreddit::firstOrCreate(['name' => $name]);

        return redirect()->route('subreddit.show', $subreddit)
            ->with('success', "Added r/{$subreddit->name}");
    }

    /**
     * Delete a subreddit.
     */
    public function destroy(Subreddit $subreddit): RedirectResponse
    {
        $name = $subreddit->name;
        $subreddit->delete();

        return redirect()->route('dashboard')
            ->with('success', "Removed r/{$name}");
    }
}
