<?php

namespace HrManager\Services;

use HrManager\Models\WatchlistEntry;

/**
 * Watchlist add / remove / query + name <-> ID resolution.
 *
 * Resolution is the interesting part: operators can add by name OR by
 * character ID without the character being authed in SeAT. The
 * service walks a four-step chain to resolve the missing half:
 *
 *   1. SeAT character_infos table  (registered chars)
 *   2. SeAT universe_names cache   (anything SeAT has touched)
 *   3. Self-cache (24h)            (avoids repeat ESI calls for the
 *                                   same lookup on a busy install)
 *   4. CCP ESI public endpoint     (/universe/ids/ or
 *                                   /characters/{id}/) with a 3s
 *                                   timeout
 *
 * The resolved character_id + character_name pair is snapshotted on
 * the WatchlistEntry row so the entry survives even if SeAT loses
 * the row later or the character is renamed in-game.
 */
class WatchlistService
{
    /**
     * Match the supplied input to a character.
     *
     * @param string $input  Either a character name or a numeric character_id.
     * @return array{character_id:int|null, character_name:string|null, resolved:bool, reason:?string}
     */
    public function resolveCharacter(string $input): array
    {
        $input = trim($input);

        if ($input === '') {
            return [
                'character_id'   => null,
                'character_name' => null,
                'resolved'       => false,
                'reason'         => 'empty_input',
            ];
        }

        // Numeric -> treat as character_id and resolve the name
        if (ctype_digit($input)) {
            return $this->resolveById((int) $input);
        }

        return $this->resolveByName($input);
    }

    /**
     * Add a character to the blacklist or whitelist. Mutually
     * exclusive: adding to one list removes any existing entry on
     * the other list for the same scope.
     */
    public function addEntry(
        string $listType,
        int $addedByUserId,
        string $input,
        ?int $scopeCorporationId = null,
        ?string $reason = null,
        string $severity = WatchlistEntry::SEVERITY_MEDIUM,
        ?\DateTimeInterface $expiresAt = null
    ): array {
        if (!in_array($listType, [WatchlistEntry::TYPE_BLACKLIST, WatchlistEntry::TYPE_WHITELIST], true)) {
            return ['success' => false, 'reason' => 'invalid_list_type'];
        }

        $resolved = $this->resolveCharacter($input);
        if (!$resolved['resolved'] || $resolved['character_id'] === null) {
            return [
                'success' => false,
                'reason'  => $resolved['reason'] ?? 'resolution_failed',
            ];
        }

        $characterId = (int) $resolved['character_id'];
        $characterName = $resolved['character_name'] ?: ('Character #' . $characterId);

        // Mutual exclusion: remove the other list's entry for the same
        // scope so a "left on good terms then turned spy" doesn't end
        // up on both lists simultaneously.
        $otherType = $listType === WatchlistEntry::TYPE_BLACKLIST
            ? WatchlistEntry::TYPE_WHITELIST
            : WatchlistEntry::TYPE_BLACKLIST;
        WatchlistEntry::where('list_type', $otherType)
            ->where('character_id', $characterId)
            ->where(function ($q) use ($scopeCorporationId) {
                if ($scopeCorporationId === null) {
                    $q->whereNull('scope_corporation_id');
                } else {
                    $q->where('scope_corporation_id', $scopeCorporationId);
                }
            })
            ->delete();

        $entry = WatchlistEntry::updateOrCreate(
            [
                'list_type'            => $listType,
                'scope_corporation_id' => $scopeCorporationId,
                'character_id'         => $characterId,
            ],
            [
                'character_name' => $characterName,
                'reason'         => $reason,
                'severity'       => $severity,
                'added_by'       => $addedByUserId,
                'added_at'       => now(),
                'expires_at'     => $expiresAt,
            ]
        );

        return [
            'success' => true,
            'entry'   => $entry,
        ];
    }

    /**
     * Look up the single most-relevant ACTIVE entry for a character
     * given the applicant's corp + alliance context. Used by
     * ApplicationController to render the headline banner.
     *
     * Visibility tiers:
     *   - global entries always match
     *   - corp-scoped entries match when applicant's corp is the
     *     scope corp
     *   - alliance-scoped entries match when applicant's corp's
     *     alliance is the scope alliance — meaning every corp in
     *     that alliance sees the warning (Matt's spec)
     *
     * @param array<int>|null $allowedCorpIds  null = admin (all)
     */
    public function findMatch(int $characterId, ?array $allowedCorpIds = null, ?int $applicantCorpId = null, ?int $applicantAllianceId = null): ?WatchlistEntry
    {
        return $this->buildMatchQuery($characterId, $allowedCorpIds, $applicantCorpId, $applicantAllianceId)
            ->active()
            ->first();
    }

    /**
     * Full history for a character including cleared and expired
     * entries. Used on application detail to surface the audit trail
     * — "this person was on the blacklist X times, last cleared by Y
     * on Z" — so recruiters get context even when there's no active
     * entry today.
     *
     * @param array<int>|null $allowedCorpIds
     */
    public function findHistory(int $characterId, ?array $allowedCorpIds = null, ?int $applicantCorpId = null, ?int $applicantAllianceId = null): \Illuminate\Database\Eloquent\Collection
    {
        return $this->buildMatchQuery($characterId, $allowedCorpIds, $applicantCorpId, $applicantAllianceId)
            ->orderByDesc('added_at')
            ->get();
    }

    /**
     * Clear an entry (mark status='cleared' with audit fields). The
     * entry stays in the table as historical record; subsequent
     * application reviews see "cleared on X by Y, reason Z".
     */
    public function clearEntry(int $id, int $clearedByUserId, ?string $reason): bool
    {
        $entry = WatchlistEntry::find($id);
        if (!$entry) {
            return false;
        }
        $entry->update([
            'status'         => WatchlistEntry::STATUS_CLEARED,
            'cleared_at'     => now(),
            'cleared_by'     => $clearedByUserId,
            'cleared_reason' => $reason,
        ]);
        return true;
    }

    // -----------------------------------------------------------------

    /**
     * The applying character + every other character on the same SeAT
     * account (its registered alts). Used so a watchlist entry on an alt
     * still matches when the applicant applies as their main. Unregistered
     * applicants resolve to just their applying character (we can't know
     * their alts). Best-effort: any failure degrades to the single id.
     *
     * @return array<int,int>
     */
    private function applicantCharacterIds(int $characterId): array
    {
        $ids = [$characterId];
        try {
            $userId = \Illuminate\Support\Facades\DB::table('refresh_tokens')
                ->where('character_id', $characterId)
                ->whereNull('deleted_at')
                ->value('user_id');
            if ($userId) {
                $alts = \Illuminate\Support\Facades\DB::table('refresh_tokens')
                    ->where('user_id', $userId)
                    ->whereNull('deleted_at')
                    ->pluck('character_id')
                    ->all();
                $ids = array_merge($ids, $alts);
            }
        } catch (\Throwable $e) {
            // best-effort; fall back to the single applying character
        }
        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            fn ($id) => $id > 0
        )));
    }

    /**
     * Build the visibility-aware base query. Centralized so findMatch
     * and findHistory share the exact same scope rules — the only
     * difference is whether we filter to ACTIVE entries.
     *
     * @param array<int>|null $allowedCorpIds
     */
    private function buildMatchQuery(int $characterId, ?array $allowedCorpIds, ?int $applicantCorpId, ?int $applicantAllianceId)
    {
        // Match against ALL of the applicant's characters (main + alts), not
        // just the applying character — a blacklisted ALT must still surface
        // on the main's application. The matched entry carries the offending
        // character's own name, so the panel shows which character tripped it.
        $query = WatchlistEntry::whereIn('character_id', $this->applicantCharacterIds($characterId));

        // First filter: applicant-context scope. Match entries whose
        // scope covers this applicant's (corp, alliance) tuple.
        $query->where(function ($q) use ($applicantCorpId, $applicantAllianceId) {
            // Global entries (both scopes NULL) always match.
            $q->where(function ($g) {
                $g->whereNull('scope_corporation_id')->whereNull('scope_alliance_id');
            });
            // Corp-scoped matches when applicant's corp = scope corp.
            if ($applicantCorpId !== null) {
                $q->orWhere(function ($g) use ($applicantCorpId) {
                    $g->where('scope_corporation_id', $applicantCorpId)
                      ->whereNull('scope_alliance_id');
                });
            }
            // Alliance-scoped matches when applicant's corp is IN that
            // alliance — every corp in the alliance sees the warning.
            if ($applicantAllianceId !== null) {
                $q->orWhere(function ($g) use ($applicantAllianceId) {
                    $g->where('scope_alliance_id', $applicantAllianceId)
                      ->whereNull('scope_corporation_id');
                });
                // Corp + alliance (rare; both must match)
                if ($applicantCorpId !== null) {
                    $q->orWhere(function ($g) use ($applicantCorpId, $applicantAllianceId) {
                        $g->where('scope_corporation_id', $applicantCorpId)
                          ->where('scope_alliance_id', $applicantAllianceId);
                    });
                }
            }
        });

        // Second filter: viewer permission. Restrict to entries the
        // viewer can SEE based on their allowed corps. Admin (null)
        // sees everything.
        if ($allowedCorpIds !== null) {
            $query->where(function ($q) use ($allowedCorpIds) {
                // Global entries visible to everyone.
                $q->whereNull('scope_corporation_id')->whereNull('scope_alliance_id');
                // Corp-scoped: viewer must have access to that corp.
                if (!empty($allowedCorpIds)) {
                    $q->orWhereIn('scope_corporation_id', $allowedCorpIds);
                }
                // Alliance-scoped: viewer sees if any of their
                // allowed corps is in that alliance. Approximate by
                // matching the alliance_id against corporation_infos
                // for the viewer's allowed corps.
                if (!empty($allowedCorpIds)) {
                    $allowedAlliances = \Illuminate\Support\Facades\DB::table('corporation_infos')
                        ->whereIn('corporation_id', $allowedCorpIds)
                        ->whereNotNull('alliance_id')
                        ->pluck('alliance_id')
                        ->map(fn($id) => (int) $id)
                        ->unique()
                        ->all();
                    if (!empty($allowedAlliances)) {
                        $q->orWhereIn('scope_alliance_id', $allowedAlliances);
                    }
                }
            });
        }

        return $query;
    }

    // -----------------------------------------------------------------

    /**
     * Look up a character_id, returning {id, name}. Delegates the
     * resolution chain to NameResolutionService so the watchlist
     * shares the same SeAT-cache -> universe_names -> ESI -> zKill
     * flow as the Members page.
     */
    protected function resolveById(int $characterId): array
    {
        if ($characterId < 90_000_000 || $characterId > 2_200_000_000_000) {
            return [
                'character_id'   => null,
                'character_name' => null,
                'resolved'       => false,
                'reason'         => 'invalid_character_id_range',
            ];
        }

        $name = app(NameResolutionService::class)->getCharacterName($characterId);

        // We trust an operator-supplied ID as canonical even when ESI
        // can't return a name — the entry still works (matching is by
        // ID). Name shows as "Character #N" until SeAT resolves later.
        return [
            'character_id'   => $characterId,
            'character_name' => $name,
            'resolved'       => true,
            'reason'         => $name !== null ? 'resolved' : 'id_trusted',
        ];
    }

    /**
     * Look up a name, returning {id, name}. Delegates to the shared
     * resolver.
     */
    protected function resolveByName(string $name): array
    {
        $name = trim($name);
        if (mb_strlen($name) < 3 || mb_strlen($name) > 37) {
            return [
                'character_id'   => null,
                'character_name' => null,
                'resolved'       => false,
                'reason'         => 'name_length_out_of_range',
            ];
        }

        $resolved = app(NameResolutionService::class)->getIdFromCharacterName($name);

        if (!empty($resolved['character_id'])) {
            return [
                'character_id'   => (int) $resolved['character_id'],
                'character_name' => $resolved['character_name'],
                'resolved'       => true,
                'reason'         => 'resolved',
            ];
        }

        return [
            'character_id'   => null,
            'character_name' => null,
            'resolved'       => false,
            'reason'         => 'esi_unknown_name',
        ];
    }
}
