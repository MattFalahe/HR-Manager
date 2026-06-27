<?php

namespace HrManager\Console\Commands;

use HrManager\Models\BuybackActivity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-time seed of historical Buyback Manager activity into HR's buyback
 * activity table. Live data flows via the EventBus going forward; this command
 * backfills offers + completed contracts that predate the subscription so the
 * profile / Corp Health panels have history immediately.
 *
 * Read-only on Buyback Manager's tables, guarded with hasTable / hasColumn so
 * it degrades cleanly across BB versions and when BB is absent. Idempotent:
 * offers dedup on offer_public_id, completions on contract_id, so re-running is
 * safe.
 */
class BackfillBuybackCommand extends Command
{
    protected $signature = 'hr-manager:backfill-buyback {--dry-run : Count what would be seeded without writing}';

    protected $description = 'Seed historical Buyback Manager offers + completed contracts into HR buyback activity';

    /** ESI contract states Buyback Manager treats as completed. */
    private const COMPLETED_STATES = ['finished', 'finished_issuer', 'finished_contractor'];

    public function handle(): int
    {
        if (!Schema::hasTable('hr_manager_buyback_activity')) {
            $this->warn('hr_manager_buyback_activity missing — migration pending. Skipping.');
            return 0;
        }

        $dry = (bool) $this->option('dry-run');

        $offers     = $this->backfillOffers($dry);
        $completed  = $this->backfillCompletions($dry);

        $verb = $dry ? 'would seed' : 'seeded';
        $this->info("Done. {$verb} {$offers} offer(s) + {$completed} completed contract(s).");

        return 0;
    }

    private function backfillOffers(bool $dry): int
    {
        if (!Schema::hasTable('buyback_offers')) {
            $this->warn('buyback_offers absent — skipping offer backfill.');
            return 0;
        }

        $hasTargetType = Schema::hasColumn('buyback_offers', 'target_type');
        $hasTargetCorp = Schema::hasColumn('buyback_offers', 'target_corporation_id');

        $count = 0;
        DB::table('buyback_offers')->orderBy('id')->chunk(500, function ($rows) use (&$count, $dry, $hasTargetType, $hasTargetCorp) {
            foreach ($rows as $o) {
                if ($dry) {
                    $count++;
                    continue;
                }

                BuybackActivity::updateOrCreate(
                    ['stage' => BuybackActivity::STAGE_OFFER, 'offer_public_id' => $o->public_id],
                    [
                        'character_id'          => (int) $o->issuer_character_id,
                        'corporation_id'        => (int) $o->corporation_id,
                        'target_type'           => $hasTargetType ? ($o->target_type ?? null) : null,
                        'target_corporation_id' => $hasTargetCorp ? ($o->target_corporation_id ?? null) : null,
                        'target_character_id'   => $o->target_character_id ?? null,
                        'mode'                  => $o->mode ?? null,
                        'total_value'           => (float) ($o->total_buyback_value ?? 0),
                        'items_count'           => 0,
                        'occurred_at'           => $o->created_at ?? now(),
                    ]
                );
                $count++;
            }
        });

        return $count;
    }

    private function backfillCompletions(bool $dry): int
    {
        if (!Schema::hasTable('buyback_contracts')) {
            $this->warn('buyback_contracts absent — skipping completion backfill.');
            return 0;
        }

        $hasOfferPid = Schema::hasColumn('buyback_contracts', 'offer_public_id');

        $count = 0;
        DB::table('buyback_contracts')
            ->whereIn('status', self::COMPLETED_STATES)
            ->orderBy('id')
            ->chunk(500, function ($rows) use (&$count, $dry, $hasOfferPid) {
                foreach ($rows as $c) {
                    if ($dry) {
                        $count++;
                        continue;
                    }

                    $publicId = $hasOfferPid ? ($c->offer_public_id ?? null) : null;
                    $offerRow = $publicId
                        ? BuybackActivity::where('stage', BuybackActivity::STAGE_OFFER)
                            ->where('offer_public_id', $publicId)->first()
                        : null;

                    BuybackActivity::updateOrCreate(
                        ['contract_id' => (int) $c->contract_id],
                        [
                            'stage'                 => BuybackActivity::STAGE_COMPLETED,
                            'character_id'          => (int) $c->issuer_id,
                            'corporation_id'        => (int) $c->corporation_id,
                            'target_type'           => $offerRow->target_type ?? null,
                            'target_corporation_id' => $offerRow->target_corporation_id ?? null,
                            'target_character_id'   => $offerRow->target_character_id ?? null,
                            'mode'                  => null,
                            'offer_public_id'       => $publicId,
                            'total_value'           => (float) ($c->total_value ?? 0),
                            'items_count'           => (int) ($c->items_count ?? 0),
                            'occurred_at'           => $c->completed_date ?? now(),
                        ]
                    );
                    $count++;
                }
            });

        return $count;
    }
}
