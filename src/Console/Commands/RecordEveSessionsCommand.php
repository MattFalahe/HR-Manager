<?php

namespace HrManager\Console\Commands;

use HrManager\Services\EveSessionService;
use Illuminate\Console\Command;

/**
 * Copies EVE play sessions out of SeAT's character_onlines. Reads SeAT's
 * table only; no ESI calls. Cheap when nothing changed, which is most runs.
 */
class RecordEveSessionsCommand extends Command
{
    protected $signature = 'hr-manager:record-eve-sessions';
    protected $description = 'Record EVE play sessions from the online status SeAT already keeps.';

    public function handle(EveSessionService $sessions): int
    {
        if (!$sessions->isEnabled()) {
            $this->info('[HR Manager] EVE session tracking is switched off.');

            return self::SUCCESS;
        }

        $stats = $sessions->record();

        if (!$stats['available']) {
            $this->warn('[HR Manager] character_onlines or the HR sessions table is missing; nothing recorded.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '[HR Manager] %d characters read: %d sessions opened, %d closed, %d still online.',
            $stats['characters'],
            $stats['opened'],
            $stats['closed'],
            $stats['touched']
        ));

        return self::SUCCESS;
    }
}
