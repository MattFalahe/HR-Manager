<?php

namespace HrManager\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads SeAT's corporation_titles + corporation_member_titles +
 * corporation_roles to surface what in-game titles and
 * roles a character currently holds. Used by:
 *   - Member profile (display titles)
 *   - Player profile (display titles per alt)
 *   - Purge workflow (Discord notifications list what to strip
 *     before the 24h in-game cooldown bites)
 *
 * Pure read-only consumer of SeAT-synced tables. No ESI calls. All
 * lookups are gated by Schema::hasTable so the service degrades to
 * empty collections on installs missing the tables (very old SeAT
 * versions, or fresh installs before the first eveapi sync).
 */
class CharacterTitleService
{
    /**
     * Titles held by a character in a corporation. Title names are
     * stripped of EVE in-game markup (<color=0xXXXXXXXX>, </color>,
     * <b>, <i>, <a>, <u>) since corps frequently use color codes to
     * style title names and the raw text leaks through to the UI.
     *
     * @return array<int, array{title_id:int, name:string}>
     */
    public function titlesForCharacter(int $characterId, int $corporationId): array
    {
        if (!Schema::hasTable('corporation_member_titles') || !Schema::hasTable('corporation_titles')) {
            return [];
        }

        return DB::table('corporation_member_titles as cmt')
            ->join('corporation_titles as ct', function ($j) {
                $j->on('ct.title_id', '=', 'cmt.title_id')
                  ->on('ct.corporation_id', '=', 'cmt.corporation_id');
            })
            ->where('cmt.character_id', $characterId)
            ->where('cmt.corporation_id', $corporationId)
            ->orderBy('ct.name')
            ->get(['ct.title_id', 'ct.name'])
            ->map(fn($r) => [
                'title_id' => (int) $r->title_id,
                'name'     => $this->stripEveMarkup((string) $r->name),
            ])
            ->all();
    }

    /**
     * Strip EVE in-game markup from a string. Corps use these tags
     * for in-game color/format effects (titles, MOTDs, descriptions)
     * but they're not meant for UI rendering outside the EVE client.
     *
     * Examples:
     *   "<color=0xFF00FF63>Member</color>"  -> "Member"
     *   "<b><color=0xFFFFFF00>Senior</color></b> Officer" -> "Senior Officer"
     *
     * Conservative pattern: strips anything that looks like a tag. EVE
     * doesn't ship arbitrary HTML in title names so this is safe.
     */
    public function stripEveMarkup(string $text): string
    {
        // Remove every <something> or </something> token. EVE color
        // tags carry a numeric attribute (<color=0xRRGGBBAA>) which
        // this catches the same as <b> / <i> / <u> / <a href>.
        $stripped = preg_replace('/<[^>]+>/', '', $text);
        // Collapse runs of whitespace introduced by removed tags.
        $stripped = preg_replace('/\s+/', ' ', (string) $stripped);
        return trim((string) $stripped);
    }

    /**
     * Direct (non-title-derived) in-game roles held by a character.
     * Returns distinct role names so [Director] only shows once even
     * when the character has it at HQ + base + other.
     *
     * @return array<int, string>
     */
    public function directRolesForCharacter(int $characterId, int $corporationId): array
    {
        if (!Schema::hasTable('corporation_roles')) {
            return [];
        }

        // corporation_roles (SeAT's director-token Corporation\Roles job)
        // carries corporation_id + a `type` enum. Exclude grantable_* so we
        // list roles the character HOLDS (corp-wide + at HQ/base/other), not
        // ones they can merely grant; distinct() collapses the same role name
        // across those scopes to a single entry.
        return DB::table('corporation_roles')
            ->where('character_id', $characterId)
            ->where('corporation_id', $corporationId)
            ->where('type', 'not like', 'grantable%')
            ->distinct()
            ->orderBy('role')
            ->pluck('role')
            ->map(fn($r) => (string) $r)
            ->all();
    }

    /**
     * Convenience: complete "what they hold in-game" snapshot for one
     * character. Shape consumed by the views + the purge notification.
     *
     * @return array{titles:array, roles:array, has_anything:bool}
     */
    public function snapshotForCharacter(int $characterId, int $corporationId): array
    {
        $titles = $this->titlesForCharacter($characterId, $corporationId);
        $roles  = $this->directRolesForCharacter($characterId, $corporationId);

        return [
            'titles'       => $titles,
            'roles'        => $roles,
            'has_anything' => !empty($titles) || !empty($roles),
        ];
    }

    /**
     * Aggregate snapshot for an entire user's alts in a corp. Used by
     * the purge notification so the operator sees one consolidated
     * "strip these" list across the kicked player's whole roster.
     *
     * @param array<int> $characterIds
     * @return array{titles:array, roles:array, by_character:array, has_anything:bool}
     */
    public function snapshotForUser(array $characterIds, int $corporationId): array
    {
        $titlesAll = [];      // title_id => name
        $rolesAll  = [];      // role => true
        $byChar    = [];      // character_id => snapshot

        foreach ($characterIds as $charId) {
            $snap = $this->snapshotForCharacter((int) $charId, $corporationId);
            $byChar[(int) $charId] = $snap;
            foreach ($snap['titles'] as $t) {
                $titlesAll[$t['title_id']] = $t['name'];
            }
            foreach ($snap['roles'] as $r) {
                $rolesAll[$r] = true;
            }
        }

        ksort($titlesAll);
        ksort($rolesAll);

        return [
            'titles'       => array_map(
                fn($name, $id) => ['title_id' => (int) $id, 'name' => $name],
                $titlesAll,
                array_keys($titlesAll)
            ),
            'roles'        => array_keys($rolesAll),
            'by_character' => $byChar,
            'has_anything' => !empty($titlesAll) || !empty($rolesAll),
        ];
    }
}
