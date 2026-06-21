<?php

namespace HrManager\Services;

use Carbon\Carbon;
use HrManager\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Token + scope health for an existing corp roster. Answers two questions:
 *   1. Who has a working SeAT token at all (can the install see them)?
 *   2. Of those, whose token carries the scopes the corp REQUIRES?
 *
 * The corp requirement is expressed as a SeAT SSO scope PROFILE — the same
 * {id,name,scopes[]} objects SeAT stores in the global `sso_scopes` setting and
 * uses for login (see RecruitmentSsoService). The operator picks one in
 * Settings; its scope list is the bar each member's token is measured against.
 * No profile chosen = no scope check (tokens are judged on existence only).
 *
 * Per-character status:
 *   valid        - has a live token AND (no requirement OR token scopes cover it)
 *   insufficient - has a live token but is MISSING one or more required scopes
 *   lost         - had a token that is now soft-deleted (delink / CCP rejection)
 *   never_linked - no token row at all
 *
 * Reads only already-synced SeAT tables (corp member roster + refresh_tokens)
 * and HR's own setting. No ESI calls. Standalone-safe.
 */
class TokenHealthService
{
    /** HR setting key: SSO profile NAME defining required member scopes (empty = no scope check). */
    public const SETTING_REQUIRED_PROFILE = 'token_required_profile';

    public const STATUS_VALID        = 'valid';
    public const STATUS_INSUFFICIENT = 'insufficient';
    public const STATUS_LOST         = 'lost';
    public const STATUS_NEVER        = 'never_linked';

    /** The operator-chosen requirement profile NAME (empty string = none). */
    public function requiredProfileName(): string
    {
        return trim((string) Setting::getValue(self::SETTING_REQUIRED_PROFILE, ''));
    }

    /**
     * Required scopes resolved from the chosen profile (publicData stripped —
     * it is always implied). Empty when no profile is chosen OR the chosen
     * profile no longer exists in SeAT (self-heals to "no scope check").
     *
     * @return array<int,string>
     */
    public function requiredScopes(): array
    {
        $name = $this->requiredProfileName();
        if ($name === '') {
            return [];
        }
        foreach (app(RecruitmentSsoService::class)->availableProfiles() as $p) {
            if ((string) ($p->name ?? '') === $name) {
                return array_values(array_filter(
                    array_map('strval', (array) ($p->scopes ?? [])),
                    fn ($s) => $s !== 'publicData'
                ));
            }
        }

        return [];
    }

    /** True when a usable requirement profile is set (drives the scope check). */
    public function requirementActive(): bool
    {
        return $this->requiredScopes() !== [];
    }

    /** True when a profile name is set but no longer resolves (renamed/deleted in SeAT). */
    public function requirementStale(): bool
    {
        $name = $this->requiredProfileName();
        if ($name === '') {
            return false;
        }
        foreach (app(RecruitmentSsoService::class)->availableProfiles() as $p) {
            if ((string) ($p->name ?? '') === $name) {
                return false;
            }
        }

        return true;
    }

    /**
     * Classify one character from its (optionally trashed) refresh_tokens row.
     *
     * @param  object|null  $token  row with ->scopes (json) + ->deleted_at, or null
     * @param  array<int,string>  $required  required scopes (computed once by the caller)
     * @return array{status:string, missing:array<int,string>}
     */
    public function classify(?object $token, array $required): array
    {
        if ($token === null) {
            return ['status' => self::STATUS_NEVER, 'missing' => []];
        }
        if (($token->deleted_at ?? null) !== null) {
            return ['status' => self::STATUS_LOST, 'missing' => []];
        }
        if (empty($required)) {
            return ['status' => self::STATUS_VALID, 'missing' => []];
        }

        $have    = $this->decodeScopes($token->scopes ?? null);
        $missing = array_values(array_diff($required, $have));

        return [
            'status'  => empty($missing) ? self::STATUS_VALID : self::STATUS_INSUFFICIENT,
            'missing' => $missing,
        ];
    }

    /**
     * Token health for a whole corp roster: headline counts + drill-down lists.
     *
     * @return array{
     *   available:bool, requirement_active:bool, requirement_stale:bool,
     *   profile_name:?string, required_scopes:array<int,string>,
     *   total:int, valid:int, insufficient:int, lost:int, never_linked:int, lost_recent:int,
     *   lists:array{insufficient:array, lost:array, never_linked:array}
     * }
     */
    public function corpTokenHealth(int $corporationId, int $recentDays = 7): array
    {
        $source = $this->resolveRosterSource($corporationId);
        if ($source === null) {
            return $this->emptyResult();
        }

        $charIds = DB::table($source)
            ->where('corporation_id', $corporationId)
            ->pluck('character_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (empty($charIds)) {
            return $this->emptyResult();
        }

        $required = $this->requiredScopes();

        // refresh_tokens via DB::table returns soft-deleted rows too (no global
        // scope), which is exactly how we tell "lost" from "never linked".
        $tokens = DB::table('refresh_tokens')
            ->whereIn('character_id', $charIds)
            ->get(['character_id', 'scopes', 'deleted_at'])
            ->keyBy(fn ($r) => (int) $r->character_id);

        $recentCut = now()->subDays(max(1, $recentDays));
        $counts    = ['valid' => 0, 'insufficient' => 0, 'lost' => 0, 'never_linked' => 0, 'lost_recent' => 0];

        // First pass: classify everyone, stash the non-valid ones so we only
        // resolve names (potential ESI hit) for the drill-down members.
        $insufficient = [];
        $lost         = [];
        $never        = [];
        foreach ($charIds as $cid) {
            $token = $tokens->get($cid);
            $c     = $this->classify($token, $required);
            $counts[$c['status']]++;

            if ($c['status'] === self::STATUS_INSUFFICIENT) {
                $insufficient[$cid] = $c['missing'];
            } elseif ($c['status'] === self::STATUS_LOST) {
                $lostAt = ($token->deleted_at ?? null) ? Carbon::parse($token->deleted_at) : null;
                if ($lostAt && $lostAt->gte($recentCut)) {
                    $counts['lost_recent']++;
                }
                $lost[$cid] = $lostAt;
            } elseif ($c['status'] === self::STATUS_NEVER) {
                $never[$cid] = true;
            }
        }

        $nonValidIds = array_values(array_unique(array_merge(
            array_keys($insufficient), array_keys($lost), array_keys($never)
        )));
        $names = empty($nonValidIds)
            ? []
            : app(NameResolutionService::class)->getCharacterNames($nonValidIds);
        $nameOf = fn (int $cid) => $names[$cid] ?? ('#' . $cid);

        $lists = [
            'insufficient' => array_map(
                fn ($cid) => ['character_id' => $cid, 'name' => $nameOf($cid), 'missing' => $insufficient[$cid]],
                array_keys($insufficient)
            ),
            'lost' => array_map(
                fn ($cid) => ['character_id' => $cid, 'name' => $nameOf($cid), 'lost_at' => $lost[$cid]?->toDateString()],
                array_keys($lost)
            ),
            'never_linked' => array_map(
                fn ($cid) => ['character_id' => $cid, 'name' => $nameOf($cid)],
                array_keys($never)
            ),
        ];

        return [
            'available'          => true,
            'requirement_active' => $this->requirementActive(),
            'requirement_stale'  => $this->requirementStale(),
            'profile_name'       => $this->requiredProfileName() ?: null,
            'required_scopes'    => $required,
            'total'              => count($charIds),
            'valid'              => $counts['valid'],
            'insufficient'       => $counts['insufficient'],
            'lost'               => $counts['lost'],
            'never_linked'       => $counts['never_linked'],
            'lost_recent'        => $counts['lost_recent'],
            'lists'              => $lists,
        ];
    }

    private function decodeScopes($raw): array
    {
        if (is_array($raw)) {
            return array_map('strval', $raw);
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return array_map('strval', $decoded);
            }
        }

        return [];
    }

    /** First synced roster table that actually has rows for this corp, or null. */
    private function resolveRosterSource(int $corporationId): ?string
    {
        foreach (['corporation_members', 'corporation_member_trackings'] as $table) {
            if (Schema::hasTable($table)
                && DB::table($table)->where('corporation_id', $corporationId)->exists()) {
                return $table;
            }
        }

        return null;
    }

    private function emptyResult(): array
    {
        return [
            'available'          => false,
            'requirement_active' => $this->requirementActive(),
            'requirement_stale'  => $this->requirementStale(),
            'profile_name'       => $this->requiredProfileName() ?: null,
            'required_scopes'    => $this->requiredScopes(),
            'total' => 0, 'valid' => 0, 'insufficient' => 0,
            'lost' => 0, 'never_linked' => 0, 'lost_recent' => 0,
            'lists' => ['insufficient' => [], 'lost' => [], 'never_linked' => []],
        ];
    }
}
