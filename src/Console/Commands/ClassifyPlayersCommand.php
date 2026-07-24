<?php

namespace HrManager\Console\Commands;

use HrManager\Services\ClassifierService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Nightly cron: classify every tracked player in every tracked corp. Writes
 * to hr_manager_player_classifications. Detects transitions vs prior state
 * and publishes hr.player.flagged_* events to MC's EventBus.
 */
class ClassifyPlayersCommand extends Command
{
    protected $signature = 'hr-manager:classify-players
                            {--corporation_id= : Only classify this corporation}
                            {--quiet-notify : Persist classifications without firing transition / flag notifications (baseline pass)}';

    protected $description = 'Compute player activity classifications + detect transitions';

    public function handle(ClassifierService $classifier): int
    {
        if ($this->option('quiet-notify')) {
            $classifier->setQuiet();
            $this->info('HR Manager: classifying players... (baseline pass — notifications suppressed)');
        } else {
            $this->info('HR Manager: classifying players...');
        }

        $corpIds = $this->option('corporation_id')
            ? [(int) $this->option('corporation_id')]
            : DB::table('refresh_tokens')
                ->join('character_affiliations', 'refresh_tokens.character_id', '=', 'character_affiliations.character_id')
                ->whereNull('refresh_tokens.deleted_at')
                ->distinct()
                ->pluck('character_affiliations.corporation_id')
                ->filter()
                ->all();

        if (empty($corpIds)) {
            $this->warn('No corporations to classify.');
            return 0;
        }

        $corpIds = array_values($corpIds);
        $totals = [
            'active' => 0, 'at_risk' => 0, 'inactive' => 0, 'dead_weight' => 0, 'inactive_directors' => 0,
        ];

        // Progress bar over the corps: a single advancing line with the current
        // corp, the running category totals, elapsed time and an ETA — legible
        // for the 100+ corps your tracked characters span (replaces the wall of
        // per-corp lines that scrolled off-screen).
        $bar = $this->output->createProgressBar(count($corpIds));
        $bar->setFormat(" %current%/%max% corps  [%bar%]  %percent:3s%%  %elapsed:6s%/%estimated:-6s%  %message%");
        $bar->setMessage('starting…');
        $bar->start();

        foreach ($corpIds as $corpId) {
            $counts = $classifier->classifyCorporation((int) $corpId);
            foreach ($counts as $k => $v) {
                $totals[$k] = ($totals[$k] ?? 0) + $v;
            }
            $bar->setMessage(sprintf(
                'corp %d  ·  running A:%d R:%d I:%d D:%d dir:%d',
                $corpId, $totals['active'], $totals['at_risk'], $totals['inactive'],
                $totals['dead_weight'], $totals['inactive_directors']
            ));
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        $this->info(sprintf('Done. Totals: active=%d at_risk=%d inactive=%d dead_weight=%d inactive_directors=%d',
            $totals['active'], $totals['at_risk'], $totals['inactive'],
            $totals['dead_weight'], $totals['inactive_directors']));

        return 0;
    }
}
