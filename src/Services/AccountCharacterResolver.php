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

    /**
     * Group many characters by the human they belong to, in a fixed number of
     * queries regardless of how many characters are passed.
     *
     * siblingsFor() answers the same question one character at a time and
     * resolves display names while it is at it, which is right for a form and
     * ruinous for a roster-wide pass: a 500-character corp would mean 500 round
     * trips plus name lookups nobody asked for. This returns bare grouping keys
     * and nothing else.
     *
     * The key is opaque and only meaningful within one call. Characters with no
     * account and no identity are their own group, which is the correct answer
     * for an unregistered member rather than a special case.
     *
     * @param array<int> $characterIds
     * @return array<int, string> character_id => account key
     */
    public function accountKeysFor(array $characterIds): array
    {
        $characterIds = array_values(array_unique(array_filter(
            array_map('intval', $characterIds), fn ($v) => $v > 0
        )));
        if (empty($characterIds)) {
            return [];
        }

        // Start everyone in their own group, then merge.
        $keys = [];
        foreach ($characterIds as $cid) {
            $keys[$cid] = 'char:' . $cid;
        }

        // SeAT accounts. Revoked tokens still count: someone who delinked an
        // alt is still the same human.
        if (Schema::hasTable('refresh_tokens')) {
            try {
                foreach (array_chunk($characterIds, 1000) as $chunk) {
                    $rows = DB::table('refresh_tokens')
                        ->whereIn('character_id', $chunk)
                        ->get(['character_id', 'user_id']);
                    foreach ($rows as $r) {
                        if ($r->user_id !== null) {
                            $keys[(int) $r->character_id] = 'user:' . (int) $r->user_id;
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[HR Manager] AccountCharacterResolver batch account lookup failed: ' . $e->getMessage());
            }
        }

        // HR identities outrank the SeAT account: a director who has merged two
        // accounts into one human has stated that they are one human, and that
        // judgement is the whole point of the identity layer.
        if (Schema::hasTable('hr_manager_character_identity_mappings')) {
            try {
                foreach (array_chunk($characterIds, 1000) as $chunk) {
                    // effective_to IS NULL is what CharacterIdentityMapping's
                    // current() scope means: a mapping that has not been
                    // superseded. Historical rows would drag in characters
                    // reassigned away in an account takeover, who are now a
                    // different human.
                    $rows = DB::table('hr_manager_character_identity_mappings')
                        ->whereIn('character_id', $chunk)
                        ->whereNull('effective_to')
                        ->get(['character_id', 'player_identity_id']);
                    foreach ($rows as $r) {
                        if ($r->player_identity_id !== null) {
                            $keys[(int) $r->character_id] = 'identity:' . (int) $r->player_identity_id;
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[HR Manager] AccountCharacterResolver batch identity lookup failed: ' . $e->getMessage());
            }
        }

        return $keys;
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
