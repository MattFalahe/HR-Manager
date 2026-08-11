<?php

namespace HrManager\Services;

use HrManager\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-computes and caches the EXPENSIVE half of a player profile so the view
 * reads it instantly instead of fanning out per-alt on every request.
 *
 * The heavy panels (per-alt summary, role profiles, blueprint / buyback,
 * in-game titles, role alignment) are read-only activity aggregates that don't
 * change on any HR action, so they cache safely. The mutable panels a director
 * ACTS on — access depth, Discord roles, squads, notes, history — stay live in
 * the controller and are never bundled here.
 *
 * Two entry points:
 *   - getBundle()  — controller path: return the warm bundle, or build+store on
 *                    a miss (so the very first view of a player still works).
 *   - warm()       — cron path (WarmPlayerProfilesCommand): rebuild ONLY when a
 *                    cheap change-fingerprint says something actually moved (or
 *                    the bundle has aged out), so re-warming a whole roster
 *                    every cycle costs a few indexed max() queries per unchanged
 *                    account instead of the full per-alt fan-out.
 *
 * Bundle TTL bounds worst-case staleness on its own: an unchanged account is
 * rebuilt at most once per TTL when the entry expires, even if nothing ever
 * changes, so a rare invisible change (e.g. an unregistered alt added) still
 * reconciles within the hour.
 */
class PlayerProfileWarmer
{
    /** Opt-in — scheduled pre-warm does background work that scales with roster size. */
    public const SETTING_PREWARM = 'enable_profile_prewarm';

    /**
     * Bundle lifetime when pre-warm is ON: long, because the scheduled warm owns
     * freshness — a real change bumps the fingerprint and rebuilds within a
     * cycle, and MAX_AGE forces a safety rebuild of even-unchanged accounts. Far
     * longer than the cron interval so a warm bundle never lapses between runs,
     * so unchanged accounts are NOT needlessly rebuilt on expiry.
     */
    private const BUNDLE_TTL_WARM = 86400; // 24h

    /**
     * Bundle lifetime when pre-warm is OFF: short, because there's no cron to
     * refresh it, so the on-view cache must re-fetch often enough that a
     * director never sees badly stale activity / last-login numbers.
     */
    private const BUNDLE_TTL_LAZY = 300; // 5 min

    /**
     * Safety rebuild cadence for the cron: even when the fingerprint hasn't
     * moved, rebuild an account at least this often so a change the fingerprint
     * can't see (e.g. an unregistered alt added to the identity) still
     * reconciles instead of serving a forever-stale bundle.
     */
    private const MAX_AGE = 43200; // 12h

    public function isPrewarmEnabled(): bool
    {
        return (bool) Setting::getValue(self::SETTING_PREWARM, false);
    }

    public function bundleKey(int $userId, int $corporationId): string
    {
        return "hr-profile-bundle-{$userId}-{$corporationId}";
    }

    private function fingerprintKey(int $userId, int $corporationId): string
    {
        return "hr-profile-fp-{$userId}-{$corporationId}";
    }

    /**
     * Controller path: return the warm bundle, building + storing it on a miss.
     * Returns null when the user genuinely doesn't exist (the view 404s).
     */
    public function getBundle(int $userId, int $corporationId): ?array
    {
        $cached = Cache::get($this->bundleKey($userId, $corporationId));
        if (is_array($cached) && $this->characterSetUnchanged($cached, $userId)) {
            return $cached;
        }
        return $this->rebuild($userId, $corporationId);
    }

    /**
     * Does the cached bundle still describe the account's actual characters?
     *
     * The cache exists so activity aggregates don't get recomputed per view,
     * and those going a few minutes stale is the whole point. The character
     * LIST is different: a character added or moved away (a SeAT account
     * transfer, a fresh auth) makes the profile look plainly broken — the alt
     * is right there in SeAT and missing from HR — and waiting out a 24-hour
     * TTL for it is not defensible. One indexed lookup to rule that out.
     */
    private function characterSetUnchanged(array $bundle, int $userId): bool
    {
        if (!isset($bundle['characterIds']) || !is_array($bundle['characterIds'])) {
            return false; // pre-dates the key, or malformed — rebuild
        }

        try {
            // Must match how characterIds was built (charactersForUser =>
            // live tokens only). Including revoked ones here would make the
            // sets differ permanently and rebuild on every single view.
            $live = DB::table('refresh_tokens')
                ->where('user_id', $userId)
                ->whereNull('deleted_at')
                ->pluck('character_id')
                ->map(fn ($i) => (int) $i)
                ->sort()->values()->all();
        } catch (\Throwable $e) {
            return true; // can't tell — serve what we have rather than thrash
        }

        $cachedIds = collect($bundle['characterIds'])->map(fn ($i) => (int) $i)->sort()->values()->all();

        return $cachedIds === $live;
    }

    /**
     * Cron path: (re)warm one account. Rebuilds only when the bundle is missing
     * / expired OR the change-fingerprint differs from the stored one; otherwise
     * leaves the still-fresh bundle untouched.
     *
     * @return string 'rebuilt' | 'skipped' | 'empty'
     */
    public function warm(int $userId, int $corporationId): string
    {
        $fp   = $this->fingerprint($userId, $corporationId);
        $meta = Cache::get($this->fingerprintKey($userId, $corporationId));

        // Skip only when the bundle is still present AND the fingerprint matches
        // AND it isn't older than the safety cadence. Otherwise rebuild.
        if (Cache::has($this->bundleKey($userId, $corporationId))
            && is_array($meta)
            && ($meta['fp'] ?? null) === $fp
            && (time() - (int) ($meta['built_at'] ?? 0)) < self::MAX_AGE) {
            return 'skipped';
        }

        return $this->rebuild($userId, $corporationId, $fp) === null ? 'empty' : 'rebuilt';
    }

    /** Build the heavy bundle + persist it with its fingerprint + build time. */
    private function rebuild(int $userId, int $corporationId, ?string $fp = null): ?array
    {
        $bundle = $this->buildBundle($userId, $corporationId);
        if ($bundle === null) {
            return null;
        }
        // Long TTL while the cron owns freshness; short when it doesn't (on-view
        // only), so a pre-warm-off install still refreshes on load.
        $ttl = $this->isPrewarmEnabled() ? self::BUNDLE_TTL_WARM : self::BUNDLE_TTL_LAZY;
        Cache::put($this->bundleKey($userId, $corporationId), $bundle, $ttl);
        Cache::put(
            $this->fingerprintKey($userId, $corporationId),
            ['fp' => $fp ?? $this->fingerprint($userId, $corporationId), 'built_at' => time()],
            $ttl
        );
        return $bundle;
    }

    /**
     * Compute the heavy read-only panels. Kept in one place so the controller
     * and the cron produce identical bundles. Returns null when the user has no
     * SeAT account (nothing to render).
     */
    public function buildBundle(int $userId, int $corporationId): ?array
    {
        $summary = app(PlayerService::class)->getPlayerSummary($userId, $corporationId);
        if (empty($summary['user'])) {
            return null;
        }

        $characterIds = $summary['characters']->pluck('character_id')->map(fn ($i) => (int) $i)->all();

        $titleSnapshot = app(CharacterTitleService::class)->snapshotForUser($characterIds, $corporationId);

        // Per-alt role badges. classify() is itself cached (30 min), so this is
        // mostly cache reads once warm.
        $classifier = app(CharacterRoleClassifier::class);
        $roleProfiles = [];
        foreach ($characterIds as $cid) {
            $profile = $classifier->classify((int) $cid, $corporationId);
            if (!empty($profile['has_data'])) {
                $roleProfiles[(int) $cid] = $profile;
            }
        }

        $fcActivity        = app(FcActivityService::class)->getForUser($userId);
        $blueprintActivity = app(BlueprintActivityService::class)->getForPlayer($characterIds, $corporationId);
        $buyback           = app(BuybackContributionService::class)->forCharacters($characterIds);

        // Optional in-game role/title alignment (Settings → Features).
        $roleAlignment = ['available' => false];
        $roleAlignSvc = app(RoleAlignmentService::class);
        if ($roleAlignSvc->isEnabled() && $userId > 0) {
            $inCorpCharIds = DB::table('character_affiliations')
                ->whereIn('character_id', $characterIds)
                ->where('corporation_id', $corporationId)
                ->pluck('character_id')->map(fn ($c) => (int) $c)->all();
            $mainCharId = (int) optional(\Seat\Web\Models\User::find($userId))->main_character_id;
            $roleAlignment = $roleAlignSvc->forPlayer($mainCharId, $inCorpCharIds, $corporationId);
        }

        return [
            'summary'           => $summary,
            'characterIds'      => $characterIds,
            'titleSnapshot'     => $titleSnapshot,
            'roleProfiles'      => $roleProfiles,
            'fcActivity'        => $fcActivity,
            'blueprintActivity' => $blueprintActivity,
            'buyback'           => $buyback,
            'roleAlignment'     => $roleAlignment,
        ];
    }

    /**
     * Cheap "has anything HR cares about changed?" hash for an account. Built
     * from indexed max() timestamps over the SeAT-synced tables the heavy
     * panels read — logins, wallet, mining, blueprint requests, buyback — plus
     * the registered-character count (alt add / remove). Guards every table so
     * it degrades cleanly when a sibling plugin isn't installed. Far cheaper
     * than buildBundle, so the cron can check thousands of accounts a cycle and
     * only rebuild the handful that actually moved.
     */
    public function fingerprint(int $userId, int $corporationId): string
    {
        $chars = DB::table('refresh_tokens')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->pluck('character_id')
            ->map(fn ($i) => (int) $i)
            ->all();

        $parts = [$corporationId, count($chars)];

        if (empty($chars)) {
            return md5(implode('|', $parts));
        }

        $maxOf = function (string $table, string $column) use ($chars): string {
            try {
                if (!Schema::hasTable($table)) {
                    return '';
                }
                return (string) DB::table($table)->whereIn('character_id', $chars)->max($column);
            } catch (\Throwable $e) {
                return '';
            }
        };

        $parts[] = $maxOf('corporation_member_trackings', 'logon_date');
        $parts[] = $maxOf('character_wallet_journals', 'date');
        $parts[] = $maxOf('mining_ledger', 'date');
        $parts[] = $maxOf('blueprint_requests', 'created_at');
        $parts[] = $maxOf('buyback_contracts', 'updated_at');

        return md5(implode('|', array_map(fn ($p) => (string) $p, $parts)));
    }
}
