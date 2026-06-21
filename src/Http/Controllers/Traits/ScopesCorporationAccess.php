<?php

namespace HrManager\Http\Controllers\Traits;

use Illuminate\Support\Facades\DB;

/**
 * Provides data-level corporation scoping for HR Manager controllers.
 *
 * Route permissions (can:hr-manager.recruiter) only gate page access — they
 * do NOT scope data. This trait resolves which corp IDs a user may see and
 * applies a corporation_id filter to queries.
 *
 * SeAT v5 convention:
 *   - Admins (hr-manager.admin or isAdmin()) see all corps.
 *   - Non-admins see only corps they have a character in.
 *   - Users with zero linked characters get an empty array → abort 403.
 *
 * Request-scoped memo prevents the refresh_tokens × character_affiliations
 * join from running multiple times per request.
 */
trait ScopesCorporationAccess
{
    private bool $allowedCorpIdsResolved = false;
    private ?array $allowedCorpIdsCache = null;

    /**
     * @return array<int>|null  null = admin (see all), array = restricted list
     */
    protected function getAllowedCorpIds(): ?array
    {
        if ($this->allowedCorpIdsResolved) {
            return $this->allowedCorpIdsCache;
        }

        $this->allowedCorpIdsResolved = true;

        $user = auth()->user();
        if (!$user) {
            return $this->allowedCorpIdsCache = [];
        }

        if ($user->can('hr-manager.admin') || (method_exists($user, 'isAdmin') && $user->isAdmin())) {
            return $this->allowedCorpIdsCache = null;
        }

        return $this->allowedCorpIdsCache = DB::table('refresh_tokens')
            ->join('character_affiliations', 'refresh_tokens.character_id', '=', 'character_affiliations.character_id')
            ->where('refresh_tokens.user_id', $user->id)
            ->whereNull('refresh_tokens.deleted_at')
            ->pluck('character_affiliations.corporation_id')
            ->unique()
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Assert the given corporation_id is within the user's allowed set.
     * Aborts 403 if not. v1.0.0 dropped the includeGlobal flag — every
     * scoped row in v1.0.0 has a non-null corporation_id (form templates,
     * applications, etc.). Tier mappings remain optionally global but are
     * handled separately by the TierService.
     */
    protected function assertCanAccessCorp(?int $corporationId): void
    {
        $allowed = $this->getAllowedCorpIds();

        if ($allowed === null) {
            return; // admin
        }

        if (empty($allowed)) {
            abort(403, 'No corporation access.');
        }

        if ($corporationId === null) {
            abort(403, 'Corporation context required.');
        }

        if (!in_array($corporationId, $allowed)) {
            abort(403, 'You do not have access to this corporation.');
        }
    }

    protected function scopeToAllowedCorps($query, string $column = 'corporation_id')
    {
        $allowed = $this->getAllowedCorpIds();

        if ($allowed === null) {
            return $query;
        }

        if (empty($allowed)) {
            return $query->whereRaw('1=0');
        }

        return $query->whereIn($column, $allowed);
    }

    protected function assertCanAccessCharacter(int $characterId): void
    {
        $allowed = $this->getAllowedCorpIds();

        if ($allowed === null) {
            return;
        }

        if (empty($allowed)) {
            abort(403, 'No corporation access.');
        }

        $corpId = DB::table('character_affiliations')
            ->where('character_id', $characterId)
            ->value('corporation_id');

        if ($corpId === null || !in_array($corpId, $allowed)) {
            abort(403, 'You do not have access to this character.');
        }
    }

    /**
     * "Default corp" for the viewer = the corp their main character belongs
     * to. Used so the corp picker lands on their own corp instead of
     * alphabetical-first when they hit a page without `?corporation_id=`.
     *
     * Non-admins only return the main-char corp when it's in their allowed
     * set (defensive — if they somehow lost token coverage of their own
     * corp, we'd rather fall through to the standard "first allowed" than
     * abort).
     *
     * Admins return their main-char corp unconditionally when it has at
     * least one tracked character (so the install starts on the admin's
     * own corp rather than the first corp encountered in the DB).
     */
    protected function defaultCorporationId(?array $allowedCorps): ?int
    {
        $user = auth()->user();
        if (!$user || empty($user->main_character_id)) {
            return null;
        }

        $mainCorp = DB::table('character_affiliations')
            ->where('character_id', $user->main_character_id)
            ->value('corporation_id');

        if ($mainCorp === null) {
            return null;
        }
        $mainCorp = (int) $mainCorp;

        if ($allowedCorps !== null && !in_array($mainCorp, $allowedCorps, true)) {
            return null;
        }

        return $mainCorp;
    }
}
