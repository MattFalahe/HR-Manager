<?php

namespace HrManager\Services;

use HrManager\Models\CharacterIdentityMapping;
use HrManager\Models\PlayerIdentity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Central registry for player-identity ↔ character relationships.
 *
 * Lazy materialization: identities are created on demand the first
 * time a character is viewed. The migration creates only the tables;
 * row creation happens here.
 *
 * Resolution order on first encounter:
 *   1. Existing active mapping for character_id  → return its identity
 *   2. SeAT refresh_token gives a user_id        → get/create identity
 *      keyed on seat_user_id, attach the character
 *   3. No SeAT user but character has a name     → ghost identity
 *      with seat_user_id=NULL
 *   4. Bare character_id only                    → minimal ghost
 *
 * Director actions:
 *   reassignCharacter — moves a character to a different identity
 *                       (account takeover); closes old mapping,
 *                       opens a new one
 *   mergeIdentities   — folds identity B into identity A; all of
 *                       B's mappings get reassigned to A and B is
 *                       soft-deleted
 *
 * Notes / intel / classification rows stay character-keyed (or
 * user-id-keyed for existing data); the resolver provides the
 * indirection to find every character a given identity has ever
 * owned, so the player profile can union notes across alts +
 * across ownership transfers.
 */
class PlayerIdentityResolver
{
    private const CACHE_TTL_SECONDS = 600; // 10 min

    /**
     * Return the current identity for a character, lazy-creating one
     * if no active mapping exists.
     */
    public function forCharacter(int $characterId): ?PlayerIdentity
    {
        if ($characterId <= 0) {
            return null;
        }

        return Cache::remember('hr-pid-char-' . $characterId, self::CACHE_TTL_SECONDS, function () use ($characterId) {
            return $this->resolveOrCreate($characterId);
        });
    }

    /**
     * Return identity history for a character: every mapping it has
     * ever had, oldest first.
     */
    public function historyForCharacter(int $characterId): \Illuminate\Database\Eloquent\Collection
    {
        return CharacterIdentityMapping::forCharacter($characterId)
            ->with(['identity', 'assignedByUser'])
            ->orderBy('effective_from')
            ->get();
    }

    /**
     * Characters HR already associates with the same human as
     * $mainCharacterId (current identity mappings) that are NOT in
     * $authedCharacterIds — i.e. characters they registered with SeAT before
     * (e.g. while previously a member) but have not re-authed on this apply.
     *
     * Drives the apply-form "unauthed characters found" warning for returning
     * members. Empty for a brand-new applicant HR has no prior record of, and
     * empty once every known character is re-linked. Characters that were
     * reassigned to a different human (account takeover) are excluded, since
     * their mapping to this identity is closed.
     *
     * @return array<int, array{character_id:int, name:string}>
     */
    public function unauthedKnownCharacters(int $mainCharacterId, array $authedCharacterIds): array
    {
        if ($mainCharacterId <= 0 || !Schema::hasTable('hr_manager_character_identity_mappings')) {
            return [];
        }

        $identity = $this->forCharacter($mainCharacterId);
        if (!$identity) {
            return [];
        }

        $authed = array_map('intval', $authedCharacterIds);
        $known  = CharacterIdentityMapping::where('player_identity_id', $identity->id)
            ->whereNull('effective_to')
            ->pluck('character_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        $missing = array_values(array_diff($known, $authed));
        if (empty($missing)) {
            return [];
        }

        return array_map(fn ($id) => [
            'character_id' => $id,
            'name'         => $this->lookupCharacterName($id) ?: ('Character #' . $id),
        ], $missing);
    }

    /**
     * Return the identity tied to a SeAT user (create if missing), and
     * eagerly sync EVERY character currently linked to that SeAT
     * account as a mapping.
     *
     * Why eager: SeAT already knows all of an account's characters
     * (refresh_tokens.character_id → user_id). Without this sync the
     * identity only ever shows the characters that happened to be
     * individually looked up via forCharacter() — so a 7-alt account
     * would display 2 of 7 on the identity admin card, which reads as
     * a bug ("why isn't my main's account complete?"). Mirroring the
     * SeAT account in full makes the identity authoritative + matches
     * what the operator sees on SeAT's own character view.
     */
    public function forSeatUser(int $seatUserId): PlayerIdentity
    {
        $identity = PlayerIdentity::where('seat_user_id', $seatUserId)->first();
        if (!$identity) {
            $primaryName = $this->mainCharacterNameForUser($seatUserId)
                ?: ('SeAT user #' . $seatUserId);
            $identity = PlayerIdentity::create([
                'primary_name' => $primaryName,
                'seat_user_id' => $seatUserId,
            ]);
        }

        $this->syncSeatCharacters($identity, $seatUserId);

        return $identity;
    }

    /**
     * Ensure every character currently linked to the SeAT account has a
     * CURRENT mapping to this identity. Idempotent — only creates rows
     * for characters that don't already have a current mapping, so it's
     * cheap to call on every resolve. Never touches historical mappings
     * or characters reassigned away (those keep their own mapping
     * state).
     */
    private function syncSeatCharacters(PlayerIdentity $identity, int $seatUserId): void
    {
        try {
            // All characters SeAT links to this account (active tokens).
            $linkedCharIds = DB::table('refresh_tokens')
                ->where('user_id', $seatUserId)
                ->whereNull('deleted_at')
                ->pluck('character_id')
                ->map(fn($id) => (int) $id)
                ->unique()
                ->all();

            if (empty($linkedCharIds)) {
                return;
            }

            // Characters that ALREADY have a current mapping anywhere —
            // don't re-create or steal them (a character reassigned to a
            // different identity must stay there until an explicit
            // reassign moves it back).
            $alreadyMapped = CharacterIdentityMapping::whereIn('character_id', $linkedCharIds)
                ->whereNull('effective_to')
                ->pluck('character_id')
                ->map(fn($id) => (int) $id)
                ->all();

            $missing = array_diff($linkedCharIds, $alreadyMapped);
            foreach ($missing as $charId) {
                $this->createMapping($charId, $identity->id, CharacterIdentityMapping::REASON_AUTO_SEAT);
            }
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] PlayerIdentityResolver: syncSeatCharacters failed: ' . $e->getMessage());
        }
    }

    /**
     * Reassign a character to a different identity. Used for account
     * takeovers — when a character was passed from one player to
     * another. Closes the old mapping (effective_to=now) and opens a
     * new one (effective_from=now, reason='account_takeover').
     */
    public function reassignCharacter(int $characterId, int $newIdentityId, int $byUserId, string $reason = CharacterIdentityMapping::REASON_ACCOUNT_TAKEOVER, ?string $notes = null): bool
    {
        if (!PlayerIdentity::find($newIdentityId)) {
            return false;
        }

        DB::transaction(function () use ($characterId, $newIdentityId, $byUserId, $reason, $notes) {
            $now = now();

            // Close any active mapping
            CharacterIdentityMapping::forCharacter($characterId)
                ->current()
                ->update(['effective_to' => $now]);

            // Open new mapping
            CharacterIdentityMapping::create([
                'character_id'       => $characterId,
                'player_identity_id' => $newIdentityId,
                'effective_from'     => $now,
                'effective_to'       => null,
                'assigned_by'        => $byUserId,
                'reason'             => $reason,
                'notes'              => $notes,
            ]);

            // Cache bust
            Cache::forget('hr-pid-char-' . $characterId);
        });

        return true;
    }

    /**
     * Merge identity B into identity A. Every active mapping owned by
     * B becomes owned by A; B is soft-deleted. Historical mappings
     * (effective_to NOT NULL) carry a reason='merge' marker.
     */
    public function mergeIdentities(int $intoIdentityId, int $fromIdentityId, int $byUserId, ?string $notes = null): bool
    {
        if ($intoIdentityId === $fromIdentityId) {
            return false;
        }
        $into = PlayerIdentity::find($intoIdentityId);
        $from = PlayerIdentity::find($fromIdentityId);
        if (!$into || !$from) {
            return false;
        }

        DB::transaction(function () use ($into, $from, $byUserId, $notes) {
            $now = now();

            // Pull every mapping currently pointing at the merged-from
            // identity and re-point to the merged-into identity. We
            // PRESERVE the effective dates so the audit history
            // survives the merge.
            CharacterIdentityMapping::where('player_identity_id', $from->id)
                ->update([
                    'player_identity_id' => $into->id,
                    'reason'             => CharacterIdentityMapping::REASON_MERGE,
                    'notes'              => $notes ?: 'Merged from identity #' . $from->id,
                    'updated_at'         => $now,
                ]);

            // If the from-identity had a seat_user_id and the into-
            // identity doesn't, carry it forward. Otherwise the
            // into-identity wins.
            if ($from->seat_user_id !== null && $into->seat_user_id === null) {
                $into->seat_user_id = $from->seat_user_id;
                $into->save();
            }

            // Soft-delete the from-identity. The merge is reversible
            // via the soft-delete (an admin can restore + remap).
            $from->delete();
        });

        // Bust cache for every character that moved.
        $movedChars = CharacterIdentityMapping::where('player_identity_id', $intoIdentityId)
            ->pluck('character_id')->unique();
        foreach ($movedChars as $cid) {
            Cache::forget('hr-pid-char-' . (int) $cid);
        }

        return true;
    }

    // -----------------------------------------------------------------

    /**
     * Lazy resolution + creation.
     */
    private function resolveOrCreate(int $characterId): ?PlayerIdentity
    {
        // 1. Existing active mapping
        $mapping = CharacterIdentityMapping::forCharacter($characterId)->current()->first();
        if ($mapping) {
            return $mapping->identity;
        }

        // 2. SeAT refresh_token → user → identity
        $userId = DB::table('refresh_tokens')
            ->where('character_id', $characterId)
            ->whereNull('deleted_at')
            ->value('user_id');

        if ($userId !== null) {
            $identity = $this->forSeatUser((int) $userId);
            $this->createMapping($characterId, $identity->id, CharacterIdentityMapping::REASON_AUTO_SEAT);
            return $identity;
        }

        // 3. No SeAT user — ghost identity. character_infos /
        // universe_names give us a display name if SeAT has touched
        // the character.
        $name = $this->lookupCharacterName($characterId);
        $identity = PlayerIdentity::create([
            'primary_name' => $name ?: ('Character #' . $characterId),
            'seat_user_id' => null,
        ]);
        $this->createMapping($characterId, $identity->id, CharacterIdentityMapping::REASON_GHOST_UNREGISTERED);
        return $identity;
    }

    private function createMapping(int $characterId, int $identityId, string $reason): void
    {
        try {
            CharacterIdentityMapping::create([
                'character_id'       => $characterId,
                'player_identity_id' => $identityId,
                'effective_from'     => now(),
                'effective_to'       => null,
                'assigned_by'        => null,
                'reason'             => $reason,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] PlayerIdentityResolver: createMapping failed: ' . $e->getMessage());
        }
    }

    private function mainCharacterNameForUser(int $userId): ?string
    {
        $user = DB::table('users')->where('id', $userId)->first(['main_character_id']);
        if (!$user || !$user->main_character_id) {
            return null;
        }
        return $this->lookupCharacterName((int) $user->main_character_id);
    }

    private function lookupCharacterName(int $characterId): ?string
    {
        $name = DB::table('character_infos')->where('character_id', $characterId)->value('name');
        if ($name) {
            return (string) $name;
        }
        if (Schema::hasTable('universe_names')) {
            $name = DB::table('universe_names')
                ->where('entity_id', $characterId)
                ->where('category', 'character')
                ->value('name');
            if ($name) {
                return (string) $name;
            }
        }
        return null;
    }
}
