<?php

namespace HrManager\Services;

use HrManager\Models\Application;
use HrManager\Models\IntelNote;
use HrManager\Models\Setting;
use HrManager\Models\WatchlistEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Seat\Eveapi\Models\Corporation\CorporationInfo;

/**
 * Screens a NEW application against the blacklist + intel database across the
 * WHOLE applying account — not just the applied main — and, when a flagged
 * character surfaces, proactively alerts directors, records the discovered link,
 * and (opt-in) auto-flags the rest of the account.
 *
 * Closes the loop on a common spy pattern: a known-bad ALT is registered under a
 * clean main during recruitment. The passive assessment panel already shows the
 * hit; this turns it into a push notification + an audit trail, and can flag the
 * whole person so a later corp/alliance scan catches every alt.
 *
 * Called best-effort from ApplicationService::submitApplication; never throws.
 */
class ApplicantScreeningService
{
    /** Setting key: also blacklist the account's OTHER characters on a hit. Off by default. */
    public const SETTING_AUTOFLAG = 'applicant_watchlist_autoflag';

    public function __construct(
        private readonly WatchlistService $watchlist,
    ) {
    }

    public function screen(Application $application, int $submitterUserId): void
    {
        try {
            if (!Schema::hasTable('hr_manager_watchlist_entries')) {
                return;
            }

            $mainCharId     = (int) $application->character_id;
            $accountCharIds = $this->accountCharacterIds($submitterUserId, $mainCharId);
            if (empty($accountCharIds)) {
                return;
            }

            // Scope context = the RECRUITING corp (whose lists we check against):
            // global entries + entries scoped to that corp / its alliance.
            $recruitCorpId     = (int) ($application->corporation_id ?? 0);
            $recruitAllianceId = $recruitCorpId > 0
                ? (int) (CorporationInfo::where('corporation_id', $recruitCorpId)->value('alliance_id') ?? 0)
                : 0;

            // Blacklist hits — active, scoped, resolved across the account by
            // findHistory's own alt logic (shared refresh-token user_id).
            $blacklistHits = $this->watchlist
                ->findHistory($mainCharId, null, $recruitCorpId ?: null, $recruitAllianceId ?: null)
                ->filter(fn ($e) => $e->list_type === WatchlistEntry::TYPE_BLACKLIST
                    && $e->status === WatchlistEntry::STATUS_ACTIVE)
                ->values();

            // Intel hits — any account character carrying an intel note (raw, no
            // viewer scoping: this is a system security check, not a UI read).
            $intelCharIds = Schema::hasTable('hr_manager_intel_notes')
                ? IntelNote::whereIn('character_id', $accountCharIds)
                    ->distinct()->pluck('character_id')->map(fn ($c) => (int) $c)->all()
                : [];

            if ($blacklistHits->isEmpty() && empty($intelCharIds)) {
                return;
            }

            $charNames = app(NameResolutionService::class)->getCharacterNamesWithFallback(
                array_values(array_unique(array_merge(
                    $accountCharIds,
                    $blacklistHits->pluck('character_id')->map(fn ($c) => (int) $c)->all()
                )))
            );
            $mainName = $charNames[$mainCharId] ?? ('Character #' . $mainCharId);

            // 1) Record the discovered link on the account's history timeline.
            $this->recordHistory($application, $submitterUserId, $blacklistHits, $intelCharIds, $charNames);

            // 2) Opt-in: auto-flag the account's OTHER characters.
            $autoFlagged = [];
            if ($blacklistHits->isNotEmpty() && (bool) Setting::getValue(self::SETTING_AUTOFLAG, false)) {
                $autoFlagged = $this->autoFlagAccount($accountCharIds, $blacklistHits->first(), $application, $charNames);
            }

            // 3) Notify directors (best-effort).
            $this->dispatchNotification(fn () => app(NotificationService::class)->notifyFlaggedApplicant(
                $application,
                $mainName,
                $blacklistHits,
                $intelCharIds,
                $charNames,
                count($autoFlagged)
            ));
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] applicant screening failed: ' . $e->getMessage());
        }
    }

    /** Every character id on the submitter's account (live tokens), plus the main. */
    private function accountCharacterIds(int $userId, int $mainCharId): array
    {
        $ids = [$mainCharId];
        if ($userId > 0) {
            $alts = DB::table('refresh_tokens')->where('user_id', $userId)
                ->whereNull('deleted_at')->pluck('character_id')->all();
            $ids = array_merge($ids, $alts);
        }
        return array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0)));
    }

    /** @param \Illuminate\Support\Collection $blacklistHits */
    private function recordHistory(Application $application, int $userId, $blacklistHits, array $intelCharIds, array $charNames): void
    {
        $flaggedNames = $blacklistHits
            ->map(fn ($e) => $charNames[(int) $e->character_id] ?? ('Character #' . $e->character_id))
            ->all();
        foreach ($intelCharIds as $cid) {
            $flaggedNames[] = ($charNames[$cid] ?? ('Character #' . $cid)) . ' (intel)';
        }

        app(HistoryEventService::class)->record(
            'watchlist_application_hit',
            [
                'severity'        => 'critical',
                'application_id'  => $application->id,
                'flagged'         => array_values(array_unique($flaggedNames)),
                'blacklist_count' => $blacklistHits->count(),
                'intel_count'     => count($intelCharIds),
            ],
            [
                'user_id'         => $userId,
                'character_id'    => (int) $application->character_id,
                'corporation_id'  => (int) $application->corporation_id,
                'occurred_at'     => now(),
                'idempotency_key' => 'wl-app-hit:' . $application->id,
            ]
        );
    }

    /**
     * Blacklist the account's OTHER characters (those not already on the list at
     * the trigger's scope), keyed to the same scope + high severity, with a clear
     * auto-generated reason so a director can review or clear them.
     *
     * @return array<int,int> character ids newly flagged
     */
    private function autoFlagAccount(array $accountCharIds, WatchlistEntry $trigger, Application $application, array $charNames): array
    {
        $triggerName = $charNames[(int) $trigger->character_id] ?? ('Character #' . $trigger->character_id);
        $reason = sprintf(
            'Auto-flagged: same account as blacklisted %s%s (application #%d).',
            $triggerName,
            $trigger->reason ? ' — ' . $trigger->reason : '',
            $application->id
        );

        $flagged = [];
        foreach ($accountCharIds as $cid) {
            if ($cid === (int) $trigger->character_id) {
                continue;
            }

            $exists = WatchlistEntry::where('list_type', WatchlistEntry::TYPE_BLACKLIST)
                ->where('character_id', $cid)
                ->where(function ($q) use ($trigger) {
                    if ($trigger->scope_corporation_id === null) {
                        $q->whereNull('scope_corporation_id');
                    } else {
                        $q->where('scope_corporation_id', $trigger->scope_corporation_id);
                    }
                })
                ->exists();
            if ($exists) {
                continue;
            }

            $res = $this->watchlist->addEntry(
                WatchlistEntry::TYPE_BLACKLIST,
                0, // system-generated
                (string) $cid,
                $trigger->scope_corporation_id !== null ? (int) $trigger->scope_corporation_id : null,
                $reason,
                WatchlistEntry::SEVERITY_HIGH
            );
            if (!empty($res['success'])) {
                $flagged[] = $cid;
            }
        }

        return $flagged;
    }

    private function dispatchNotification(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] flagged-applicant notification failed: ' . $e->getMessage());
        }
    }
}
