<?php

namespace HrManager\Services;

use HrManager\Models\MemberAssessment;
use Illuminate\Support\Facades\Log;

/**
 * Aggregates CWM + MM data into a director-tier "wallet audit" view
 * for the member profile. Surfaces fraud-relevant signals:
 *
 *   Income profile
 *     - Total contributed (lifetime + last 90 days)
 *     - Breakdown by category (mining tax / donations / etc.)
 *     - Total ratting income (when CWM ratting capability registered)
 *     - Total mining value (when MM is installed)
 *
 *   Expense profile
 *     - Total withdrawn (lifetime + last 90 days)
 *     - Net position (contributed - withdrawn)
 *     - Recent large withdrawals (latest 5 entries with sign filter)
 *
 *   Audit signals (the actual fraud detection)
 *     - Tax compliance %
 *     - Income-to-tax ratio (low ratio = potential tax fraud)
 *     - Net negative flag (taking more than giving)
 *     - Suspicious wallet flags (CWM signals: stalled, negative,
 *       compliance dropped, drop detected, silent wallet director)
 *     - Watchlist match (the person on our own list)
 *     - Unusual recipient detection count (from CWM events HR
 *       already subscribes to)
 *
 * Pure consumer of existing CWM/MM bridge calls. Adds no new ESI
 * traffic. Returns ['available' => false] when neither CWM nor MM
 * is installed so the partial can render a graceful empty state.
 */
class WalletAuditService
{
    public function __construct(
        private CrossPluginDataService $cross
    ) {}

    /**
     * Build the audit snapshot for one character in one corp.
     *
     * @return array Structured snapshot with income / expense /
     *               signals subsections. See class docblock for the
     *               shape contract.
     */
    public function snapshot(int $characterId, int $corporationId): array
    {
        try {
            $lifetime    = $this->cross->getCharacterLifetimeSummary($characterId, $corporationId);
            $netPosition = $this->cross->getCharacterNetPosition($characterId, $corporationId, 6);
            $tax         = $this->cross->getCharacterTaxCompliance($characterId, $corporationId, 6);
            $breakdown   = $this->cross->getCharacterCategoryBreakdown($characterId, $corporationId, 6);
            $entries     = $this->cross->getCharacterRecentEntries($characterId, $corporationId, 10);
            $ratting     = $this->cross->getRattingIncome($characterId, $corporationId, 6);
            $rattingBd   = $this->cross->getRattingBreakdown($characterId, $corporationId, 6);
            $rattingMon  = $this->cross->getRattingMonthly($characterId, $corporationId, 6);
            $miningHist  = $this->cross->getMiningHistory($characterId, 6);
            $taxHist     = $this->cross->getTaxHistory($characterId, 6);

            // Pull the assessment row for HR's cached signals (wallet
            // flags from the classifier, lifetime totals snapshotted
            // by AssessmentService).
            $assessment = MemberAssessment::forCharacter($characterId)
                ->where('corporation_id', $corporationId)
                ->first();

            $anyAvailable = ($lifetime['available'] ?? false)
                || ($netPosition['available'] ?? false)
                || ($ratting['available'] ?? false)
                || ($miningHist['available'] ?? false);

            if (!$anyAvailable) {
                return [
                    'available' => false,
                    'reason'    => 'no_data_sources',
                ];
            }

            $income = $this->buildIncomeProfile($lifetime, $breakdown, $ratting, $miningHist);
            $expense = $this->buildExpenseProfile($lifetime, $netPosition, $entries);
            $signals = $this->buildAuditSignals(
                $income,
                $expense,
                $tax,
                $taxHist,
                $assessment
            );

            return [
                'available'       => true,
                'income_profile'  => $income,
                'expense_profile' => $expense,
                'audit_signals'   => $signals,
                'ratting_detail'  => $this->buildRattingDetail($rattingBd, $rattingMon),
            ];
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] WalletAuditService failed: ' . $e->getMessage());
            return [
                'available' => false,
                'reason'    => 'exception',
            ];
        }
    }

    // -----------------------------------------------------------------

    private function buildIncomeProfile(array $lifetime, array $breakdown, array $ratting, array $miningHist): array
    {
        $lifetimeData = $lifetime['data'] ?? null;
        $rattingData  = $ratting['data'] ?? null;

        $contributedLifetime = $this->fieldValue($lifetimeData, 'lifetime_total_contributed');
        $rattingTotal        = (float) ($ratting['total_income'] ?? 0);
        $miningTotal         = (float) ($miningHist['total_value'] ?? 0);

        $breakdownRows = $this->normalizeCategoryRows($breakdown['data'] ?? null);

        $combinedTotal = (float) ($contributedLifetime ?? 0) + $rattingTotal + $miningTotal;

        return [
            'lifetime_contributed' => $contributedLifetime,
            'ratting_income_6mo'   => $rattingTotal,
            'mining_value_6mo'     => $miningTotal,
            'combined_income'      => $combinedTotal,
            'categories'           => $breakdownRows,
            'ratting_available'    => (bool) ($ratting['available'] ?? false),
            'mining_available'     => (bool) ($miningHist['available'] ?? false),
        ];
    }

    private function buildExpenseProfile(array $lifetime, array $netPosition, array $entries): array
    {
        $lifetimeData = $lifetime['data'] ?? null;
        $netData      = $netPosition['data'] ?? null;

        $withdrawnLifetime = $this->fieldValue($lifetimeData, 'lifetime_total_withdrawn');
        $netAmount         = $this->fieldValue($netData, 'net_amount');
        $isNetPositive     = isset($netData?->is_net_positive)
            ? (bool) $netData->is_net_positive
            : (is_array($netData) ? (bool) ($netData['is_net_positive'] ?? false) : null);

        // Latest entries with negative amounts (withdrawals).
        $entriesRows = $this->normalizeEntryRows($entries['data'] ?? null);
        $withdrawalEntries = array_values(array_filter(
            $entriesRows,
            fn($r) => ($r['amount'] ?? 0) < 0
        ));

        return [
            'lifetime_withdrawn'  => $withdrawnLifetime,
            'net_position_6mo'    => $netAmount,
            'is_net_positive'     => $isNetPositive,
            'recent_withdrawals'  => array_slice($withdrawalEntries, 0, 5),
        ];
    }

    private function buildAuditSignals(array $income, array $expense, array $tax, array $taxHist, ?MemberAssessment $assessment): array
    {
        $taxData = $tax['data'] ?? null;
        $compliancePct = $this->fieldValue($taxData, 'compliance_pct');
        if ($compliancePct === null && !empty($taxHist['compliance_pct'])) {
            $compliancePct = $taxHist['compliance_pct'];
        }
        $totalOwed = $this->fieldValue($taxData, 'total_owed') ?? (float) ($taxHist['total_owed'] ?? 0);
        $totalPaid = $this->fieldValue($taxData, 'total_paid') ?? (float) ($taxHist['total_paid'] ?? 0);

        $combinedIncome = (float) ($income['combined_income'] ?? 0);
        $taxRatio = ($combinedIncome > 0 && $totalPaid !== null)
            ? round(($totalPaid / $combinedIncome) * 100, 2)
            : null;

        // Severity from compliance % — same buckets the classifier uses.
        $complianceSeverity = $compliancePct === null
            ? 'unknown'
            : ($compliancePct >= 80 ? 'good'
                : ($compliancePct >= 50 ? 'warning'
                    : 'critical'));

        // Wallet flags persisted on the assessment row by the
        // classifier round-3 work. Surface them inline.
        $walletFlags = [];
        if ($assessment !== null) {
            $cached = $assessment->wallet_flags ?? null;
            if (is_string($cached)) {
                $decoded = json_decode($cached, true);
                if (is_array($decoded)) $cached = $decoded;
            }
            if (is_array($cached)) {
                $walletFlags = array_values(array_filter($cached, 'is_string'));
            }
        }

        return [
            'compliance_pct'      => $compliancePct,
            'compliance_severity' => $complianceSeverity,
            'tax_owed'            => $totalOwed,
            'tax_paid'            => $totalPaid,
            'income_to_tax_ratio' => $taxRatio,
            'is_net_negative'     => isset($expense['is_net_positive']) ? !$expense['is_net_positive'] : null,
            'wallet_flags'        => $walletFlags,
        ];
    }

    // -----------------------------------------------------------------

    /**
     * Build the ratting-detail subsection: income SOURCE breakdown
     * (bounties vs mission rewards — NOT site type, CCP doesn't expose
     * that) + a monthly income series for the sparkline.
     *
     * The breakdown payload is rows of {ref_type, total, count}. We
     * fold the raw EVE ref_types into two human buckets:
     *   - Bounties  (bounty_prizes / bounty_prize)
     *   - Missions  (agent_mission_reward / *_time_bonus_reward)
     * so the operator sees "70% bounties, 30% missions" not raw
     * snake_case ref_type strings.
     */
    private function buildRattingDetail(array $breakdown, array $monthly): array
    {
        $available = (bool) ($breakdown['available'] ?? false) || (bool) ($monthly['available'] ?? false);
        if (!$available) {
            return ['available' => false];
        }

        // --- Source breakdown ---
        $rawRows = $breakdown['data'] ?? $breakdown['breakdown'] ?? [];
        if (is_object($rawRows)) $rawRows = (array) $rawRows;
        $buckets = ['bounties' => 0.0, 'missions' => 0.0];
        if (is_array($rawRows)) {
            foreach ($rawRows as $row) {
                $r = is_array($row) || is_object($row) ? (array) $row : [];
                $refType = (string) ($r['ref_type'] ?? '');
                $total   = (float) ($r['total'] ?? 0);
                if ($total <= 0) continue;
                if (str_starts_with($refType, 'bounty')) {
                    $buckets['bounties'] += $total;
                } elseif (str_starts_with($refType, 'agent_mission')) {
                    $buckets['missions'] += $total;
                }
            }
        }
        $sourceTotal = $buckets['bounties'] + $buckets['missions'];
        $sources = [];
        if ($sourceTotal > 0) {
            foreach (['bounties' => 'Bounties', 'missions' => 'Mission rewards'] as $key => $label) {
                if ($buckets[$key] > 0) {
                    $sources[] = [
                        'key'    => $key,
                        'label'  => $label,
                        'amount' => $buckets[$key],
                        'pct'    => ($buckets[$key] / $sourceTotal) * 100,
                    ];
                }
            }
            usort($sources, fn($a, $b) => $b['amount'] <=> $a['amount']);
        }

        // --- Monthly series (oldest → newest for sparkline) ---
        $rawMonths = $monthly['data'] ?? $monthly['months'] ?? [];
        if (is_object($rawMonths)) $rawMonths = (array) $rawMonths;
        $series = [];
        if (is_array($rawMonths)) {
            foreach ($rawMonths as $m) {
                $r = is_array($m) || is_object($m) ? (array) $m : [];
                $month = $r['month'] ?? null;
                $val   = (float) ($r['total_income'] ?? $r['total'] ?? 0);
                if ($month !== null) {
                    $series[] = ['month' => (string) $month, 'amount' => $val];
                }
            }
            // CWM returns DESC; flip to ASC for left-to-right sparkline.
            usort($series, fn($a, $b) => strcmp($a['month'], $b['month']));
        }

        return [
            'available'    => true,
            'sources'      => $sources,
            'source_total' => $sourceTotal,
            'series'       => $series,
        ];
    }

    private function fieldValue($data, string $key): mixed
    {
        if ($data === null) return null;
        if (is_array($data)) return $data[$key] ?? null;
        if (is_object($data)) return $data->{$key} ?? null;
        return null;
    }

    /**
     * @param mixed $data
     * @return array<int, array{name:string, amount:float, pct:?float}>
     */
    private function normalizeCategoryRows($data): array
    {
        // Try every key CWM versions have used for the breakdown
        // payload. NEVER fall back to $data itself — when CWM returns
        // a wrapper with no categories array, $data's top-level fields
        // (character_id, corporation_id, months, mm_available) would
        // get iterated AS IF they were income categories. That's the
        // bug that rendered "character_id: 2.12 B" (the character ID
        // formatted as ISK) on member profiles with zero wallet
        // activity.
        $categories = $this->fieldValue($data, 'categories')
            ?? $this->fieldValue($data, 'breakdown')
            ?? $this->fieldValue($data, 'rows')
            ?? $this->fieldValue($data, 'top_categories');
        if ($categories === null) return [];
        if (is_object($categories)) $categories = (array) $categories;
        if (!is_array($categories)) return [];

        // Wrapper field names CWM bridge responses tend to include at
        // the top level. If any of these slip through alongside real
        // categories, skip them — they're metadata, not income.
        $wrapperFields = [
            'character_id', 'corporation_id', 'months', 'days',
            'available', 'mm_available', 'cwm_available',
            'limit', 'offset', 'total_pages', 'page',
        ];

        $rows = [];
        foreach ($categories as $key => $row) {
            if (is_string($key) && in_array($key, $wrapperFields, true)) {
                continue;
            }
            if (is_array($row) || is_object($row)) {
                $r = (array) $row;
                // Only count if the row genuinely looks like a
                // category entry. Without an amount-like field it's
                // probably metadata pretending to be a row.
                $hasAmount = isset($r['amount']) || isset($r['total']) || isset($r['value']);
                if (!$hasAmount) continue;
                $name = $r['category'] ?? $r['name'] ?? $r['label'] ?? (is_string($key) ? $key : '?');
                $amount = (float) ($r['amount'] ?? $r['total'] ?? $r['value'] ?? 0);
                $pct = isset($r['pct']) ? (float) $r['pct'] : null;
            } elseif (is_numeric($row) && is_string($key)) {
                // Flat map shape: { "Donations": 1500000, "Mining tax": ... }
                $name = $key;
                $amount = (float) $row;
                $pct = null;
            } else {
                continue;
            }
            if ($amount > 0) {
                $rows[] = ['name' => (string) $name, 'amount' => $amount, 'pct' => $pct];
            }
        }
        usort($rows, fn($a, $b) => $b['amount'] <=> $a['amount']);

        // Backfill share-of-shown pct when CWM doesn't include one.
        $totalShown = array_sum(array_column($rows, 'amount'));
        foreach ($rows as &$r) {
            if ($r['pct'] === null && $totalShown > 0) {
                $r['pct'] = ($r['amount'] / $totalShown) * 100;
            }
        }
        unset($r);
        return $rows;
    }

    /**
     * @param mixed $data
     * @return array<int, array{period:?string, amount:?float, category:?string, note:?string}>
     */
    private function normalizeEntryRows($data): array
    {
        $entries = $this->fieldValue($data, 'entries') ?? $data;
        if (is_object($entries)) $entries = (array) $entries;
        if (!is_array($entries)) return [];

        $out = [];
        foreach ($entries as $row) {
            $r = is_array($row) || is_object($row) ? (array) $row : [];
            $out[] = [
                'period'   => $r['period'] ?? $r['date'] ?? null,
                'amount'   => isset($r['amount']) ? (float) $r['amount'] : null,
                'category' => $r['category'] ?? $r['type'] ?? null,
                'note'     => $r['note'] ?? $r['memo'] ?? null,
            ];
        }
        return $out;
    }
}
