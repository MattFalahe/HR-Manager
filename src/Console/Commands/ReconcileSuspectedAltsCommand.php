<?php

namespace HrManager\Console\Commands;

use HrManager\Services\SuspectedAltService;
use Illuminate\Console\Command;

/**
 * Settles open "probably an alt of X" claims against SeAT account data.
 *
 * Claims are made about characters HR often can't see yet, so most passes find
 * nothing to decide — that's expected and cheap. The pass that matters is the
 * one after a suspect finally registers: the link either confirms, or it turns
 * out the two characters are different people and the claim is refuted.
 */
class ReconcileSuspectedAltsCommand extends Command
{
    protected $signature = 'hr-manager:reconcile-suspected-alts';

    protected $description = 'Confirm or refute suspected alt links once their characters appear in SeAT';

    public function handle(SuspectedAltService $service): int
    {
        $r = $service->reconcile();

        // Zero counts are ambiguous on their own — say WHY there was nothing to
        // do, so a pending migration can't be mistaken for a healthy quiet run.
        if (!$r['available']) {
            $this->warn('Suspected-alt links table is missing — migration pending. Restart SeAT to apply it, then re-run.');
            return 0;
        }

        if ($r['total'] === 0) {
            $this->info('No suspected-alt claims on file yet. Claims are created when you list "possible alts" on a blacklist / whitelist / intel entry; existing entries added before that option existed have none, so there is nothing to reconcile.');
            return 0;
        }

        $this->info(sprintf(
            'Re-checked %d claim(s): %d confirmed, %d refuted, %d still waiting on SeAT data.',
            $r['checked'],
            $r['confirmed'],
            $r['refuted'],
            $r['pending']
        ));

        if ($r['refuted'] > 0) {
            $this->warn($r['refuted'] . ' claim(s) stand REFUTED — those characters are on different accounts. Review the entries they annotate.');
        }

        if ($r['reversed'] > 0) {
            $this->warn($r['reversed'] . ' verdict(s) CHANGED since the last run — account data moved (a merge or a reassignment). The corrections are noted on the affected players.');
        }

        return 0;
    }
}
