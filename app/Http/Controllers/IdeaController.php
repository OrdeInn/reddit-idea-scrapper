<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListIdeasRequest;
use App\Models\Idea;
use App\Models\Subreddit;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class IdeaController extends Controller
{
    /**
     * Get ideas for a subreddit (JSON for AJAX loading).
     */
    public function index(ListIdeasRequest $request, Subreddit $subreddit): JsonResponse
    {
        $validated = $request->validated();

        $query = Idea::query()
            ->fromSubreddit($subreddit->id)
            ->with(['post:id,reddit_id,title,permalink,upvotes,num_comments']);

        // Apply filters
        if (isset($validated['min_score'])) {
            $query->minScore($validated['min_score']);
        }

        if (isset($validated['min_complexity'])) {
            $query->minComplexity($validated['min_complexity']);
        }

        if (($validated['starred_only'] ?? false) === true) {
            $query->starred();
        }

        // Only exclude borderline when explicitly set to false
        if (($validated['include_borderline'] ?? true) === false) {
            $query->includeBorderline(false);
        }

        if (! empty($validated['date_from']) && ! empty($validated['date_to'])) {
            $query->createdBetween($validated['date_from'], $validated['date_to']);
        }

        // Apply sorting
        $sortBy = $validated['sort_by'] ?? 'score_overall';
        $sortDir = $validated['sort_dir'] ?? 'desc';
        $query->sortBy($sortBy, $sortDir);

        // Paginate with max limit
        $perPage = $validated['per_page'] ?? 20;
        $ideas = $query->paginate($perPage);

        return response()->json([
            'ideas' => $ideas->items(),
            'pagination' => [
                'current_page' => $ideas->currentPage(),
                'last_page' => $ideas->lastPage(),
                'per_page' => $ideas->perPage(),
                'total' => $ideas->total(),
            ],
        ]);
    }

    /**
     * Display starred ideas page.
     */
    public function starred(): Response
    {
        return Inertia::render('Starred');
    }

    /**
     * Get all starred ideas (JSON for AJAX).
     */
    public function starredList(ListIdeasRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $query = Idea::query()
            ->starred()
            ->with([
                'post:id,reddit_id,title,permalink,upvotes,num_comments,subreddit_id',
                'post.subreddit:id,name',
            ]);

        // Apply filters (same as subreddit listing)
        if (isset($validated['min_score'])) {
            $query->minScore($validated['min_score']);
        }

        if (isset($validated['min_complexity'])) {
            $query->minComplexity($validated['min_complexity']);
        }

        // Only exclude borderline when explicitly set to false
        if (($validated['include_borderline'] ?? true) === false) {
            $query->includeBorderline(false);
        }

        if (! empty($validated['date_from']) && ! empty($validated['date_to'])) {
            $query->createdBetween($validated['date_from'], $validated['date_to']);
        }

        // Apply sorting
        $sortBy = $validated['sort_by'] ?? 'starred_at';
        $sortDir = $validated['sort_dir'] ?? 'desc';
        $query->sortBy($sortBy, $sortDir);

        $perPage = $validated['per_page'] ?? 20;
        $ideas = $query->paginate($perPage);

        return response()->json([
            'ideas' => $ideas->items(),
            'pagination' => [
                'current_page' => $ideas->currentPage(),
                'last_page' => $ideas->lastPage(),
                'per_page' => $ideas->perPage(),
                'total' => $ideas->total(),
            ],
        ]);
    }

    /**
     * Toggle star status for an idea.
     */
    public function toggleStar(Idea $idea): JsonResponse
    {
        $idea->toggleStar();

        return response()->json([
            'is_starred' => $idea->is_starred,
            'starred_at' => $idea->starred_at?->toIso8601String(),
        ]);
    }

    /**
     * Get a single idea with full details.
     */
    public function show(Idea $idea): JsonResponse
    {
        $idea->load([
            'post:id,reddit_id,title,body,permalink,author,upvotes,num_comments,reddit_created_at,subreddit_id',
            'post.subreddit:id,name',
            'post.classification:post_id,haiku_category,gpt_category,final_decision,combined_score',
        ]);

        return response()->json([
            'idea' => $idea,
        ]);
    }
}
