<?php

namespace HrManager\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Thin read + cleanup bridge over SeAT's core Squads.
 *
 * Squads are a SeAT-core grouping; when SeAT Connector is installed a squad
 * can be bound to a Discord role, so squad membership drives Discord roles.
 * HR does NOT own squads or wire recruitment onboarding through them (that
 * integration was retired). This service only:
 *
 *   1. surfaces which squads a player currently belongs to (so a director can
 *      see it on the player profile), and
 *   2. offers a one-click removal of the EXPLICIT-membership squads during the
 *      purge workflow.
 *
 * IMPORTANT — auto squads are deliberately never touched. SeAT recomputes auto
 * squad membership from each squad's filters and RE-ADDS any user who still
 * matches: Squad::recomputeSquadMemberships() (on squad config change) and the
 * AuthedCharacterFilterDataUpdatedSquads listener (on every character-data /
 * ESI update) both call `$squad->members()->save($user)` for eligible users.
 * So detaching an auto squad is futile churn; it just comes back on the next
 * sync. Auto squads resolve themselves once the purged member stops matching
 * (e.g. after they leave the corp). Only `manual` / `hidden` squads (explicit,
 * operator-assigned membership) are removed here.
 *
 * Removal mirrors SeAT's OWN kick exactly (`$squad->members()->detach($id)` —
 * the same call SeAT's Squads\MembersController::destroy uses), so the core
 * SquadMemberObserver fires and any Connector-managed Discord roles cascade
 * off precisely as a native kick would. There is no half-removed state and no
 * HR-specific squad logic to drift from core.
 *
 * Standalone-safe: every method no-ops gracefully if the SeAT Squads tables /
 * models are unavailable.
 */
class SeatSquadService
{
    /**
     * Squad types whose membership is explicit (operator-assigned) and so
     * stays removed once detached. Everything outside this list (i.e. 'auto')
     * is left alone — SeAT manages it from filters.
     */
    public const REMOVABLE_TYPES = ['manual', 'hidden'];

    private function available(): bool
    {
        return class_exists(\Seat\Web\Models\User::class)
            && class_exists(\Seat\Web\Models\Squads\Squad::class)
            && Schema::hasTable('squad_member');
    }

    public function isRemovableType(string $type): bool
    {
        return in_array($type, self::REMOVABLE_TYPES, true);
    }

    /**
     * Squads the given SeAT user belongs to, scoped by SeAT's own SquadScope
     * (so the viewing director only sees squads they are allowed to see —
     * hidden squads they neither moderate nor belong to stay hidden, exactly
     * as on the native Squads page). Each entry carries a `removable` flag so
     * the view can separate operator-removable squads from auto ones.
     *
     * @return array<int, array{id:int,name:string,type:string,member_since:?string,removable:bool}>
     */
    public function squadsForUser(int $userId): array
    {
        if (!$this->available()) {
            return [];
        }

        $user = \Seat\Web\Models\User::find($userId);
        if (!$user) {
            return [];
        }

        $excluded = $this->excludedSquadIds();

        return $user->squads->map(function ($squad) use ($excluded) {
            $since         = $squad->pivot->created_at ?? null;
            $type          = (string) $squad->type;
            $typeRemovable = $this->isRemovableType($type);
            $isExcluded    = in_array((int) $squad->id, $excluded, true);

            return [
                'id'             => (int) $squad->id,
                'name'           => (string) $squad->name,
                'type'           => $type,
                'member_since'   => $since ? Carbon::parse($since)->format('M d, Y') : null,
                'type_removable' => $typeRemovable,                 // manual / hidden
                'excluded'       => $isExcluded,                    // operator never-touch list
                'removable'      => $typeRemovable && !$isExcluded, // what the button actually clears
            ];
        })->values()->all();
    }

    /**
     * Remove the user from every REMOVABLE squad they belong to (manual /
     * hidden — never auto), one squad at a time via the identical call SeAT's
     * native kick uses, so the SquadMemberObserver fires per squad (roles drop;
     * Connector, when present, re-syncs Discord). Auto squads are skipped
     * because SeAT would re-add an eligible user anyway. Returns the squads
     * removed for the history timeline + flash message.
     *
     * @return array<int, array{id:int,name:string}>
     */
    public function removeUserFromRemovableSquads(int $userId, ?array $excludedSquadIds = null): array
    {
        if (!$this->available()) {
            return [];
        }

        $user = \Seat\Web\Models\User::find($userId);
        if (!$user) {
            return [];
        }

        $excluded = $excludedSquadIds ?? $this->excludedSquadIds();
        $removed  = [];

        foreach ($user->squads as $squad) {
            if (!$this->isRemovableType((string) $squad->type)) {
                continue; // auto squad — leave it to SeAT's own recompute
            }
            if (in_array((int) $squad->id, $excluded, true)) {
                continue; // operator-excluded squad (Former Member / Alliance keep-in-touch)
            }

            $squad->members()->detach($userId);
            $removed[] = ['id' => (int) $squad->id, 'name' => (string) $squad->name];
        }

        return $removed;
    }

    /**
     * Every squad on the install (id / name / type), bypassing SeAT's
     * SquadScope so the settings exclusions picker can list hidden squads too
     * (a "Former Member" squad is often hidden). Admin-tier surface.
     *
     * @return array<int, array{id:int,name:string,type:string}>
     */
    public function allSquads(): array
    {
        if (!$this->available()) {
            return [];
        }

        return \Seat\Web\Models\Squads\Squad::withoutGlobalScope(\Seat\Web\Http\Scopes\SquadScope::class)
            ->orderBy('name')
            ->get(['id', 'name', 'type'])
            ->map(fn ($s) => [
                'id'   => (int) $s->id,
                'name' => (string) $s->name,
                'type' => (string) $s->type,
            ])->all();
    }

    /**
     * Squad ids the operator has excluded from HR removal (Settings, key
     * `purge_squad_exclusions`). HR never detaches these (the Former Member /
     * Alliance keep-in-touch squads). Always a list of ints.
     *
     * @return array<int>
     */
    public function excludedSquadIds(): array
    {
        $raw = \HrManager\Models\Setting::getValue('purge_squad_exclusions', []);
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $raw)));
    }
}
