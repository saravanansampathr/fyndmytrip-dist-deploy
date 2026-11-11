<?php

namespace App\Http\Controllers\Api;

use App\Domains\Search\Models\RecentSearch;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Platform Roadmap Phase 4: "recent searches remembered per user underneath
 * the search box." Deliberately tiny — every module's own search page
 * already knows how to build its own search payload and how to replay one
 * (just re-submit it), so this controller only ever stores/returns that
 * payload plus a one-line summary; it never interprets `params` itself.
 *
 * Per-user, per-module list capped at MAX_PER_MODULE, newest first, with
 * resubmitting an identical search bumping it back to the top rather than
 * creating a duplicate entry (store() dedupes on {user, module, summary}
 * before inserting).
 */
class RecentSearchController extends Controller
{
    private const MAX_PER_MODULE = 5;

    /** GET /recent-searches?module=flight — this user's recent searches for one module, newest first. */
    public function index(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'module' => ['required', 'string', Rule::in(Tenant::ALL_MODULES)],
        ]);

        $searches = RecentSearch::query()
            ->where('user_id', $user->id)
            ->where('module', $data['module'])
            ->orderByDesc('created_at')
            ->limit(self::MAX_PER_MODULE)
            ->get(['id', 'module', 'summary', 'params', 'created_at']);

        return response()->json(['data' => $searches]);
    }

    /** POST /recent-searches — log a search just made on that module's search page. */
    public function store(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'module' => ['required', 'string', Rule::in(Tenant::ALL_MODULES)],
            'summary' => ['required', 'string', 'max:191'],
            'params' => ['required', 'array'],
        ]);

        // Resubmitting the exact same search (same one-line summary) bumps
        // it back to the top instead of piling up an identical duplicate —
        // the common case of someone re-checking the same route/dates.
        RecentSearch::query()
            ->where('user_id', $user->id)
            ->where('module', $data['module'])
            ->where('summary', $data['summary'])
            ->delete();

        $search = RecentSearch::create([
            'user_id' => $user->id,
            'module' => $data['module'],
            'summary' => $data['summary'],
            'params' => $data['params'],
        ]);

        $staleIds = RecentSearch::query()
            ->where('user_id', $user->id)
            ->where('module', $data['module'])
            ->orderByDesc('created_at')
            ->pluck('id')
            ->slice(self::MAX_PER_MODULE)
            ->values();

        if ($staleIds->isNotEmpty()) {
            RecentSearch::query()->whereIn('id', $staleIds)->delete();
        }

        return response()->json(['data' => $search], 201);
    }
}
