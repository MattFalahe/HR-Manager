<?php

namespace HrManager\Services;

use HrManager\Models\BuybackActivity;
use HrManager\Models\BuybackPolicy;
use Illuminate\Support\Facades\Schema;
use Seat\Eveapi\Models\Corporation\CorporationInfo;

/**
 * Reads accumulated buyback activity through the per-corp valuation policy.
 *
 * Activity rows store the RAW facts (contributor, running corp, value, BB
 * target model). Tier, weight and corp attribution are resolved here on read
 * from BuybackPolicy, so editing a policy re-values history with no re-import.
 *
 * The policy default is derived from BB's own target model (my_corp ->
 * community, corp -> direct credited to the target corporation, player ->
 * personal/uncounted), so an alt / holding corp that BB already delivers to the
 * main corp is auto-credited there until the operator overrides it.
 *
 * Self-hides when its table is absent (BB / MC not installed).
 */
class BuybackContributionService
{
    /** BB target_type values (string contract — BB classes are never imported). */
    private const TARGET_MY_CORP = 'my_corp';
    private const TARGET_CORP    = 'corp';
    private const TARGET_PLAYER  = 'player';

    public function isAvailable(): bool
    {
        return Schema::hasTable('hr_manager_buyback_activity')
            && Schema::hasTable('hr_manager_buyback_policies');
    }

    /**
     * Effective policy for a buyback-running corp: the operator's saved row, or
     * a smart default derived from the corp's observed BB target model.
     *
     * @return array{counted:bool,tier:string,weight:float,attributed_corporation_id:int,is_default:bool}
     */
    public function policyFor(int $corporationId, ?array $observed = null): array
    {
        $row = BuybackPolicy::where('corporation_id', $corporationId)->first();
        if ($row !== null) {
            return [
                'counted'                   => (bool) $row->counted,
                'tier'                      => $row->tier,
                'weight'                    => (float) $row->weight,
                'attributed_corporation_id' => $row->attributedCorporationId(),
                'is_default'                => false,
            ];
        }

        return $this->defaultPolicy($corporationId, $observed);
    }

    /**
     * Per-account buyback summary for the member / player profile. Pass every
     * character id on the account so alts roll up into one figure.
     */
    public function forCharacters(array $characterIds): array
    {
        $characterIds = array_values(array_filter(array_map('intval', $characterIds)));
        if (empty($characterIds) || !$this->isAvailable()) {
            return ['available' => false];
        }

        $rows = BuybackActivity::whereIn('character_id', $characterIds)->get();
        if ($rows->isEmpty()) {
            return ['available' => true, 'has_data' => false];
        }

        $offers    = $rows->where('stage', BuybackActivity::STAGE_OFFER);
        $completed = $rows->where('stage', BuybackActivity::STAGE_COMPLETED);

        $policies = [];
        $rawTotal = 0.0;
        $weightedTotal = 0.0;
        $byTier = [BuybackPolicy::TIER_DIRECT => 0.0, BuybackPolicy::TIER_COMMUNITY => 0.0, BuybackPolicy::TIER_PERSONAL => 0.0];
        $creditedCorps = [];

        foreach ($completed as $r) {
            $pol = $this->cachedPolicy((int) $r->corporation_id, $policies);
            $rawTotal += (float) $r->total_value;
            if (!$pol['counted']) {
                continue;
            }
            $w = (float) $r->total_value * $pol['weight'];
            $weightedTotal += $w;
            $byTier[$pol['tier']] = ($byTier[$pol['tier']] ?? 0) + $w;
            $ac = $pol['attributed_corporation_id'];
            $creditedCorps[$ac] = ($creditedCorps[$ac] ?? 0) + $w;
        }

        return [
            'available'        => true,
            'has_data'         => true,
            'offers_count'     => $offers->count(),
            'completed_count'  => $completed->count(),
            'raw_value'        => $rawTotal,
            'weighted_value'   => $weightedTotal,
            'by_tier'          => $byTier,
            'credited_corps'   => $this->nameCorps($creditedCorps),
            'last_activity'    => $rows->max('occurred_at'),
        ];
    }

    /**
     * Corp-level rollup: contributions CREDITED to this corp (via attribution),
     * with the top contributors. Drives the Corp Health -> Economy card.
     */
    public function forCorporation(int $corporationId): array
    {
        if (!$this->isAvailable()) {
            return ['available' => false];
        }

        $completed = BuybackActivity::completed()->get();
        if ($completed->isEmpty()) {
            return ['available' => true, 'has_data' => false];
        }

        $policies = [];
        $weighted = 0.0;
        $raw = 0.0;
        $count = 0;
        $perContributor = [];

        foreach ($completed as $r) {
            $pol = $this->cachedPolicy((int) $r->corporation_id, $policies);
            if (!$pol['counted'] || $pol['attributed_corporation_id'] !== $corporationId) {
                continue;
            }
            $w = (float) $r->total_value * $pol['weight'];
            $weighted += $w;
            $raw += (float) $r->total_value;
            $count++;
            $cid = (int) $r->character_id;
            $perContributor[$cid] = ($perContributor[$cid] ?? 0) + $w;
        }

        if ($count === 0) {
            return ['available' => true, 'has_data' => false];
        }

        arsort($perContributor);
        $topIds = array_slice(array_keys($perContributor), 0, 5, true);
        $names = $this->characterNames($topIds);
        $top = [];
        foreach ($topIds as $cid) {
            $top[] = [
                'character_id'   => $cid,
                'name'           => $names[$cid] ?? ('Character #' . $cid),
                'weighted_value' => $perContributor[$cid],
            ];
        }

        return [
            'available'        => true,
            'has_data'         => true,
            'completed_count'  => $count,
            'raw_value'        => $raw,
            'weighted_value'   => $weighted,
            'top_contributors' => $top,
        ];
    }

    /**
     * Every buyback-running corp HR has seen activity for (and, when BB is
     * installed, every configured programme even with no activity yet), with
     * its observed target model + current/default policy. Powers the settings
     * tab.
     */
    public function detectedProgrammes(): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $corpIds = BuybackActivity::query()->distinct()->pluck('corporation_id')
            ->map(fn ($c) => (int) $c)->all();

        // Include configured-but-idle programmes straight from BB's settings
        // table when present (read-only, guarded).
        if (Schema::hasTable('buyback_settings')) {
            $configured = \Illuminate\Support\Facades\DB::table('buyback_settings')
                ->pluck('corporation_id')->map(fn ($c) => (int) $c)->all();
            $corpIds = array_values(array_unique(array_merge($corpIds, $configured)));
        }

        $names = $this->corporationNameMap($corpIds);
        $out = [];
        foreach ($corpIds as $corpId) {
            if ($corpId <= 0) {
                continue;
            }
            $observed = $this->observedTarget($corpId);
            $policy   = $this->policyFor($corpId, $observed);

            $offers    = BuybackActivity::where('corporation_id', $corpId)->where('stage', BuybackActivity::STAGE_OFFER)->count();
            $completed = BuybackActivity::where('corporation_id', $corpId)->where('stage', BuybackActivity::STAGE_COMPLETED)->count();

            $attributedId = $policy['attributed_corporation_id'];

            $out[] = [
                'corporation_id'        => $corpId,
                'corporation_name'      => $names[$corpId] ?? ('Corp #' . $corpId),
                'observed_target_type'  => $observed['target_type'],
                'target_corporation_id' => $observed['target_corporation_id'],
                'offers_count'          => $offers,
                'completed_count'       => $completed,
                'policy'                => $policy,
                'attributed_name'       => $names[$attributedId] ?? (($map = $this->corporationNameMap([$attributedId]))[$attributedId] ?? ('Corp #' . $attributedId)),
            ];
        }

        usort($out, fn ($a, $b) => strcmp((string) $a['corporation_name'], (string) $b['corporation_name']));

        return $out;
    }

    // -----------------------------------------------------------------

    private function defaultPolicy(int $corporationId, ?array $observed = null): array
    {
        $observed = $observed ?? $this->observedTarget($corporationId);
        $tt = $observed['target_type'] ?? null;

        switch ($tt) {
            case self::TARGET_CORP:
                $tier = BuybackPolicy::TIER_DIRECT;
                $attributed = $observed['target_corporation_id'] ?: $corporationId;
                break;
            case self::TARGET_PLAYER:
                $tier = BuybackPolicy::TIER_PERSONAL;
                $attributed = $corporationId;
                break;
            case self::TARGET_MY_CORP:
            default:
                $tier = BuybackPolicy::TIER_COMMUNITY;
                $attributed = $corporationId;
                break;
        }

        return [
            'counted'                   => $tier !== BuybackPolicy::TIER_PERSONAL,
            'tier'                      => $tier,
            'weight'                    => BuybackPolicy::TIER_DEFAULT_WEIGHT[$tier],
            'attributed_corporation_id' => (int) $attributed,
            'is_default'                => true,
        ];
    }

    private function observedTarget(int $corporationId): array
    {
        $row = BuybackActivity::where('corporation_id', $corporationId)
            ->whereNotNull('target_type')
            ->orderByDesc('occurred_at')
            ->first();

        return [
            'target_type'           => $row->target_type ?? null,
            'target_corporation_id' => $row !== null ? ($row->target_corporation_id !== null ? (int) $row->target_corporation_id : null) : null,
            'target_character_id'   => $row !== null ? ($row->target_character_id !== null ? (int) $row->target_character_id : null) : null,
        ];
    }

    private function cachedPolicy(int $corporationId, array &$cache): array
    {
        if (!array_key_exists($corporationId, $cache)) {
            $cache[$corporationId] = $this->policyFor($corporationId);
        }
        return $cache[$corporationId];
    }

    /** @param array<int,float> $weightedByCorp */
    private function nameCorps(array $weightedByCorp): array
    {
        $names = $this->corporationNameMap(array_keys($weightedByCorp));
        $out = [];
        foreach ($weightedByCorp as $corpId => $value) {
            $out[] = [
                'corporation_id'   => (int) $corpId,
                'corporation_name' => $names[$corpId] ?? ('Corp #' . $corpId),
                'weighted_value'   => $value,
            ];
        }
        usort($out, fn ($a, $b) => $b['weighted_value'] <=> $a['weighted_value']);
        return $out;
    }

    private function corporationNameMap(array $corpIds): array
    {
        $corpIds = array_values(array_unique(array_filter(array_map('intval', $corpIds))));
        if (empty($corpIds)) {
            return [];
        }
        return CorporationInfo::whereIn('corporation_id', $corpIds)
            ->pluck('name', 'corporation_id')->toArray();
    }

    private function characterNames(array $charIds): array
    {
        $charIds = array_values(array_unique(array_filter(array_map('intval', $charIds))));
        if (empty($charIds)) {
            return [];
        }
        try {
            return app(NameResolutionService::class)->getCharacterNamesWithFallback($charIds);
        } catch (\Throwable $e) {
            return [];
        }
    }
}
