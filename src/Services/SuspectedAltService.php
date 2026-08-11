<?php

namespace HrManager\Services;

use HrManager\Models\Note;
use HrManager\Models\SuspectedAltLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Records "probably an alt of X" claims and later settles them against reality.
 *
 * A director listing a spy's alts is working from their own intel — EVE exposes
 * no account concept, so nothing can prove the link at the time. HR files the
 * claim, then re-checks it whenever the characters turn up in SeAT:
 *
 *   same SeAT account      -> CONFIRMED, the director was right
 *   different accounts     -> REFUTED, the director was wrong and somebody is
 *                             blacklisted on a link that does not exist
 *   not both in SeAT yet   -> still open, re-checked next pass
 *
 * The refutation path is the point. Without it a bad guess is permanent and
 * invisible; with it, HR corrects its own record.
 */
class SuspectedAltService
{
    /**
     * File a claim. Idempotent on (alt, main) — re-asserting refreshes the
     * names and the asserting director rather than stacking duplicates, and
     * never quietly reopens a claim evidence has already settled.
     */
    public function record(
        int $suspectedCharacterId,
        int $mainCharacterId,
        ?string $suspectedName,
        ?string $mainName,
        string $source,
        ?int $sourceId,
        ?int $byUserId
    ): ?SuspectedAltLink {
        if (!Schema::hasTable('hr_manager_suspected_alt_links')) {
            return null;
        }
        // A character can't be its own alt.
        if ($suspectedCharacterId <= 0 || $mainCharacterId <= 0 || $suspectedCharacterId === $mainCharacterId) {
            return null;
        }

        try {
            $existing = SuspectedAltLink::where('suspected_character_id', $suspectedCharacterId)
                ->where('main_character_id', $mainCharacterId)
                ->first();

            $attrs = [
                'suspected_character_name' => $suspectedName,
                'main_character_name'      => $mainName,
                'source'                   => $source,
                'source_id'                => $sourceId,
                'asserted_by'              => $byUserId,
            ];

            if ($existing) {
                // Leave a resolved verdict alone — a director re-adding the
                // entry shouldn't wipe out evidence that already settled it.
                $existing->update($attrs);
                return $existing;
            }

            return SuspectedAltLink::create($attrs + [
                'suspected_character_id' => $suspectedCharacterId,
                'main_character_id'      => $mainCharacterId,
                'state'                  => SuspectedAltLink::STATE_SUSPECTED,
                'asserted_at'            => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] suspected alt link record failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Re-check every open claim. Returns counts for the CLI.
     *
     * `available` distinguishes "nothing to do" from "can't run": both produce
     * zero counts, and without the flag an operator can't tell a healthy empty
     * pass from a migration that never ran.
     *
     * @return array{available:bool, total:int, checked:int, confirmed:int, refuted:int, pending:int, reversed:int}
     */
    public function reconcile(): array
    {
        $out = ['available' => false, 'total' => 0, 'checked' => 0, 'confirmed' => 0, 'refuted' => 0, 'pending' => 0, 'reversed' => 0];

        if (!Schema::hasTable('hr_manager_suspected_alt_links') || !Schema::hasTable('refresh_tokens')) {
            return $out;
        }

        $out['available'] = true;
        $out['total']     = SuspectedAltLink::count();

        // EVERY claim, not just the open ones. Account data moves — two
        // characters on separate accounts can be merged onto one, and a
        // takeover can reassign a character away — so a verdict reached
        // earlier can stop being true. Re-deriving all of them keeps the
        // record honest in both directions.
        foreach (SuspectedAltLink::all() as $link) {
            $out['checked']++;

            $same = $this->sameHuman(
                (int) $link->suspected_character_id,
                (int) $link->main_character_id
            );

            // Can't judge yet. One character registering tells us nothing on
            // its own. A settled verdict is NOT reverted just because data went
            // missing — absence isn't counter-evidence.
            if ($same === null) {
                if ($link->isOpen()) {
                    $out['pending']++;
                }
                continue;
            }

            $verdict = $same
                ? SuspectedAltLink::STATE_CONFIRMED
                : SuspectedAltLink::STATE_REFUTED;

            // Unchanged verdict: nothing to record. Re-stamping it every night
            // would bury the profile in duplicate notes saying the same thing.
            if ($link->state === $verdict) {
                $out[$verdict === SuspectedAltLink::STATE_CONFIRMED ? 'confirmed' : 'refuted']++;
                continue;
            }

            // A verdict that REPLACES an earlier one is worth calling out — it
            // means HR previously told a director something that has since
            // stopped being true.
            $wasSettled = !$link->isOpen();

            $noteKey = $verdict === SuspectedAltLink::STATE_CONFIRMED
                ? ($wasSettled ? 'alt_link_confirmed_after_refute_note' : 'alt_link_confirmed_note')
                : ($wasSettled ? 'alt_link_refuted_after_confirm_note' : 'alt_link_refuted_note');

            $this->settle($link, $verdict, trans('hr-manager::watchlist.' . $noteKey, [
                'alt'  => $link->suspected_character_name ?: ('#' . $link->suspected_character_id),
                'main' => $link->main_character_name ?: ('#' . $link->main_character_id),
            ]));

            $out[$verdict === SuspectedAltLink::STATE_CONFIRMED ? 'confirmed' : 'refuted']++;
            if ($wasSettled) {
                $out['reversed']++;
            }
        }

        return $out;
    }

    /**
     * Characters on the main's SeAT account that no claim covers — alts the
     * director never knew about. Only meaningful once the main is registered;
     * empty otherwise.
     *
     * @return array<int, array{character_id:int, name:string}>
     */
    public function missingAlts(int $mainCharacterId): array
    {
        if (!Schema::hasTable('hr_manager_suspected_alt_links')) {
            return [];
        }

        $siblings = app(AccountCharacterResolver::class)->siblingsFor($mainCharacterId);
        if (empty($siblings)) {
            return [];
        }

        $claimed = SuspectedAltLink::where('main_character_id', $mainCharacterId)
            ->pluck('suspected_character_id')
            ->map(function ($id) { return (int) $id; })
            ->all();

        $out = [];
        foreach ($siblings as $sib) {
            if (!empty($sib['is_seed'])) {
                continue;
            }
            if (in_array((int) $sib['character_id'], $claimed, true)) {
                continue;
            }
            $out[] = ['character_id' => (int) $sib['character_id'], 'name' => $sib['name']];
        }

        return $out;
    }

    /**
     * Account-level gap check: of the characters on one account, which are
     * already accounted for on the watchlist (listed themselves, or named in an
     * alt claim) and which are not.
     *
     * The point is the second list. A director who blacklists the alts they
     * knew about has no way to notice the two they didn't — until the person
     * authenticates and SeAT finally shows the whole account. Then the gap is
     * obvious, if anything bothers to look.
     *
     * Returns empty when the account has no watchlist presence at all, so a
     * clean player never renders an accusatory empty panel.
     *
     * Resolved through AccountCharacterResolver, so it follows HR's player
     * identity as well as the SeAT account — a director who merged two
     * identities gets the whole human considered, not just the half SeAT
     * happens to hold on one login.
     *
     * @return array{listed:array<int,int>, uncovered:array<int, array{character_id:int, name:string}>}
     */
    public function coverageGap(int $anyCharacterId): array
    {
        $empty = ['listed' => [], 'uncovered' => []];

        $accountCharacters = app(AccountCharacterResolver::class)->siblingsFor($anyCharacterId);
        if (empty($accountCharacters)) {
            return $empty;
        }

        $ids = array_values(array_filter(array_map(function ($c) {
            return (int) ($c['character_id'] ?? 0);
        }, $accountCharacters)));

        if (empty($ids) || !Schema::hasTable('hr_manager_watchlist_entries')) {
            return $empty;
        }

        try {
            // Listed in their own right.
            $listed = DB::table('hr_manager_watchlist_entries')
                ->whereIn('character_id', $ids)
                ->pluck('character_id')
                ->map(function ($i) { return (int) $i; })
                ->all();

            // Or named in an alt claim, either end of it — a character claimed
            // as somebody's alt is already on a director's radar.
            if (Schema::hasTable('hr_manager_suspected_alt_links')) {
                $claimed = SuspectedAltLink::whereIn('suspected_character_id', $ids)
                    ->orWhereIn('main_character_id', $ids)
                    ->get(['suspected_character_id', 'main_character_id']);

                foreach ($claimed as $c) {
                    $listed[] = (int) $c->suspected_character_id;
                    $listed[] = (int) $c->main_character_id;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] alt coverage gap query failed: ' . $e->getMessage());
            return $empty;
        }

        $listed = array_values(array_unique(array_intersect($listed, $ids)));

        // No presence at all -> nothing to be missing FROM.
        if (empty($listed)) {
            return $empty;
        }

        $uncovered = [];
        foreach ($accountCharacters as $c) {
            $cid = (int) ($c['character_id'] ?? 0);
            if ($cid > 0 && !in_array($cid, $listed, true)) {
                $uncovered[] = ['character_id' => $cid, 'name' => $c['name'] ?? ('#' . $cid)];
            }
        }

        return ['listed' => $listed, 'uncovered' => $uncovered];
    }

    /** Stamp the verdict and narrate it on the main's profile. */
    private function settle(SuspectedAltLink $link, string $state, string $note): void
    {
        $link->update([
            'state'           => $state,
            'resolved_at'     => now(),
            'resolution_note' => $note,
        ]);

        // Narrate on the MAIN's player profile when that account is known, so
        // the correction lands where a director is actually looking.
        $userId = $this->seatUserFor((int) $link->main_character_id);
        if ($userId === null) {
            return;
        }

        try {
            Note::create([
                'noteable_type' => 'player',
                'noteable_id'   => $userId,
                'author_id'     => 0,
                'system_source' => Note::SOURCE_ALT_LINK,
                'content'       => $note,
                'is_private'    => false,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] suspected alt link note failed: ' . $e->getMessage());
        }
    }

    /**
     * Are these two characters the same person? true / false / null (unknown).
     *
     * HR's PlayerIdentity is checked FIRST and outranks the account comparison,
     * because one human can legitimately hold two SeAT accounts — HR's own
     * merge tool exists precisely for "one human accidentally created two
     * separate SeAT accounts". A director who merged the identities has made a
     * deliberate judgement that these are one person, and that judgement must
     * beat a raw refresh_tokens.user_id mismatch, or HR would keep reporting
     * the link as refuted after the operator explicitly resolved it.
     *
     * Falls back to the SeAT account when no identity record covers them.
     */
    private function sameHuman(int $altCharacterId, int $mainCharacterId): ?bool
    {
        // 1. Explicit human record.
        $altIdentity  = $this->identityIdFor($altCharacterId);
        $mainIdentity = $this->identityIdFor($mainCharacterId);
        if ($altIdentity !== null && $mainIdentity !== null && $altIdentity === $mainIdentity) {
            return true;
        }

        // 2. SeAT account.
        $altUser  = $this->seatUserFor($altCharacterId);
        $mainUser = $this->seatUserFor($mainCharacterId);
        if ($altUser !== null && $mainUser !== null) {
            if ($altUser === $mainUser) {
                return true;
            }
            // Different accounts AND no identity says otherwise -> not the
            // same person, as far as anything HR can see.
            return false;
        }

        // 3. Two different identities is itself an answer, even when one of
        // them has no live SeAT token.
        if ($altIdentity !== null && $mainIdentity !== null) {
            return false;
        }

        return null; // not enough to say either way
    }

    /** HR's canonical human record for a character, if one exists. */
    private function identityIdFor(int $characterId): ?int
    {
        if (!Schema::hasTable('hr_manager_character_identity_mappings')) {
            return null;
        }

        try {
            $identity = app(PlayerIdentityResolver::class)->forCharacter($characterId);

            return $identity ? (int) $identity->id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** The SeAT account behind a character, or null when it isn't registered. */
    private function seatUserFor(int $characterId): ?int
    {
        try {
            // Query builder so a revoked (soft-deleted) token still counts —
            // delinking an alt doesn't make it a different human.
            $userId = DB::table('refresh_tokens')->where('character_id', $characterId)->value('user_id');

            return $userId === null ? null : (int) $userId;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
