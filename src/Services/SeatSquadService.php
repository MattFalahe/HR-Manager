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
 *   2. offers a one-click "remove from all squads" during the purge workflow.
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
    private function available(): bool
    {
        return class_exists(\Seat\Web\Models\User::class)
            && class_exists(\Seat\Web\Models\Squads\Squad::class)
            && Schema::hasTable('squad_member');
    }

    /**
     * Squads the given SeAT user belongs to, scoped by SeAT's own SquadScope
     * (so the viewing director only sees squads they are allowed to see —
     * hidden squads they neither moderate nor belong to stay hidden, exactly
     * as on the native Squads page).
     *
     * @return array<int, array{id:int,name:string,type:string,member_since:?string}>
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

        return $user->squads->map(function ($squad) {
            $since = $squad->pivot->created_at ?? null;

            return [
                'id'           => (int) $squad->id,
                'name'         => (string) $squad->name,
                'type'         => (string) $squad->type,
                'member_since' => $since ? Carbon::parse($since)->format('M d, Y') : null,
            ];
        })->values()->all();
    }

    /**
     * Remove the user from every squad they belong to (that the current viewer
     * can see), one squad at a time via the identical call SeAT's native kick
     * uses, so the SquadMemberObserver fires per squad (roles drop; Connector,
     * when present, re-syncs Discord). Returns the squads removed for the
     * history timeline + flash message.
     *
     * @return array<int, array{id:int,name:string}>
     */
    public function removeUserFromAllSquads(int $userId): array
    {
        if (!$this->available()) {
            return [];
        }

        $user = \Seat\Web\Models\User::find($userId);
        if (!$user) {
            return [];
        }

        $removed = [];

        foreach ($user->squads as $squad) {
            $squad->members()->detach($userId);
            $removed[] = ['id' => (int) $squad->id, 'name' => (string) $squad->name];
        }

        return $removed;
    }
}
