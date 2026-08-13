<?php

namespace HrManager\Services;

use HrManager\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Opt-in secondary roster source: EveWho's public corp list.
 *
 * EveWho (zKillboard/Squizz's project) aggregates corp membership from ESI
 * affiliations + killmails and exposes it CORS-enabled at
 *   GET https://evewho.com/api/corplist/{corp_id}?page=N
 *   -> { info, characters: [{character_id, name}, ...],
 *        pagination: {page, limit, total, pages, has_next, has_previous} }
 * 500 characters per page. We upsert into hr_manager_external_roster and let
 * the Members page read it when SeAT has no authoritative roster.
 *
 * Its pagination cannot be relied on. Observed 2026-08-13 against a 717-member
 * corp: every value of ?page (and ?p / ?offset / ?start) returned a byte-identical
 * page 1, while pagination still reported pages: 2 and has_next: true. Following
 * has_next alone therefore re-reads page 1 until MAX_PAGES, spending 20 requests
 * to end up with the 500 rows the first one already had.
 *
 * So the loop checks whether a page ACTUALLY advanced, by the page number it
 * reports and by whether it contributed any new character ids, and stops when it
 * did not. pagination.total is kept as EveWho's own idea of the member count, so
 * a truncated roster can say so instead of looking complete at 500.
 *
 * This is a SECONDARY source, deliberately:
 *   - It is an aggregator snapshot, so it can still list departed members and
 *     miss very recent joins. Rows are name-only (no ESI token behind them).
 *   - It only runs when the operator opts in (Settings → Features), and only
 *     when SeAT itself has no authoritative roster for the corp.
 *   - Every call fails soft: any network / parse error logs a warning and
 *     leaves the previous copy intact — the Members page never breaks on it.
 *
 * The authoritative fix remains a Director token with the
 * read_corporation_membership scope; this just fills the gap until then.
 */
class EveWhoRosterService
{
    private const TABLE      = 'hr_manager_external_roster';
    private const SETTING    = 'enable_evewho_roster';
    private const BASE_URL   = 'https://evewho.com/api/corplist/';
    private const TTL_HOURS  = 24;   // don't re-pull a corp more than once a day
    private const MAX_PAGES  = 20;   // safety cap (20 * 500 = 10000 chars)
    private const HTTP_TIMEOUT = 8;  // per-request seconds
    private const WALL_BUDGET  = 12; // default budget — a first-view seed shouldn't hold the page long
    public const WALL_BUDGET_FULL = 150; // background (cron) budget — fetch every page

    /**
     * Set by sync() when a pull came back short of pagination.total.
     *
     * @var array{got:int, reported:int, stalled:bool}|null
     */
    private ?array $lastShortfall = null;

    /** Operator toggle (Settings → Features). Off by default. */
    public function isEnabled(): bool
    {
        return (bool) Setting::getValue(self::SETTING, false);
    }

    /** How many characters we currently have stored for this corp. */
    public function count(int $corporationId): int
    {
        if (!Schema::hasTable(self::TABLE)) {
            return 0;
        }
        return (int) DB::table(self::TABLE)->where('corporation_id', $corporationId)->count();
    }

    /**
     * Corp ids that already have a stored EveWho roster — i.e. corps a director
     * has actually opened at least once (which seeded them). The daily cron
     * refreshes exactly these, so it never introduces new EveWho traffic on its
     * own; it just keeps the in-use rosters full + current.
     *
     * @return array<int>
     */
    public function syncedCorporationIds(): array
    {
        if (!Schema::hasTable(self::TABLE)) {
            return [];
        }
        return DB::table(self::TABLE)
            ->distinct()
            ->pluck('corporation_id')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /**
     * Corps a currently-registered character belongs to.
     *
     * The same rule that scopes a non-admin director's access, so "the corps
     * this install is actually about" means the same thing here as it does
     * everywhere else in HR. Bounded by the size of the user base rather than
     * by corporation_infos, which holds every corp SeAT has ever resolved and
     * runs to thousands on a real server.
     *
     * @return array<int>
     */
    public function corporationIdsFromRegisteredCharacters(): array
    {
        if (!Schema::hasTable('refresh_tokens') || !Schema::hasTable('character_affiliations')) {
            return [];
        }

        try {
            return DB::table('refresh_tokens')
                ->join('character_affiliations', 'refresh_tokens.character_id', '=', 'character_affiliations.character_id')
                ->whereNull('refresh_tokens.deleted_at')
                ->distinct()
                ->pluck('character_affiliations.corporation_id')
                ->map(fn ($c) => (int) $c)
                ->filter()
                ->values()
                ->all();
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] EveWho: registered-corp lookup failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Corps with a recruitment landing: the ones this install actively
     * recruits for, whether or not anyone has authed for them yet.
     *
     * @return array<int>
     */
    public function corporationIdsWithLandings(): array
    {
        if (!Schema::hasTable('hr_manager_recruitment_landings')) {
            return [];
        }

        try {
            return DB::table('hr_manager_recruitment_landings')
                ->distinct()
                ->pluck('corporation_id')
                ->map(fn ($c) => (int) $c)
                ->filter()
                ->values()
                ->all();
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] EveWho: landing-corp lookup failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Corps SeAT already holds an authoritative roster for.
     *
     * Worth knowing before spending an EveWho request: the Members page only
     * falls through to the back-fill when neither roster table has the corp, so
     * pulling one of these fetches data the page will never display.
     *
     * @return array<int>
     */
    public function corporationIdsWithSeatRoster(): array
    {
        $out = [];
        foreach (['corporation_members', 'corporation_member_trackings'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            try {
                foreach (DB::table($table)->distinct()->pluck('corporation_id') as $c) {
                    $out[(int) $c] = (int) $c;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return array_values($out);
    }

    /** When this corp was last pulled from EveWho, or null if never. */
    public function lastFetchedAt(int $corporationId): ?Carbon
    {
        if (!Schema::hasTable(self::TABLE)) {
            return null;
        }
        $v = DB::table(self::TABLE)->where('corporation_id', $corporationId)->max('fetched_at');
        return $v ? Carbon::parse($v) : null;
    }

    /** Stored roster as [character_id => name] for display fallback. */
    public function storedRoster(int $corporationId): array
    {
        if (!Schema::hasTable(self::TABLE)) {
            return [];
        }
        return DB::table(self::TABLE)
            ->where('corporation_id', $corporationId)
            ->pluck('name', 'character_id')
            ->map(fn ($n) => (string) $n)
            ->toArray();
    }

    private function isStale(int $corporationId): bool
    {
        $last = $this->lastFetchedAt($corporationId);
        return $last === null || $last->lt(now()->subHours(self::TTL_HOURS));
    }

    /**
     * Pull the corp's roster from EveWho and upsert it. Cached: skips the
     * network entirely when the stored copy is still fresh (< TTL) unless
     * $force. $maxSeconds bounds the multi-page pull — the page-load path passes
     * a short budget (a first-view seed), the background cron passes a long one
     * so it fetches EVERY page (this is why a page-load seed can show only the
     * first 500 until the cron completes the roster). Returns the stored
     * character count, or null when disabled / unavailable / the pull failed
     * (fail-soft — the previous copy is kept).
     */
    public function sync(int $corporationId, bool $force = false, ?int $maxSeconds = null): ?int
    {
        if (!$this->isEnabled() || !Schema::hasTable(self::TABLE) || $corporationId <= 0) {
            return null;
        }
        if (!$force && !$this->isStale($corporationId)) {
            return $this->count($corporationId);
        }

        // Attempt cooldown: a fresh success is good for TTL_HOURS (via
        // fetched_at), but a FAILURE leaves the corp stale, which would retry on
        // every page load. Stamp a short cooldown around each attempt so an
        // EveWho outage backs off to ~once / 10 min instead of hammering it (and
        // slowing the Members page) on every view.
        $cooldownKey = 'hr_evewho_attempt_' . $corporationId;
        if (!$force && Cache::has($cooldownKey)) {
            return $this->count($corporationId);
        }
        Cache::put($cooldownKey, 1, now()->addMinutes(10));

        try {
            $idToName = [];
            $reported = null;   // pagination.total, EveWho's own member count
            $stalled  = false;  // a page repeated instead of advancing
            $deadline = microtime(true) + ($maxSeconds ?? self::WALL_BUDGET);

            for ($page = 1; $page <= self::MAX_PAGES; $page++) {
                if (microtime(true) > $deadline) {
                    break; // budget spent — use whatever we have so far
                }

                $resp = Http::withHeaders(['User-Agent' => $this->userAgent()])
                    ->timeout(self::HTTP_TIMEOUT)
                    ->get(self::BASE_URL . $corporationId, ['page' => $page]);

                if (!$resp->ok()) {
                    break;
                }

                $json  = $resp->json();
                $chars = is_array($json) ? ($json['characters'] ?? []) : [];
                if (empty($chars)) {
                    break;
                }

                if (isset($json['pagination']['total'])) {
                    $reported = (int) $json['pagination']['total'];
                }

                // Did this page actually advance? Two independent checks,
                // because the endpoint has been observed serving page 1 for
                // every page number while still reporting has_next: true.
                // Trusting has_next alone spends MAX_PAGES requests re-reading
                // the same rows and silently caps the corp at one page.
                $servedPage = isset($json['pagination']['page']) ? (int) $json['pagination']['page'] : null;
                $before     = count($idToName);

                foreach ($chars as $c) {
                    $cid = (int) ($c['character_id'] ?? 0);
                    if ($cid > 0) {
                        $name = trim((string) ($c['name'] ?? ''));
                        $idToName[$cid] = $name !== '' ? $name : null;
                    }
                }

                $wrongPage = $servedPage !== null && $servedPage !== $page;
                $noNewRows = count($idToName) === $before;

                if ($page > 1 && ($wrongPage || $noNewRows)) {
                    $stalled = true;
                    break;
                }

                if (!($json['pagination']['has_next'] ?? false)) {
                    break;
                }
            }

            // Empty result = treat as a transient miss; keep the previous copy
            // rather than wiping the corp's roster to zero.
            if (empty($idToName)) {
                return $this->count($corporationId);
            }

            $this->store($corporationId, $idToName);

            $got = count($idToName);

            // Short of what EveWho itself says the corp has. Worth saying out
            // loud: the roster looks complete from the inside, and a director
            // comparing it to the corp's real size needs to know the ceiling is
            // the API's, not theirs.
            if ($reported !== null && $got < $reported) {
                $this->lastShortfall = ['got' => $got, 'reported' => $reported, 'stalled' => $stalled];
                Log::info(sprintf(
                    '[HR Manager] EveWho returned %d of %d members for corp %d%s.',
                    $got,
                    $reported,
                    $corporationId,
                    $stalled ? ' (its pagination served the same page again, so the rest is unreachable)' : ''
                ));
            } else {
                $this->lastShortfall = null;
            }

            return $got;
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] EveWho roster sync failed for corp ' . $corporationId . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Detail about the most recent sync(), when it came back short.
     *
     * sync() returns a plain count so its existing callers keep working; this
     * carries the bit they had no way to ask about, namely that the count is a
     * ceiling imposed by EveWho rather than the corp's real size.
     *
     * @return array{got:int, reported:int, stalled:bool}|null
     */
    public function lastShortfall(): ?array
    {
        return $this->lastShortfall;
    }


    /**
     * Replace the corp's stored roster with the freshly pulled set. Delete +
     * re-insert inside a transaction so a reader never sees a half-written
     * roster, and departed characters drop out cleanly.
     *
     * @param  array<int,?string>  $idToName
     */
    private function store(int $corporationId, array $idToName): void
    {
        $now  = now();
        $rows = [];
        foreach ($idToName as $cid => $name) {
            $rows[] = [
                'corporation_id' => $corporationId,
                'character_id'   => (int) $cid,
                'name'           => $name,
                'source'         => 'evewho',
                'fetched_at'     => $now,
                'created_at'     => $now,
                'updated_at'     => $now,
            ];
        }

        DB::transaction(function () use ($corporationId, $rows) {
            DB::table(self::TABLE)->where('corporation_id', $corporationId)->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table(self::TABLE)->insert($chunk);
            }
        });
    }

    /**
     * Descriptive User-Agent per the zKillboard/EveWho convention — identifies
     * the plugin + a contact so the operator (and EveWho) can trace traffic.
     */
    private function userAgent(): string
    {
        return 'SeAT-HR-Manager/1.0 (+https://github.com/MattFalahe/HR-Manager; mattfalahe@gmail.com)';
    }
}
