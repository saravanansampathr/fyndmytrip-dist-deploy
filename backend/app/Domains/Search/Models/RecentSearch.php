<?php

namespace App\Domains\Search\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Recent searches remembered per user underneath the search box" (Platform
 * Roadmap Phase 4) — one row per remembered search, any Product Module.
 * `params` is opaque to this model and to RecentSearchController: it's
 * exactly whatever payload that module's own search page already builds
 * for its own search API call, so replaying a recent search is just
 * re-submitting that same payload — no per-module parsing needed here.
 * No `updated_at`: a search is either logged fresh or superseded (see
 * RecentSearchController::store()'s dedup-then-reinsert), never edited.
 */
class RecentSearch extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'module',
        'summary',
        'params',
    ];

    protected $casts = [
        'params' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
