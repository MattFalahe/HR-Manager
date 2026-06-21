<?php

namespace HrManager\Services;

use HrManager\Models\MemberAssessment;
use Seat\Eveapi\Models\Character\CharacterAffiliation;

class AssessmentService
{
    private CrossPluginDataService $crossPlugin;
    private CharacterCheckService $characterCheck;

    public function __construct(CrossPluginDataService $crossPlugin, CharacterCheckService $characterCheck)
    {
        $this->crossPlugin = $crossPlugin;
        $this->characterCheck = $characterCheck;
    }

    /**
     * Get cached assessment or build a new one.
     */
    public function getOrBuild(int $characterId, ?int $corporationId, int $cacheMinutes = 60): ?MemberAssessment
    {
        $query = MemberAssessment::forCharacter($characterId);
        if ($corporationId !== null) {
            $query->where('corporation_id', $corporationId);
        }
        $assessment = $query->first();

        if ($assessment && $assessment->isFresh($cacheMinutes)) {
            return $assessment;
        }

        return $this->buildAssessment($characterId, $corporationId);
    }

    /**
     * Force refresh an assessment.
     */
    public function refreshAssessment(int $characterId): ?MemberAssessment
    {
        $affiliation = CharacterAffiliation::find($characterId);
        $corporationId = $affiliation->corporation_id ?? null;

        return $this->buildAssessment($characterId, $corporationId);
    }

    /**
     * Build assessment data from all sources.
     */
    public function buildAssessment(int $characterId, ?int $corporationId): ?MemberAssessment
    {
        if (!$corporationId) {
            $affiliation = CharacterAffiliation::find($characterId);
            $corporationId = $affiliation->corporation_id ?? null;
        }

        $months = config('hr-manager.assessment.mining_months', 6);

        // Gather mining data
        $mining = $this->crossPlugin->getMiningHistory($characterId, $months);
        $taxes = $this->crossPlugin->getTaxHistory($characterId, $months);
        $oreBreakdown = $this->crossPlugin->getOreBreakdown($characterId, $months);

        // Gather ratting data
        $ratting = $corporationId
            ? $this->crossPlugin->getRattingIncome($characterId, $corporationId, $months)
            : ['available' => false];

        // CWM wallet aggregates folded into the cache row so the player
        // detail view + assessment exports don't have to re-fetch on every
        // read. All three calls return ['available' => bool, 'data' => ...];
        // when CWM/MC are absent every call reports available=false and the
        // assessment row's wallet columns stay null.
        $walletLifetime = $corporationId
            ? $this->crossPlugin->getCharacterLifetimeSummary($characterId, $corporationId)
            : ['available' => false];
        $walletNet = $corporationId
            ? $this->crossPlugin->getCharacterNetPosition($characterId, $corporationId, $months)
            : ['available' => false];
        $walletTax = $corporationId
            ? $this->crossPlugin->getCharacterTaxCompliance($characterId, $corporationId, $months)
            : ['available' => false];

        // Character check data
        $characterSummary = $this->characterCheck->getCharacterSummary($characterId);
        $employmentHistory = $characterSummary['employment_history'];

        // Active months across ALL activity signals, not just mining. The
        // previous mining-only count reported 0 for non-miners who were
        // clearly active (ratters / industrialists with wallet contributions).
        // Prefer the corp-wallet "months_active" — the broadest signal, the
        // count of months the character actually contributed (covering
        // ratting tax, mining tax and donations) — and fall back to the
        // mining-active month count when CWM/wallet data is absent.
        $activeMonths = 0;
        if ($mining['available'] && isset($mining['monthly_summaries'])) {
            $activeMonths = $mining['monthly_summaries']->count();
        }
        $walletMonthsActive = $this->fieldFromBridge($walletLifetime, 'months_active');
        if ($walletMonthsActive !== null) {
            $activeMonths = max($activeMonths, (int) $walletMonthsActive);
        }
        // Floor: a member with ratting income or any lifetime contribution is
        // demonstrably active, so they should never read 0 active months (e.g.
        // a ratter in a corp that doesn't tax ratting into the wallet).
        if ($activeMonths === 0
            && ((($ratting['available'] ?? false) && (float) ($ratting['total_income'] ?? 0) > 0)
                || (float) ($this->fieldFromBridge($walletLifetime, 'lifetime_total_contributed') ?? 0) > 0)) {
            $activeMonths = 1;
        }

        // Determine member_since from employment history
        $memberSince = null;
        if ($employmentHistory->isNotEmpty() && $corporationId) {
            $currentEntry = $employmentHistory->first(function ($entry) use ($corporationId) {
                return ($entry->corporation_id ?? null) === $corporationId;
            });
            $memberSince = $currentEntry->start_date ?? null;
        }

        $data = [
            'character_id'       => $characterId,
            'corporation_id'     => $corporationId,
            'total_mining_value' => $mining['available'] ? ($mining['total_value'] ?? 0) : 0,
            'total_mining_tax'   => $taxes['available'] ? ($taxes['total_paid'] ?? 0) : 0,
            'tax_compliance_pct' => $taxes['available'] ? ($taxes['compliance_pct'] ?? 0) : 0,
            'total_ratting_income' => $ratting['available'] ? ($ratting['total_income'] ?? 0) : 0,
            'ore_preferences'    => $oreBreakdown['available'] ? ($oreBreakdown['breakdown'] ?? null) : null,
            'active_months'      => $activeMonths,
            'last_mining_date'   => $mining['available'] && isset($mining['monthly_summaries']) && $mining['monthly_summaries']->isNotEmpty()
                ? $mining['monthly_summaries']->first()->month ?? null
                : null,
            'last_ratting_date'  => $ratting['available'] ? ($ratting['last_activity'] ?? null) : null,
            'security_status'    => $characterSummary['security_status'],
            'total_sp'           => $characterSummary['skill_points'],
            'employment_count'   => $employmentHistory->count(),
            'member_since'       => $memberSince,
            // CWM wallet aggregates — null when MC/CWM absent or call failed
            'lifetime_contribution'     => $this->fieldFromBridge($walletLifetime, 'lifetime_total_contributed'),
            'net_position_6mo'          => $this->fieldFromBridge($walletNet, 'net_amount'),
            'wallet_compliance_pct_6mo' => $this->fieldFromBridge($walletTax, 'compliance_pct'),
            'last_contribution_at'      => $this->fieldFromBridge($walletLifetime, 'last_contribution_period'),
            'cached_at'          => now(),
        ];

        // Schema-safety: drop any keys for columns that don't exist on disk.
        // Installs that ran the v1.0.0 consolidated migration before commit
        // 40ce808 lack the four CWM-aggregate columns until the
        // 2026_06_01_000002 forward-only migration runs. Filtering here
        // means updateOrCreate succeeds even in that window.
        $data = $this->dropMissingColumns(MemberAssessment::query()->getModel()->getTable(), $data);

        return MemberAssessment::updateOrCreate(
            ['character_id' => $characterId, 'corporation_id' => $corporationId],
            $data
        );
    }

    /**
     * Return $data with any keys removed whose columns do not exist on the
     * given table. Used as a safety net for pre-released-migration installs
     * that may be missing columns added in a later commit.
     */
    private function dropMissingColumns(string $table, array $data): array
    {
        $existing = \Illuminate\Support\Facades\Schema::getColumnListing($table);
        return array_intersect_key($data, array_flip($existing));
    }

    /**
     * Pull a field from a CrossPluginDataService bridge response. Returns null
     * when the bridge call wasn't available OR the underlying data block is
     * null (e.g. MM tax compliance when MM itself isn't installed). The
     * 'data' shape may be an object or associative array depending on
     * PluginBridge transport — handle both.
     */
    private function fieldFromBridge(array $envelope, string $key)
    {
        if (!($envelope['available'] ?? false)) {
            return null;
        }
        $data = $envelope['data'] ?? null;
        if ($data === null) {
            return null;
        }
        if (is_array($data)) {
            return $data[$key] ?? null;
        }
        if (is_object($data)) {
            return $data->{$key} ?? null;
        }
        return null;
    }
}
