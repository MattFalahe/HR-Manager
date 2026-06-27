<?php

namespace HrManager\Services;

use HrManager\Models\BuybackActivity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * MC EventBus subscriber for Buyback Manager activity.
 *
 * Wired in HrManagerServiceProvider to two persistent subscriptions:
 *   - buyback.offer.published    -> recordOffer()     (stage 'offer')
 *   - buyback.contract.completed -> recordCompletion() (stage 'completed')
 *
 * Forward-only accumulation into hr_manager_buyback_activity. The contributor
 * is the issuer (the member who published the offer / created the contract).
 * Tier + corp attribution are NOT decided here — they are resolved on read
 * from the per-corp policy (BuybackContributionService), so a policy change
 * re-values history without a re-import.
 *
 * Every handler is idempotent (offers dedup on offer_public_id, completions on
 * contract_id) and catches + logs without throwing, mirroring the other HR
 * EventBus handlers — a subscriber failure must never poison the dispatcher.
 *
 * All cross-plugin data arrives via the payload array only; this service
 * imports no Buyback Manager classes.
 */
class BuybackEventHandler
{
    /**
     * buyback.offer.published — a member requested a quote / published an
     * offer. Engagement signal; also carries BB's richer target model
     * (my_corp / corp / player + target_corporation_id) which informs the
     * per-corp policy and is inherited by the matching completion.
     */
    public function recordOffer(array $payload): ?array
    {
        $characterId   = $this->intOrNull($payload['issuer_character_id'] ?? null);
        $corporationId = $this->intOrNull($payload['corporation_id'] ?? null);
        $publicId      = $this->stringOrNull($payload['offer_public_id'] ?? null);

        if ($characterId === null || $corporationId === null || $publicId === null) {
            Log::warning('[HR Manager] BuybackEventHandler::recordOffer rejected malformed payload', [
                'has_issuer' => isset($payload['issuer_character_id']),
                'has_corp'   => isset($payload['corporation_id']),
                'has_offer'  => isset($payload['offer_public_id']),
            ]);
            return null;
        }

        try {
            if (!Schema::hasTable('hr_manager_buyback_activity')) {
                return null;
            }

            BuybackActivity::updateOrCreate(
                ['stage' => BuybackActivity::STAGE_OFFER, 'offer_public_id' => $publicId],
                [
                    'character_id'          => $characterId,
                    'corporation_id'        => $corporationId,
                    'target_type'           => $this->stringOrNull($payload['target_type'] ?? null),
                    'target_corporation_id' => $this->intOrNull($payload['target_corporation_id'] ?? null),
                    'target_character_id'   => $this->intOrNull($payload['target_character_id'] ?? null),
                    'mode'                  => $this->stringOrNull($payload['mode'] ?? null),
                    'total_value'           => (float) ($payload['total_buyback_value'] ?? 0),
                    'items_count'           => 0,
                    'occurred_at'           => $this->parseDate($payload['published_at'] ?? null) ?? now(),
                ]
            );

            return ['recorded' => true];
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] BuybackEventHandler::recordOffer failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * buyback.contract.completed — the contract finished; realized ISK
     * contribution. The completion envelope omits BB's target model, so we
     * inherit it from the matching offer row when one was recorded.
     */
    public function recordCompletion(array $payload): ?array
    {
        $characterId   = $this->intOrNull($payload['issuer_id'] ?? null);
        $corporationId = $this->intOrNull($payload['corporation_id'] ?? null);
        $contractId    = $this->intOrNull($payload['contract_id'] ?? null);

        if ($characterId === null || $corporationId === null || $contractId === null) {
            Log::warning('[HR Manager] BuybackEventHandler::recordCompletion rejected malformed payload', [
                'has_issuer'   => isset($payload['issuer_id']),
                'has_corp'     => isset($payload['corporation_id']),
                'has_contract' => isset($payload['contract_id']),
            ]);
            return null;
        }

        try {
            if (!Schema::hasTable('hr_manager_buyback_activity')) {
                return null;
            }

            $publicId = $this->stringOrNull($payload['offer_public_id'] ?? null);

            // The completion event omits the richer target model BB attaches at
            // offer time, so inherit it from the matching offer row.
            $offerRow = $publicId !== null
                ? BuybackActivity::where('stage', BuybackActivity::STAGE_OFFER)
                    ->where('offer_public_id', $publicId)->first()
                : null;

            BuybackActivity::updateOrCreate(
                ['contract_id' => $contractId],
                [
                    'stage'                 => BuybackActivity::STAGE_COMPLETED,
                    'character_id'          => $characterId,
                    'corporation_id'        => $corporationId,
                    'target_type'           => $offerRow->target_type ?? $this->stringOrNull($payload['target_type'] ?? null),
                    'target_corporation_id' => $offerRow->target_corporation_id ?? $this->intOrNull($payload['target_corporation_id'] ?? null),
                    'target_character_id'   => $offerRow->target_character_id ?? $this->intOrNull($payload['target_character_id'] ?? null),
                    'mode'                  => $this->stringOrNull($payload['mode'] ?? null),
                    'offer_public_id'       => $publicId,
                    'total_value'           => (float) ($payload['total_value'] ?? 0),
                    'items_count'           => (int) ($payload['items_count'] ?? 0),
                    'occurred_at'           => $this->parseDate($payload['completed_date'] ?? null) ?? now(),
                ]
            );

            return ['recorded' => true];
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] BuybackEventHandler::recordCompletion failed: ' . $e->getMessage());
            return null;
        }
    }

    private function intOrNull($value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $int = (int) $value;
        return $int > 0 ? $int : null;
    }

    private function stringOrNull($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $str = trim((string) $value);
        return $str === '' ? null : $str;
    }

    private function parseDate($value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
