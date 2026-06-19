<?php

namespace HrManager\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class CrossPluginDataService
{
    /**
     * Cache for plugin availability checks within a single request.
     */
    private array $pluginCache = [];

    /**
     * Sentinel classes proving a sibling plugin is installed WITHOUT
     * needing Manager Core's bridge. Used as the fallback path for
     * plugins whose data HR reads via direct model queries (not bridge
     * capabilities) — so e.g. Mining Manager data still shows on an
     * MM-without-MC install.
     *
     * Only list plugins HR can read DIRECTLY. Corp Wallet Manager is
     * deliberately absent: its contribution data is exposed solely
     * through bridge capabilities, so without MC there is genuinely
     * no path to it — no sentinel would change that.
     */
    private const DIRECT_QUERY_SENTINELS = [
        'mining-manager' => 'MiningManager\MiningManagerServiceProvider',
    ];

    /**
     * Check if a sibling plugin is available.
     *
     * Primary path: Manager Core's PluginBridge::hasPlugin(). When MC
     * is absent, falls back to a sentinel-class existence check for
     * plugins HR reads via direct model queries (see
     * DIRECT_QUERY_SENTINELS) — so the MM integration no longer
     * silently requires MC just to gate a query that never touched
     * the bridge anyway.
     */
    public function isPluginAvailable(string $pluginName): bool
    {
        if (isset($this->pluginCache[$pluginName])) {
            return $this->pluginCache[$pluginName];
        }

        // Bridge path (preferred — authoritative when MC is present).
        if (class_exists('ManagerCore\Services\PluginBridge')) {
            try {
                $bridge = app(\ManagerCore\Services\PluginBridge::class);
                if ($bridge->hasPlugin($pluginName)) {
                    return $this->pluginCache[$pluginName] = true;
                }
            } catch (\Exception $e) {
                // Fall through to the sentinel fallback below.
            }
        }

        // Fallback path: direct sentinel-class check. Lets HR read a
        // sibling plugin's models even when MC isn't installed.
        $sentinel = self::DIRECT_QUERY_SENTINELS[$pluginName] ?? null;
        if ($sentinel !== null && class_exists($sentinel)) {
            return $this->pluginCache[$pluginName] = true;
        }

        return $this->pluginCache[$pluginName] = false;
    }

    /**
     * Get mining history for a character from Mining Manager.
     */
    public function getMiningHistory(int $characterId, int $months = 6): array
    {
        if (!$this->isPluginAvailable('mining-manager')) {
            return ['available' => false, 'data' => []];
        }

        try {
            // Direct model query — all plugins share the same Laravel app
            $summaryClass = 'MiningManager\Models\MiningLedgerMonthlySummary';
            if (!class_exists($summaryClass)) {
                return ['available' => false, 'data' => []];
            }

            $summaries = $summaryClass::where('character_id', $characterId)
                ->where('month', '>=', now()->subMonths($months)->startOfMonth())
                ->orderBy('month', 'desc')
                ->get();

            return [
                'available'        => true,
                'monthly_summaries' => $summaries,
                'total_value'      => $summaries->sum('total_value'),
                'total_tax'        => $summaries->sum('total_tax'),
            ];
        } catch (\Exception $e) {
            Log::warning('[HR Manager] Mining data fetch failed: ' . $e->getMessage());
            return ['available' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Top ores a character mines, by total quantity over the window.
     * Direct query on mining_ledger (MM's raw table) joined to SeAT's
     * invTypes for ore names. "Favourite ores" — what they pull most.
     *
     * @return array{available: bool, ores?: array<int,array{type_id:int,name:string,quantity:int}>}
     */
    public function getTopMiningOres(int $characterId, int $months = 6, int $limit = 6): array
    {
        if (!$this->isPluginAvailable('mining-manager') || !Schema::hasTable('mining_ledger')) {
            return ['available' => false];
        }
        try {
            $rows = DB::table('mining_ledger')
                ->where('character_id', $characterId)
                ->where('date', '>=', now()->subMonths($months)->startOfMonth())
                ->groupBy('type_id')
                ->selectRaw('type_id, SUM(quantity) as qty')
                ->orderByDesc('qty')
                ->limit($limit)
                ->get();

            if ($rows->isEmpty()) {
                return ['available' => true, 'ores' => []];
            }

            $names = DB::table('invTypes')
                ->whereIn('typeID', $rows->pluck('type_id')->all())
                ->pluck('typeName', 'typeID')
                ->toArray();

            $ores = $rows->map(fn($r) => [
                'type_id'  => (int) $r->type_id,
                'name'     => $names[$r->type_id] ?? ('Type #' . $r->type_id),
                'quantity' => (int) $r->qty,
            ])->all();

            return ['available' => true, 'ores' => $ores];
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] getTopMiningOres failed: ' . $e->getMessage());
            return ['available' => false];
        }
    }

    /**
     * Top solar systems a character mines in, by total quantity over
     * the window. Direct query on mining_ledger joined to SeAT's
     * solar_systems for names. "Favourite systems" — where they mine.
     *
     * @return array{available: bool, systems?: array<int,array{system_id:int,name:string,quantity:int,entries:int}>}
     */
    public function getTopMiningSystems(int $characterId, int $months = 6, int $limit = 6): array
    {
        if (!$this->isPluginAvailable('mining-manager') || !Schema::hasTable('mining_ledger')) {
            return ['available' => false];
        }
        try {
            $rows = DB::table('mining_ledger')
                ->where('character_id', $characterId)
                ->where('date', '>=', now()->subMonths($months)->startOfMonth())
                ->groupBy('solar_system_id')
                ->selectRaw('solar_system_id, SUM(quantity) as qty, COUNT(*) as entries')
                ->orderByDesc('qty')
                ->limit($limit)
                ->get();

            if ($rows->isEmpty()) {
                return ['available' => true, 'systems' => []];
            }

            $names = [];
            if (Schema::hasTable('solar_systems')) {
                $names = DB::table('solar_systems')
                    ->whereIn('system_id', $rows->pluck('solar_system_id')->all())
                    ->pluck('name', 'system_id')
                    ->toArray();
            }

            $systems = $rows->map(fn($r) => [
                'system_id' => (int) $r->solar_system_id,
                'name'      => $names[$r->solar_system_id] ?? ('System #' . $r->solar_system_id),
                'quantity'  => (int) $r->qty,
                'entries'   => (int) $r->entries,
            ])->all();

            return ['available' => true, 'systems' => $systems];
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] getTopMiningSystems failed: ' . $e->getMessage());
            return ['available' => false];
        }
    }

    /**
     * Mining event attendance for a character — how many of the corp's
     * ore ops they showed up to. A strong engagement / retention
     * signal: "attended 8 of the corp's 12 ops (67%)".
     *
     * Numerator: distinct REAL events (completed/active, never planned)
     * the character has an event_participants row for, in the window.
     * Denominator: all real corp events in the window. Rate = the
     * fraction of ops they participated in.
     *
     * Also returns the most recent events with the character's mined
     * quantity so the profile can show "what they brought".
     *
     * @return array{available: bool, attended?: int, total_events?: int,
     *               rate_pct?: ?int, recent?: array}
     */
    public function getMiningEventAttendance(int $characterId, int $corporationId, int $months = 6): array
    {
        if (!$this->isPluginAvailable('mining-manager')
            || !Schema::hasTable('mining_events')
            || !Schema::hasTable('event_participants')) {
            return ['available' => false];
        }
        try {
            $since = now()->subMonths($months);
            $realStatuses = ['completed', 'active'];

            // Denominator: corp's real events in the window.
            $totalEvents = DB::table('mining_events')
                ->where('corporation_id', $corporationId)
                ->whereIn('status', $realStatuses)
                ->where('start_time', '>=', $since)
                ->count();

            // Events this character participated in (corp-scoped, real).
            $attendedRows = DB::table('event_participants as ep')
                ->join('mining_events as me', 'me.id', '=', 'ep.event_id')
                ->where('ep.character_id', $characterId)
                ->where('me.corporation_id', $corporationId)
                ->whereIn('me.status', $realStatuses)
                ->where('me.start_time', '>=', $since)
                ->orderByDesc('me.start_time')
                ->get([
                    'me.id', 'me.name', 'me.start_time', 'me.type',
                    'ep.quantity_mined', 'ep.value_mined',
                ]);

            $attended = $attendedRows->count();
            $ratePct = $totalEvents > 0 ? (int) round(($attended / $totalEvents) * 100) : null;

            $recent = $attendedRows->take(5)->map(fn($r) => [
                'event_id' => (int) $r->id,
                'name'     => $r->name ?: ('Op #' . $r->id),
                'date'     => $r->start_time,
                'type'     => $r->type,
                'quantity' => (int) ($r->quantity_mined ?? 0),
                'value'    => (float) ($r->value_mined ?? 0),
            ])->all();

            return [
                'available'    => true,
                'attended'     => $attended,
                'total_events' => $totalEvents,
                'rate_pct'     => $ratePct,
                'recent'       => $recent,
            ];
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] getMiningEventAttendance failed: ' . $e->getMessage());
            return ['available' => false];
        }
    }

    /**
     * Get tax records for a character from Mining Manager.
     */
    public function getTaxHistory(int $characterId, int $months = 6): array
    {
        if (!$this->isPluginAvailable('mining-manager')) {
            return ['available' => false, 'data' => []];
        }

        try {
            $taxClass = 'MiningManager\Models\MiningTax';
            if (!class_exists($taxClass)) {
                return ['available' => false, 'data' => []];
            }

            $taxes = $taxClass::where('character_id', $characterId)
                ->where('created_at', '>=', now()->subMonths($months)->startOfMonth())
                ->orderBy('created_at', 'desc')
                ->get();

            $totalOwed = $taxes->sum('amount_owed');
            $totalPaid = $taxes->sum('amount_paid');

            return [
                'available'      => true,
                'tax_records'    => $taxes,
                'total_owed'     => $totalOwed,
                'total_paid'     => $totalPaid,
                'compliance_pct' => $totalOwed > 0 ? round(($totalPaid / $totalOwed) * 100, 2) : 100,
            ];
        } catch (\Exception $e) {
            Log::warning('[HR Manager] Tax data fetch failed: ' . $e->getMessage());
            return ['available' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get ore breakdown for a character from Mining Manager.
     */
    public function getOreBreakdown(int $characterId, int $months = 6): array
    {
        if (!$this->isPluginAvailable('mining-manager')) {
            return ['available' => false, 'data' => []];
        }

        try {
            $summaryClass = 'MiningManager\Models\MiningLedgerMonthlySummary';
            if (!class_exists($summaryClass)) {
                return ['available' => false, 'data' => []];
            }

            $summaries = $summaryClass::where('character_id', $characterId)
                ->where('month', '>=', now()->subMonths($months)->startOfMonth())
                ->whereNotNull('ore_breakdown')
                ->get();

            // Merge ore_breakdown JSON from all months. Newer Mining
            // Manager versions store each ore as a nested object
            // {volume, value} rather than a flat number, so the
            // straight `+` failed with "int + array". This handles
            // both shapes plus the case where the row is a plain
            // numeric (legacy data).
            $breakdown = [];
            foreach ($summaries as $summary) {
                $ores = $summary->ore_breakdown;
                if (is_array($ores)) {
                    foreach ($ores as $ore => $value) {
                        if (is_numeric($value)) {
                            $numeric = (float) $value;
                        } elseif (is_array($value)) {
                            // Prefer ISK value over raw volume; fall
                            // back to either if one is missing.
                            $numeric = (float) ($value['value'] ?? $value['volume'] ?? $value['amount'] ?? 0);
                        } elseif (is_object($value)) {
                            $numeric = (float) ($value->value ?? $value->volume ?? $value->amount ?? 0);
                        } else {
                            $numeric = 0;
                        }
                        $breakdown[$ore] = ($breakdown[$ore] ?? 0) + $numeric;
                    }
                }
            }

            arsort($breakdown);

            return [
                'available' => true,
                'breakdown' => $breakdown,
            ];
        } catch (\Exception $e) {
            Log::warning('[HR Manager] Ore breakdown fetch failed: ' . $e->getMessage());
            return ['available' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get ratting income for a character from Corp Wallet Manager bridge.
     */
    public function getRattingIncome(int $characterId, int $corporationId, int $months = 6): array
    {
        if (!class_exists('ManagerCore\Services\PluginBridge')) {
            return ['available' => false, 'data' => []];
        }

        try {
            $bridge = app(\ManagerCore\Services\PluginBridge::class);

            if (!$bridge->hasCapability('corp-wallet-manager', 'ratting.getCharacterIncome')) {
                return ['available' => false, 'data' => []];
            }

            $income = $bridge->call('corp-wallet-manager', 'ratting.getCharacterIncome', $characterId, $corporationId, $months);
            $monthly = $bridge->call('corp-wallet-manager', 'ratting.getCharacterMonthly', $characterId, $corporationId, $months);

            return [
                'available'     => true,
                'total_income'  => $income->total_income ?? 0,
                'tx_count'      => $income->transaction_count ?? 0,
                'last_activity' => $income->last_activity ?? null,
                'monthly'       => $monthly,
            ];
        } catch (\Exception $e) {
            Log::warning('[HR Manager] Ratting data fetch failed: ' . $e->getMessage());
            return ['available' => false, 'error' => $e->getMessage()];
        }
    }

    // ==================================================================
    // CWM round-2 wallet signals — consumed by the Corp Health classifier
    // and the member profile's Wallet Activity panel. Every method goes
    // through MC's PluginBridge with a capability-name string; HR has no
    // direct reference to the corp-wallet-manager class namespace.
    //
    // All methods return ['available' => bool, ...] so callers can branch
    // on whether MC is installed AND the underlying CWM capability is
    // registered. Returning a structured "not available" instead of
    // throwing means the classifier can simply skip that scoring input
    // when CWM isn't on the install.
    // ==================================================================

    /**
     * Trend slope, average velocity, last-3-months vs prior-3-months
     * %-change, full per-period contribution series. HR uses the slope
     * + recent_vs_prior_pct as direct classifier inputs.
     */
    public function getCharacterContributionTrend(int $characterId, int $corporationId, int $months = 6): array
    {
        return $this->callBridge('contribution.getCharacterTrend', [$characterId, $corporationId, $months]);
    }

    /**
     * Zero-contribution gap analysis — gap_count, longest_gap_months,
     * months_with_activity, last_active_period. Drives the purge
     * workflow's T-7/T-3/T-48/T-0 ladder.
     */
    public function getCharacterActivityGaps(int $characterId, int $corporationId, int $months = 12): array
    {
        return $this->callBridge('contribution.getActivityGaps', [$characterId, $corporationId, $months]);
    }

    /**
     * Contributed minus withdrawn over trailing N months. is_net_positive
     * flag + withdrawal/contribution ratio catches "takes more than they
     * give" patterns.
     */
    public function getCharacterNetPosition(int $characterId, int $corporationId, int $months = 6): array
    {
        return $this->callBridge('contribution.getNetPosition', [$characterId, $corporationId, $months]);
    }

    /**
     * All-time aggregate — lifetime_total_contributed,
     * lifetime_total_withdrawn, months_active, first/last contribution
     * period. Used by the milestone history events + the "tenure summary"
     * UI line on the member profile.
     */
    public function getCharacterLifetimeSummary(int $characterId, int $corporationId): array
    {
        return $this->callBridge('contribution.getLifetimeSummary', [$characterId, $corporationId]);
    }

    /**
     * Where this member ranks vs the corp cohort for a given period.
     * Returns percentile (0-100) + corp_median / p25 / p75 +
     * character_amount. UI uses for the "top X% contributor" badge.
     */
    public function getCharacterContributionPercentile(int $characterId, int $corporationId, string $period): array
    {
        return $this->callBridge('contribution.getCharacterPercentile', [$characterId, $corporationId, $period]);
    }

    /**
     * Per-character MM tax compliance over months. Returns null
     * top-level when MM isn't installed (the underlying CWM capability
     * returns null in that case); HR's classifier treats "null"
     * specifically as "no tax signal to score on" and skips this input
     * rather than scoring zero.
     */
    public function getCharacterTaxCompliance(int $characterId, int $corporationId, int $months = 6): array
    {
        return $this->callBridge('contribution.getCharacterTaxCompliance', [$characterId, $corporationId, $months]);
    }

    /**
     * Best-effort director-action attribution for a corp's outgoing
     * payments. Critical-alert pathway uses to flag directors moving
     * large ISK while otherwise quiet.
     */
    public function getDirectorAttribution(int $corporationId, int $months = 3, int $minAmount = 50_000_000): array
    {
        return $this->callBridge('wallet.getDirectorAttribution', [$corporationId, $months, $minAmount]);
    }

    /**
     * Corp wallet OUTFLOWS over a window, grouped by recipient. Powers
     * the Corp Health "Wallet Outflows" card — where is the corp wallet
     * spending its ISK, who's receiving it, and how much is
     * unattributed (CCP rarely structures the acting director on
     * outgoing journal entries). Director-tier surface.
     */
    public function getCorpOutflows(int $corporationId, int $months = 3): array
    {
        return $this->callBridge('wallet.getCorpOutflows', [$corporationId, $months]);
    }

    /**
     * Corp-wide per-member financial roll-up from CWM — EVERY member with
     * wallet activity (registered or not), in one call. Powers the Corp
     * Health Wallet Insights cards corp-wide instead of HR's registered-only
     * assessment cache. months=0 = all-time. Returns the standard bridge
     * envelope: ['available' => bool, 'data' => ['available', 'members' =>
     * [...]]].
     */
    public function getCorpMemberFinancials(int $corporationId, int $months = 0): array
    {
        return $this->callBridge('contribution.getCorpMemberSummary', [$corporationId, $months]);
    }

    /**
     * Per-category breakdown of a character's contributions (donations /
     * mining tax / ratting tax / etc.). Powers the "top contribution
     * categories" subsection on the member profile's Wallet Activity
     * panel.
     */
    public function getCharacterCategoryBreakdown(int $characterId, int $corporationId, int $months = 6): array
    {
        return $this->callBridge('contribution.getCharacterByCategory', [$characterId, $corporationId, $months]);
    }

    /**
     * Ratting income breakdown by wallet ref_type — bounties vs agent
     * mission rewards. IMPORTANT: this is income SOURCE, not site type.
     * CCP's wallet journal has no concept of "Sanctum vs Haven vs belt"
     * — a bounty_prizes entry is identical regardless of where the NPC
     * died. So the breakdown answers "is this member bounty-ratting or
     * mission-running", not "what sites are they running".
     */
    public function getRattingBreakdown(int $characterId, int $corporationId, int $months = 6): array
    {
        return $this->callBridge('ratting.getCharacterBreakdown', [$characterId, $corporationId, $months]);
    }

    /**
     * Monthly ratting income series (YYYY-MM => total). Powers the
     * activity-consistency sparkline on the Wallet Audit panel.
     */
    public function getRattingMonthly(int $characterId, int $corporationId, int $months = 6): array
    {
        return $this->callBridge('ratting.getCharacterMonthly', [$characterId, $corporationId, $months]);
    }

    /**
     * Most-recent contribution entries for a character (think
     * mini-statement). Powers the "latest 5 entries" subsection on the
     * member profile. Each row is a flat transaction summary.
     */
    public function getCharacterRecentEntries(int $characterId, int $corporationId, int $limit = 5): array
    {
        return $this->callBridge('contribution.getCharacterEntries', [$characterId, $corporationId, $limit]);
    }

    /**
     * Single MC PluginBridge call wrapper. Handles the
     * class_exists guard + capability presence check + try/catch in one
     * place so every wallet-signal method above is one line. Returns
     * ['available' => false] consistently when MC or the capability is
     * absent; otherwise wraps the raw response with available=true and
     * spreads the response into the result.
     */
    private function callBridge(string $capabilityName, array $args): array
    {
        return $this->callPluginBridge('corp-wallet-manager', $capabilityName, $args);
    }

    /**
     * Generalized MC PluginBridge call wrapper for any publisher plugin.
     * Same guard + capability-presence + try/catch contract as callBridge,
     * but the publisher is a parameter so HR can reach Blueprint Manager
     * (and any future capability provider) through the same path.
     */
    private function callPluginBridge(string $plugin, string $capabilityName, array $args): array
    {
        if (!class_exists('ManagerCore\Services\PluginBridge')) {
            return ['available' => false, 'reason' => 'manager_core_absent'];
        }

        try {
            $bridge = app(\ManagerCore\Services\PluginBridge::class);

            if (!$bridge->hasCapability($plugin, $capabilityName)) {
                return ['available' => false, 'reason' => 'capability_not_registered'];
            }

            $result = $bridge->call($plugin, $capabilityName, ...$args);

            // Capability may return null when an underlying optional
            // signal isn't available. Preserve that as available=true with
            // data=null so callers can distinguish "no MC" from "MC routed
            // but underlying signal absent".
            return [
                'available' => true,
                'data'      => $result,
            ];
        } catch (\Exception $e) {
            Log::warning('[HR Manager] Bridge call failed: ' . $plugin . '/' . $capabilityName . ': ' . $e->getMessage());
            return ['available' => false, 'reason' => 'call_failed', 'error' => $e->getMessage()];
        }
    }

    // -----------------------------------------------------------------
    // Blueprint Manager (blueprint.* capabilities)
    // -----------------------------------------------------------------

    /**
     * Per-character blueprint-request engagement (counts by status,
     * rejection rate, favourite types) scoped to one corp. Routed through
     * MC's PluginBridge to Blueprint Manager.
     */
    public function getBlueprintCharacterStats(int $characterId, int $corporationId): array
    {
        return $this->callPluginBridge('blueprint-manager', 'blueprint.getCharacterStats', [$characterId, $corporationId]);
    }

    /**
     * Corp-wide blueprint-request rollup (totals, unique requesters,
     * pending backlog age, top requesters).
     */
    public function getBlueprintCorpSummary(int $corporationId): array
    {
        return $this->callPluginBridge('blueprint-manager', 'blueprint.getCorpSummary', [$corporationId]);
    }

    // -----------------------------------------------------------------
    // Structure Manager (structure-doctrine compliance)
    // -----------------------------------------------------------------

    /**
     * Doctrine-compliance report for a corp's Upwell structures, sourced from
     * Structure Manager via Manager Core's PluginBridge. SM OWNS this feature;
     * HR consumes it (no local compute). Returns SM's `forCorporation` payload
     * (available / summary / structures / ...) when reachable, or
     * ['available' => false, 'reason' => 'sm_absent'] when Structure Manager (or
     * Manager Core) isn't installed, so the Corp Health tab can show a clear
     * "Structure Manager required" notice instead of a blank panel.
     */
    public function getStructureCompliance(int $corporationId): array
    {
        $res = $this->callPluginBridge('structure-manager', 'compliance.getForCorporation', [$corporationId]);
        if (empty($res['available'])) {
            return ['available' => false, 'reason' => 'sm_absent'];
        }
        $data = $res['data'] ?? null;
        return is_array($data) ? $data : ['available' => false, 'reason' => 'sm_absent'];
    }
}
