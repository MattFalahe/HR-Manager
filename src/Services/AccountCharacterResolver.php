<?php

namespace HrManager\Services;

use HrManager\Models\PlayerIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * "Which other characters are the SAME HUMAN as this one?"
 *
 * Used by the watchlist + intel bulk-add so a director can list an entire
 * account in one action instead of typing each alt. Deliberately narrow: it
 * only ever returns characters HR can prove belong to the same person, never a
 * guess from name similarity, shared corp, or anything else circumstantial.
 * Blacklisting the wrong pilot is a real harm, so an unproven link is no link.
 *
 * Two sources of proof, unioned:
 *   1. SeAT account — characters sharing a refresh_tokens.user_id are the same
 *      human by definition (one person authed them all).
 *   2. HR PlayerIdentity — HR's canonical human record. Catches characters a
 *      director has mapped to the person but which aren't authed in SeAT, and
 *      survives an account-takeover reassignment.
 *
 * Returns [] when the character is unknown to both, which is the common case
 * for a hostile who was never in your SeAT — the caller then just adds the one
 * character, exactly as before.
 */
class AccountCharacterResolver
{
    /**
     * Every character belonging to the same human as $characterId, INCLUDING
     * the character itself. Names resolved in bulk.
     *
     * @return array<int, array{character_id:int, name:string, is_seed:bool}>
     *         Seed character first, then the rest alphabetically. Empty when
     *         no account or identity link exists.
     */
    public function siblingsFor(int $characterId): array
    {
        if ($characterId <= 0) {
            return [];
        }

        $ids = array_merge(
            $this->viaSeatAccount($characterId),
            $this->viaPlayerIdentity($characterId)
        );

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        // Nothing beyond the seed itself means there is no account to expand.
        if (count($ids) <= 1) {
            return [];
        }

        $names = app(NameResolutionService::class)->getCharacterNamesWithFallback($ids);

        $out = [];
        foreach ($ids as $id) {
            $out[] = [
                'character_id' => $id,
                'name'         => $names[$id] ?? ('Character #' . $id),
                'is_seed'      => $id === $characterId,
            ];
        }

        // Seed first so the operator sees what they typed at the top; the rest
        // alphabetical so a long alt list is scannable.
        usort($out, function ($a, $b) {
            return ($b['is_seed'] <=> $a['is_seed']) ?: strcmp($a['name'], $b['name']);
        });

        return $out;
    }

    /** Characters sharing this character's SeAT account. */
    private function viaSeatAccount(int $characterId): array
    {
        if (!Schema::hasTable('refresh_tokens')) {
            return [];
        }

        try {
            // Query builder, so soft-deleted (revoked) tokens still count — a
            // spy who delinked an alt is still the same human.
            $userId = DB::table('refresh_tokens')
                ->where('character_id', $characterId)
                ->value('user_id');
            if ($userId === null) {
                return [];
            }

            return DB::table('refresh_tokens')
                ->where('user_id', $userId)
                ->pluck('character_id')
                ->all();
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] AccountCharacterResolver SeAT-account lookup failed: ' . $e->getMessage());
            return [];
        }
    }

    /** Characters mapped to the same HR PlayerIdentity. */
    private function viaPlayerIdentity(int $characterId): array
    {
        if (!Schema::hasTable('hr_manager_player_identities')
            || !Schema::hasTable('hr_manager_character_identity_mappings')) {
            return [];
        }

        try {
            $identity = app(PlayerIdentityResolver::class)->forCharacter($characterId);
            if (!$identity instanceof PlayerIdentity) {
                return [];
            }

            // CURRENT mappings only. allCharacterIds() includes historical ones,
            // which would drag in a character this identity used to own but that
            // was reassigned away in an account-takeover — that pilot is now a
            // different human and must not be swept into a blacklist entry.
            return $identity->currentCharacterIds();
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] AccountCharacterResolver identity lookup failed: ' . $e->getMessage());
            return [];
        }
    }
}
