<?php

namespace HrManager\Services;

use HrManager\Models\IntelNote;
use HrManager\Models\WatchlistEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Periodic monitor for blacklist (and high-impact whitelist) hits:
 *
 *  1. JOINED MANAGED CORP
 *     Scans corporation_members for tracked corps and looks for
 *     character_ids that match active blacklist entries scoped to
 *     that corp (or globally / alliance-of-that-corp). Fires a
 *     Discord notification per detection. Dedup via the
 *     hr_manager_watchlist_detections unique constraint.
 *
 *  2. JOINED MANAGED ALLIANCE
 *     Same logic at alliance level: when a character joins ANY corp
 *     in an alliance we manage, an alliance-scoped blacklist entry
 *     for them fires a detection.
 *
 *  3. EXTERNAL CORP CHANGE
 *     For entries with notify_on_external_change=true, polls the
 *     public ESI /characters/{id}/ endpoint (no auth needed) and
 *     compares the returned corporation_id against the previously
 *     recorded last_external_corp_id. When it changes, fires a
 *     "warning: this person joined {new corp / alliance}, be
 *     vigilant" notification — even if they're outside our reach.
 *
 * Every detection writes to hr_manager_watchlist_detections with a
 * stable composite key (entry_id, character_id, detection_type,
 * detected_corp_id) so the same event doesn't fire twice for the
 * same entry. The notification dispatch happens once per detection
 * row and is logged.
 */
class WatchlistMonitorService
{
    private const ESI_TIMEOUT_SECONDS = 5;
    private const EXTERNAL_POLL_THROTTLE_HOURS = 6;
    // Hard cap on public-ESI polls per scan run so a large blacklist can't
    // burst. The 6h per-entry throttle already spreads them out; this bounds
    // the worst case.
    private const EXTERNAL_POLL_MAX_PER_SCAN = 100;
    // Back off when CCP's shared per-IP ESI error budget drops below this, so
    // the (tokenless) watchlist poll never eats into the budget SeAT's own
    // authenticated ESI calls depend on.
    private const ESI_ERROR_BUDGET_FLOOR = 15;

    /** Set when a poll sees CCP rate-limiting; halts the rest of the scan. */
    private bool $esiBackOff = false;

    /**
     * Run all detection passes. Returns counts for the CLI summary.
     *
     * @return array{corp:int, alliance:int, external:int, intel:int}
     */
    public function scan(): array
    {
        return [
            'corp'     => $this->scanCorpJoins(),
            'alliance' => $this->scanAllianceJoins(),
            'external' => $this->scanExternalChanges(),
            'intel'    => $this->scanIntelScopeMatches(),
        ];
    }

    /**
     * Pass 1: blacklisted character in a corp we manage.
     */
    public function scanCorpJoins(): int
    {
        if (!Schema::hasTable('corporation_members')) {
            return 0;
        }

        $detections = 0;

        // For every active blacklist entry with notify_on_corp_match
        // we figure out the corps the entry covers + look for the
        // character in those corps.
        $entries = WatchlistEntry::where('list_type', WatchlistEntry::TYPE_BLACKLIST)
            ->where('notify_on_corp_match', true)
            ->active()
            ->get();

        foreach ($entries as $entry) {
            $corps = $this->scopedCorpIds($entry);
            if (empty($corps)) {
                continue;
            }

            $foundIn = DB::table('corporation_members')
                ->where('character_id', $entry->character_id)
                ->whereIn('corporation_id', $corps)
                ->pluck('corporation_id')
                ->map(fn($id) => (int) $id)
                ->all();

            foreach ($foundIn as $corpId) {
                if ($this->recordDetection($entry, 'joined_managed_corp', $corpId, null, null)) {
                    $detections++;
                }
            }
        }

        return $detections;
    }

    /**
     * Pass 2: blacklisted character in a corp that's in one of our
     * managed alliances. Distinct from pass 1 because alliance-scoped
     * entries warn about chars joining ANY corp in the alliance, not
     * just our own.
     */
    public function scanAllianceJoins(): int
    {
        if (!Schema::hasTable('corporation_members') || !Schema::hasTable('corporation_infos')) {
            return 0;
        }

        $detections = 0;

        $entries = WatchlistEntry::where('list_type', WatchlistEntry::TYPE_BLACKLIST)
            ->where('notify_on_alliance_match', true)
            ->active()
            ->get();

        // Pull the alliance map for ALL corporations we know about.
        // Cheap enough — corporation_infos is small.
        $corpToAlliance = DB::table('corporation_infos')
            ->whereNotNull('alliance_id')
            ->pluck('alliance_id', 'corporation_id')
            ->map(fn($id) => (int) $id)
            ->all();

        foreach ($entries as $entry) {
            $allianceIds = $this->scopedAllianceIds($entry);
            if (empty($allianceIds)) {
                continue;
            }

            // Corps that are in any of the scope alliances.
            $relevantCorps = array_keys(array_filter(
                $corpToAlliance,
                fn($aid) => in_array($aid, $allianceIds, true)
            ));
            if (empty($relevantCorps)) {
                continue;
            }

            $foundIn = DB::table('corporation_members')
                ->where('character_id', $entry->character_id)
                ->whereIn('corporation_id', $relevantCorps)
                ->pluck('corporation_id')
                ->map(fn($id) => (int) $id)
                ->all();

            foreach ($foundIn as $corpId) {
                $allianceId = $corpToAlliance[$corpId] ?? null;
                if ($this->recordDetection($entry, 'joined_managed_alliance', $corpId, $allianceId, null)) {
                    $detections++;
                }
            }
        }

        return $detections;
    }

    /**
     * Immediate check for a SINGLE entry, run the moment it's added. Mirrors
     * the corp + alliance passes for just this entry so the operator is told
     * straight away if the character is ALREADY inside a scope corp/alliance,
     * instead of waiting for the next cron tick. Idempotent via the same
     * detections-table dedup as the scan, so it never double-notifies with the
     * periodic pass. Best-effort; only acts on active blacklist entries.
     */
    public function checkEntryNow(WatchlistEntry $entry): int
    {
        if ($entry->list_type !== WatchlistEntry::TYPE_BLACKLIST
            || $entry->status !== WatchlistEntry::STATUS_ACTIVE) {
            return 0;
        }

        $detections = 0;

        // Corp pass.
        if ($entry->notify_on_corp_match && Schema::hasTable('corporation_members')) {
            $corps = $this->scopedCorpIds($entry);
            if (!empty($corps)) {
                $foundIn = DB::table('corporation_members')
                    ->where('character_id', $entry->character_id)
                    ->whereIn('corporation_id', $corps)
                    ->pluck('corporation_id')->map(fn ($id) => (int) $id)->all();
                foreach ($foundIn as $corpId) {
                    if ($this->recordDetection($entry, 'joined_managed_corp', $corpId, null, null)) {
                        $detections++;
                    }
                }
            }
        }

        // Alliance pass.
        if ($entry->notify_on_alliance_match
            && Schema::hasTable('corporation_members') && Schema::hasTable('corporation_infos')) {
            $allianceIds = $this->scopedAllianceIds($entry);
            if (!empty($allianceIds)) {
                $corpToAlliance = DB::table('corporation_infos')
                    ->whereNotNull('alliance_id')
                    ->pluck('alliance_id', 'corporation_id')->map(fn ($id) => (int) $id)->all();
                $relevantCorps = array_keys(array_filter(
                    $corpToAlliance,
                    fn ($aid) => in_array($aid, $allianceIds, true)
                ));
                if (!empty($relevantCorps)) {
                    $foundIn = DB::table('corporation_members')
                        ->where('character_id', $entry->character_id)
                        ->whereIn('corporation_id', $relevantCorps)
                        ->pluck('corporation_id')->map(fn ($id) => (int) $id)->all();
                    foreach ($foundIn as $corpId) {
                        if ($this->recordDetection($entry, 'joined_managed_alliance', $corpId, $corpToAlliance[$corpId] ?? null, null)) {
                            $detections++;
                        }
                    }
                }
            }
        }

        return $detections;
    }

    /**
     * Intel pass: an intel-flagged character who is CURRENTLY inside a
     * corp the note watches (its scope corp, or any tracked corp for a
     * global note). Unlike the blacklist passes this is informational,
     * not a security alert — intel can be positive or negative — so the
     * operator just gets a "there's a note on this member" heads-up.
     *
     * Idempotent per corp via the note's own scope_alert_corp_id: it
     * fires once when first seen in a watched corp, and again only if
     * the member later moves to a DIFFERENT watched corp.
     */
    public function scanIntelScopeMatches(): int
    {
        if (!Schema::hasTable('corporation_members') || !Schema::hasTable('hr_manager_intel_notes')) {
            return 0;
        }

        $count = 0;
        IntelNote::active()->chunkById(200, function ($notes) use (&$count) {
            foreach ($notes as $note) {
                if ($this->checkIntelNoteNow($note)) {
                    $count++;
                }
            }
        });

        return $count;
    }

    /**
     * Evaluate a SINGLE intel note against the live corp roster and
     * alert if the character is inside a watched corp we haven't already
     * alerted on. Used both for the immediate check at add time and per
     * note by the periodic scan. Returns true when an alert fired.
     */
    public function checkIntelNoteNow(IntelNote $note): bool
    {
        if (!Schema::hasTable('corporation_members')) {
            return false;
        }
        if ($note->expires_at !== null && $note->expires_at->isPast()) {
            return false;
        }

        $corps = $this->intelScopedCorpIds($note);
        if (empty($corps)) {
            return false;
        }

        $currentCorp = (int) (DB::table('corporation_members')
            ->where('character_id', $note->character_id)
            ->whereIn('corporation_id', $corps)
            ->value('corporation_id') ?? 0);

        if ($currentCorp <= 0) {
            return false;
        }

        // Already alerted for this exact corp — stay quiet until they move.
        if ((int) $note->scope_alert_corp_id === $currentCorp) {
            return false;
        }

        // Mark first (dedup), then notify best-effort — same ordering as
        // the blacklist detection path so a notification failure never
        // re-fires the alert on the next scan.
        $note->forceFill([
            'scope_alert_corp_id' => $currentCorp,
            'scope_alert_sent_at' => now(),
        ])->save();

        try {
            app(NotificationService::class)->notifyIntelInScopeCorp($note, $currentCorp);
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] Intel scope notification failed: ' . $e->getMessage());
        }

        return true;
    }

    /**
     * Corps an intel note watches: its scope corp if set, else every
     * tracked corp (global note). Intel notes have no alliance scope.
     *
     * @return array<int>
     */
    private function intelScopedCorpIds(IntelNote $note): array
    {
        if ($note->scope_corporation_id !== null) {
            return [(int) $note->scope_corporation_id];
        }
        return DB::table('corporation_members')
            ->distinct()
            ->pluck('corporation_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Pass 3: public ESI poll for "where is this person now". Throttled
     * per entry to 6 hours so we don't hammer ESI; only fires for
     * entries with notify_on_external_change=true (opt-in).
     */
    public function scanExternalChanges(): int
    {
        $detections = 0;

        $entries = WatchlistEntry::where('list_type', WatchlistEntry::TYPE_BLACKLIST)
            ->where('notify_on_external_change', true)
            ->active()
            ->where(function ($q) {
                $q->whereNull('last_external_check_at')
                  ->orWhere('last_external_check_at', '<', now()->subHours(self::EXTERNAL_POLL_THROTTLE_HOURS));
            })
            ->orderBy('last_external_check_at') // oldest-checked first, fair rotation
            ->limit(self::EXTERNAL_POLL_MAX_PER_SCAN)
            ->get();

        foreach ($entries as $entry) {
            $data = $this->pollEsiCharacter((int) $entry->character_id);

            // CCP's per-IP ESI error budget is running low — stop polling this
            // run so SeAT's own authenticated ESI keeps its headroom. The
            // current entry stays unchecked and is retried next scan.
            if ($this->esiBackOff) {
                Log::info('[HR Manager] WatchlistMonitor: ESI error budget low — pausing external poll to protect SeAT ESI');
                break;
            }

            $entry->update(['last_external_check_at' => now()]);

            if (!is_array($data)) {
                continue;
            }

            $currentCorpId = isset($data['corporation_id']) ? (int) $data['corporation_id'] : null;
            if ($currentCorpId === null) {
                continue;
            }

            $previousCorpId = $entry->last_external_corp_id;

            // First poll just establishes a baseline; no notification.
            if ($previousCorpId === null) {
                $entry->update(['last_external_corp_id' => $currentCorpId]);
                continue;
            }

            if ((int) $previousCorpId === $currentCorpId) {
                continue;
            }

            // Real change — record + notify.
            if ($this->recordDetection($entry, 'external_corp_change', $currentCorpId, null, (int) $previousCorpId)) {
                $detections++;
            }
            $entry->update(['last_external_corp_id' => $currentCorpId]);
        }

        return $detections;
    }

    // -----------------------------------------------------------------

    /**
     * Corps that an entry's scope covers:
     *   - corp scope:        [scope_corp_id]
     *   - alliance scope:    every corp in that alliance (from
     *                        corporation_infos)
     *   - global:            every tracked corp
     *
     * Used by the corp-join detection pass.
     *
     * @return array<int>
     */
    private function scopedCorpIds(WatchlistEntry $entry): array
    {
        if ($entry->scope_corporation_id !== null) {
            return [(int) $entry->scope_corporation_id];
        }

        if ($entry->scope_alliance_id !== null && Schema::hasTable('corporation_infos')) {
            return DB::table('corporation_infos')
                ->where('alliance_id', $entry->scope_alliance_id)
                ->pluck('corporation_id')
                ->map(fn($id) => (int) $id)
                ->all();
        }

        // Global. Cover only corps we actually have member-tracking for.
        return DB::table('corporation_members')
            ->distinct()
            ->pluck('corporation_id')
            ->map(fn($id) => (int) $id)
            ->all();
    }

    /**
     * Alliances that an entry's scope covers, for the alliance-join
     * detection pass.
     *
     * @return array<int>
     */
    private function scopedAllianceIds(WatchlistEntry $entry): array
    {
        if ($entry->scope_alliance_id !== null) {
            return [(int) $entry->scope_alliance_id];
        }
        if ($entry->scope_corporation_id !== null && Schema::hasTable('corporation_infos')) {
            $allianceId = DB::table('corporation_infos')
                ->where('corporation_id', $entry->scope_corporation_id)
                ->value('alliance_id');
            return $allianceId ? [(int) $allianceId] : [];
        }
        // Global: every known alliance.
        if (!Schema::hasTable('corporation_infos')) {
            return [];
        }
        return DB::table('corporation_infos')
            ->whereNotNull('alliance_id')
            ->distinct()
            ->pluck('alliance_id')
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Insert a detection row if it's new (composite unique catches
     * duplicates). Returns true on first-time-fire, false on dedup.
     */
    private function recordDetection(WatchlistEntry $entry, string $type, ?int $corpId, ?int $allianceId, ?int $previousCorpId): bool
    {
        $existing = DB::table('hr_manager_watchlist_detections')
            ->where('watchlist_entry_id', $entry->id)
            ->where('character_id', $entry->character_id)
            ->where('detection_type', $type)
            ->where('detected_corporation_id', $corpId)
            ->exists();
        if ($existing) {
            return false;
        }

        $now = now();
        DB::table('hr_manager_watchlist_detections')->insert([
            'watchlist_entry_id'      => $entry->id,
            'character_id'            => $entry->character_id,
            'detection_type'          => $type,
            'detected_corporation_id' => $corpId,
            'detected_alliance_id'    => $allianceId,
            'previous_corporation_id' => $previousCorpId,
            'detected_at'             => $now,
            'created_at'              => $now,
            'updated_at'              => $now,
        ]);

        // Fire notification — best effort, isolated.
        try {
            app(NotificationService::class)->notifyWatchlistDetection(
                $entry,
                $type,
                $corpId,
                $allianceId,
                $previousCorpId
            );
            DB::table('hr_manager_watchlist_detections')
                ->where('watchlist_entry_id', $entry->id)
                ->where('character_id', $entry->character_id)
                ->where('detection_type', $type)
                ->where('detected_corporation_id', $corpId)
                ->update(['notification_sent_at' => $now]);
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] Watchlist notification failed: ' . $e->getMessage());
        }

        return true;
    }

    /**
     * Public ESI character endpoint poll. Reuses MM's pattern from
     * NameResolutionService (Http facade, short timeout, log only on
     * exception). Returns the parsed body or null on any failure.
     */
    private function pollEsiCharacter(int $characterId): ?array
    {
        try {
            $response = Http::timeout(self::ESI_TIMEOUT_SECONDS)
                ->withHeaders([
                    'Accept'     => 'application/json',
                    'User-Agent' => 'SeAT-HrManager-WatchlistMonitor/' . config('hr-manager.version', 'unknown'),
                ])
                ->get('https://esi.evetech.net/latest/characters/' . $characterId . '/');

            // This is TOKENLESS public ESI — it never uses or touches SeAT's
            // refresh tokens / ESI keys. The only shared resource is CCP's
            // per-IP error budget, so if we're being rate-limited (420) or the
            // remaining budget is low, raise the back-off flag and bail so we
            // don't erode the headroom SeAT's authenticated calls rely on.
            $remain = $response->header('X-Esi-Error-Limit-Remain');
            if ($response->status() === 420 || ($remain !== null && (int) $remain < self::ESI_ERROR_BUDGET_FLOOR)) {
                $this->esiBackOff = true;
                return null;
            }

            if (!$response->successful()) {
                return null;
            }
            return $response->json();
        } catch (\Throwable $e) {
            Log::debug('[HR Manager] WatchlistMonitor: ESI poll failed: ' . $e->getMessage());
            return null;
        }
    }
}
