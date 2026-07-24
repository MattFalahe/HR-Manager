<?php

namespace HrManager\Services;

use HrManager\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Optional in-game title / role ALIGNMENT check: do all of a player's characters
 * in a corp carry the same corp titles + roles as their main? Drift — an alt
 * missing the Member title, or an alt holding a role the main lacks — surfaces
 * here. Off by default (Settings → Features); when on it renders a Corp Health
 * tab + a player-profile panel.
 *
 * Alignment is only computable for REGISTERED characters — an account link is
 * needed to know which characters belong to one human, so unregistered members
 * (no SeAT token) are out of scope. The account main is the reference; every
 * other character is compared to it.
 */
class RoleAlignmentService
{
    public const SETTING_ENABLED = 'enable_role_alignment';

    public function isEnabled(): bool
    {
        return (bool) Setting::getValue(self::SETTING_ENABLED, config('hr-manager.features.enable_role_alignment', false));
    }

    /**
     * Alignment for one player: each character (all in $corporationId) compared
     * to the reference — the account main if it's among them, else the first.
     *
     * @param array<int> $characterIds  the account's characters in this corp
     * @return array{available:bool, aligned:bool, ref_character_id:int, characters:array, missing_total:int, extra_total:int}
     */
    public function forPlayer(int $mainCharacterId, array $characterIds, int $corporationId): array
    {
        $characterIds = array_values(array_unique(array_filter(array_map('intval', $characterIds), fn ($id) => $id > 0)));

        if (count($characterIds) < 2) {
            // Nothing to align against — a single character (or none) in this corp.
            return [
                'available' => false, 'aligned' => true, 'characters' => [],
                'ref_character_id' => $characterIds[0] ?? 0, 'missing_total' => 0, 'extra_total' => 0,
            ];
        }

        $snap   = app(CharacterTitleService::class)->snapshotForUser($characterIds, $corporationId);
        $byChar = $snap['by_character'] ?? [];

        $refCharId = in_array($mainCharacterId, $characterIds, true) ? $mainCharacterId : $characterIds[0];
        $refSnap   = $byChar[$refCharId] ?? ['titles' => [], 'roles' => []];

        $refTitleNames = [];
        foreach ($refSnap['titles'] as $t) {
            $refTitleNames[(int) $t['title_id']] = $t['name'];
        }
        $refTitleIds = array_keys($refTitleNames);
        $refRoles    = $refSnap['roles'];

        $names = app(NameResolutionService::class)->getCharacterNamesWithFallback($characterIds);

        $chars = [];
        $aligned = true;
        $missingTotal = 0;
        $extraTotal   = 0;

        foreach ($characterIds as $cid) {
            $s = $byChar[$cid] ?? ['titles' => [], 'roles' => []];

            $titleNameById = [];
            foreach ($s['titles'] as $t) {
                $titleNameById[(int) $t['title_id']] = $t['name'];
            }
            $titleIds = array_keys($titleNameById);
            $roles    = $s['roles'];

            $isRef = $cid === $refCharId;

            $missingTitleIds = $isRef ? [] : array_values(array_diff($refTitleIds, $titleIds));
            $extraTitleIds   = $isRef ? [] : array_values(array_diff($titleIds, $refTitleIds));
            $missingRoles    = $isRef ? [] : array_values(array_diff($refRoles, $roles));
            $extraRoles      = $isRef ? [] : array_values(array_diff($roles, $refRoles));

            $charAligned = $isRef
                || (empty($missingTitleIds) && empty($extraTitleIds) && empty($missingRoles) && empty($extraRoles));
            if (!$charAligned) {
                $aligned = false;
            }
            $missingTotal += count($missingTitleIds) + count($missingRoles);
            $extraTotal   += count($extraTitleIds) + count($extraRoles);

            $chars[] = [
                'character_id'   => $cid,
                'name'           => $names[$cid] ?? ('#' . $cid),
                'is_reference'   => $isRef,
                'aligned'        => $charAligned,
                'titles'         => array_values($titleNameById),
                'roles'          => $roles,
                'missing_titles' => array_map(fn ($id) => $refTitleNames[$id] ?? ('#' . $id), $missingTitleIds),
                'extra_titles'   => array_map(fn ($id) => $titleNameById[$id] ?? ('#' . $id), $extraTitleIds),
                'missing_roles'  => $missingRoles,
                'extra_roles'    => $extraRoles,
            ];
        }

        return [
            'available'        => true,
            'aligned'          => $aligned,
            'ref_character_id' => $refCharId,
            'characters'       => $chars,
            'missing_total'    => $missingTotal,
            'extra_total'      => $extraTotal,
        ];
    }

    /**
     * Corp-wide summary — every account with 2+ registered characters in the
     * corp, misaligned players first. Heavy (per-character title/role reads), so
     * the Corp Health tab that shows it is lazy-loaded.
     */
    public function forCorporation(int $corporationId): array
    {
        if (!Schema::hasTable('refresh_tokens') || !Schema::hasTable('character_affiliations')) {
            return ['available' => false, 'players' => [], 'aligned_count' => 0, 'misaligned_count' => 0, 'total' => 0];
        }

        $rows = DB::table('refresh_tokens')
            ->join('character_affiliations', 'refresh_tokens.character_id', '=', 'character_affiliations.character_id')
            ->where('character_affiliations.corporation_id', $corporationId)
            ->whereNull('refresh_tokens.deleted_at')
            ->select(['refresh_tokens.user_id', 'refresh_tokens.character_id'])
            ->get();

        $byUser = [];
        foreach ($rows as $r) {
            $byUser[(int) $r->user_id][] = (int) $r->character_id;
        }
        $byUser = array_filter($byUser, fn ($chars) => count(array_unique($chars)) >= 2);

        if (empty($byUser)) {
            return ['available' => true, 'players' => [], 'aligned_count' => 0, 'misaligned_count' => 0, 'total' => 0];
        }

        $mainByUser = DB::table('users')->whereIn('id', array_keys($byUser))->pluck('main_character_id', 'id')->toArray();

        $players = [];
        $alignedCount = 0;
        $misalignedCount = 0;

        foreach ($byUser as $uid => $chars) {
            $result = $this->forPlayer((int) ($mainByUser[$uid] ?? 0), $chars, $corporationId);
            if (empty($result['available'])) {
                continue;
            }
            $result['aligned'] ? $alignedCount++ : $misalignedCount++;

            $refName = null;
            foreach ($result['characters'] as $c) {
                if ($c['is_reference']) {
                    $refName = $c['name'];
                    break;
                }
            }

            $players[] = [
                'user_id'           => (int) $uid,
                'main_character_id' => $result['ref_character_id'],
                'main_name'         => $refName ?? ('User #' . $uid),
                'aligned'           => $result['aligned'],
                'char_count'        => count($result['characters']),
                'missing_total'     => $result['missing_total'],
                'extra_total'       => $result['extra_total'],
                'characters'        => $result['characters'],
            ];
        }

        usort($players, fn ($a, $b) => ($a['aligned'] <=> $b['aligned']) ?: strcmp($a['main_name'], $b['main_name']));

        return [
            'available'        => true,
            'players'          => $players,
            'aligned_count'    => $alignedCount,
            'misaligned_count' => $misalignedCount,
            'total'            => count($players),
        ];
    }
}
