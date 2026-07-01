<?php

namespace HrManager\Services;

use HrManager\Models\IntelNote;
use HrManager\Models\Setting;
use Illuminate\Database\Eloquent\Collection;

/**
 * Centralizes the visibility rules for intel notes so every consumer
 * (intel page, application review, member profile) honours them
 * consistently.
 *
 * Visibility model:
 *   - Directors and admins always see every intel note they have
 *     corp access to.
 *   - Recruiters see notes flagged recruiter_visible=true, AND only
 *     when the install-level setting intel.recruiter_view_enabled
 *     is true. Either condition false -> recruiter sees nothing.
 *   - Authors always see their own notes regardless of the above.
 *
 * Corp scope:
 *   - Notes with scope_corporation_id = NULL are global; every
 *     viewer who passes the role/setting check sees them.
 *   - Notes with a corp scope are visible only to viewers whose
 *     allowedCorps include that corp.
 *
 * Expiry:
 *   - Notes with expires_at in the past are filtered out unless
 *     the caller explicitly opts into expired view (admin audit).
 */
class IntelService
{
    public const SETTING_RECRUITER_VIEW = 'intel.recruiter_view_enabled';

    /**
     * Notes for a single character that the given viewer can see.
     *
     * @param array<int>|null $allowedCorpIds  null = admin (all)
     */
    public function notesForCharacter(int $characterId, int $viewerUserId, ?array $allowedCorpIds, string $viewerTier): Collection
    {
        $query = IntelNote::forCharacter($characterId)
            ->active()
            ->with('author')
            ->orderByDesc('created_at');

        $this->applyVisibility($query, $viewerUserId, $allowedCorpIds, $viewerTier);

        return $query->get();
    }

    /**
     * Aggregated intel summary suitable for the application detail
     * card and the member profile chip. Returns counts + the latest
     * note text so the calling view can render a teaser.
     *
     * @param array<int>|null $allowedCorpIds
     * @return array{count:int, recent:?\HrManager\Models\IntelNote, has_blacklist_watchlist_match:bool}
     */
    public function summaryForCharacter(int $characterId, int $viewerUserId, ?array $allowedCorpIds, string $viewerTier): array
    {
        $notes = $this->notesForCharacter($characterId, $viewerUserId, $allowedCorpIds, $viewerTier);

        return [
            'count'   => $notes->count(),
            'recent'  => $notes->first(),
            'all'     => $notes,
        ];
    }

    /**
     * Paginated note index — used by the Intel page. Honors visibility
     * + optional search + tag filter.
     *
     * @param array<int>|null $allowedCorpIds
     */
    public function index(int $viewerUserId, ?array $allowedCorpIds, string $viewerTier, ?string $search = null, ?string $tag = null)
    {
        $query = IntelNote::active()
            ->with('author')
            ->orderByDesc('created_at');

        $this->applyVisibility($query, $viewerUserId, $allowedCorpIds, $viewerTier);

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('character_name', 'like', "%{$search}%")
                  ->orWhere('character_id', 'like', "%{$search}%")
                  ->orWhere('body', 'like', "%{$search}%");
            });
        }

        if ($tag !== null && $tag !== '') {
            // JSON array contains — MariaDB JSON_CONTAINS is the
            // portable path here. Falls back to LIKE if JSON_CONTAINS
            // isn't available (older MariaDB), accepting some false
            // positives.
            $query->where(function ($q) use ($tag) {
                $needle = json_encode($tag);
                $q->whereRaw("JSON_CONTAINS(tags, ?)", [$needle])
                  ->orWhere('tags', 'like', '%"' . $tag . '"%');
            });
        }

        return $query->paginate(40);
    }

    /**
     * Is the recruiter-view setting on at the install level?
     */
    public function recruiterViewEnabled(): bool
    {
        return (bool) Setting::getValue(self::SETTING_RECRUITER_VIEW, false);
    }

    /**
     * Can the given user contribute (add / edit / delete) intel notes?
     * Directors and admins yes; recruiters never.
     */
    public function canContribute(): bool
    {
        $user = auth()->user();
        return $user && ($user->can('hr-manager.director') || $user->can('hr-manager.admin'));
    }

    // -----------------------------------------------------------------

    /**
     * Apply visibility filtering to a query builder.
     */
    private function applyVisibility($query, int $viewerUserId, ?array $allowedCorpIds, string $viewerTier): void
    {
        $isDirector = in_array($viewerTier, ['director', 'admin'], true);
        $recruiterViewOn = $this->recruiterViewEnabled();

        // Corp scope: NULL (global) OR in allowed list. Admins see all.
        if ($allowedCorpIds !== null) {
            $query->where(function ($q) use ($allowedCorpIds) {
                $q->whereNull('scope_corporation_id');
                if (!empty($allowedCorpIds)) {
                    $q->orWhereIn('scope_corporation_id', $allowedCorpIds);
                }
            });
        }

        if ($isDirector) {
            return; // directors see all corp-allowed notes
        }

        // Recruiter view: per-note flag must be true AND the install
        // setting must be on. Authors always see their own.
        $query->where(function ($q) use ($viewerUserId, $recruiterViewOn) {
            $q->where('author_id', $viewerUserId);
            if ($recruiterViewOn) {
                $q->orWhere('recruiter_visible', true);
            }
        });
    }
}
