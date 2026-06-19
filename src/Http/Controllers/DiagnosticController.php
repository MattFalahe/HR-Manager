<?php

namespace HrManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR Manager diagnostic dashboard. Admin-only, NOT in the sidebar (reach it
 * directly at /hr-manager/diagnostic). Read-only health + integration checks
 * plus a notification test and an application-pipeline trace.
 *
 * Tab structure follows the suite-wide diagnostic standard:
 *   Health Checks (default) / System Validation / Settings Health /
 *   Data Integrity / Notification Test / Application Trace.
 *
 * The heavy per-tab sections only build when their tab is active (?diag_tab=X);
 * Health Checks (the default landing) is always computed and stays cheap.
 */
class DiagnosticController extends Controller
{
    /** SeAT core tables HR depends on. */
    private const SEAT_TABLES = [
        'refresh_tokens', 'character_infos', 'character_affiliations',
        'corporation_infos', 'corporation_members', 'corporation_member_trackings',
        'corporation_roles', 'character_info_skills', 'universe_names',
    ];

    /** HR-owned tables (mirrors DiagnoseCommand). */
    private const HR_TABLES = [
        'hr_manager_settings', 'hr_manager_webhook_configurations',
        'hr_manager_form_templates', 'hr_manager_form_template_questions',
        'hr_manager_applications', 'hr_manager_application_answers',
        'hr_manager_application_status_history', 'hr_manager_notes',
        'hr_manager_member_assessments', 'hr_manager_role_tier_mappings',
        'hr_manager_player_status', 'hr_manager_member_history_events',
        'hr_manager_player_classifications', 'hr_manager_purge_reminders',
        'hr_manager_recruitment_landings', 'hr_manager_recruitment_views',
        'hr_manager_watchlist_entries', 'hr_manager_watchlist_detections',
        'hr_manager_intel_notes', 'hr_manager_player_identities',
        'hr_manager_character_identity_mappings', 'hr_manager_recruiter_access_grants',
        'hr_manager_fc_activity',
    ];

    /** Manager Core capabilities HR consumes (plugin:capability). */
    private const CONSUMED_CAPABILITIES = [
        'corp-wallet-manager:ratting.getCharacterIncome',
        'corp-wallet-manager:ratting.getCharacterMonthly',
        'corp-wallet-manager:ratting.getCharacterBreakdown',
        'corp-wallet-manager:contribution.getCharacterTrend',
        'corp-wallet-manager:contribution.getActivityGaps',
        'corp-wallet-manager:contribution.getNetPosition',
        'corp-wallet-manager:contribution.getLifetimeSummary',
        'corp-wallet-manager:contribution.getCharacterPercentile',
        'corp-wallet-manager:contribution.getCharacterTaxCompliance',
        'corp-wallet-manager:contribution.getCharacterByCategory',
        'corp-wallet-manager:contribution.getCharacterEntries',
        'corp-wallet-manager:wallet.getDirectorAttribution',
        'corp-wallet-manager:wallet.getCorpOutflows',
        'corp-wallet-manager:contribution.getCorpMemberSummary',
        // Blueprint Manager — consumed by the Corp Health blueprint panels and
        // the classifier's blueprint-engagement signal (via CrossPluginDataService).
        'blueprint-manager:blueprint.getCharacterStats',
        'blueprint-manager:blueprint.getCorpSummary',
        // Structure Manager — the Corp Health Structure Compliance tab consumes
        // SM's doctrine-compliance report (SM owns the feature; HR retired its
        // local copy).
        'structure-manager:compliance.getForCorporation',
    ];

    /** EventBus subscriptions HR owns. */
    private const OWNED_SUBSCRIPTIONS = [
        'mining.*',
        'member.contribution.stalled', 'member.contribution.milestone',
        'member.contribution.drop_detected', 'member.tax.compliance_dropped',
        'wallet.unusual_recipient_detected', 'pings.broadcast.sent',
        'pings.formup.scheduled',
    ];

    // TODO (HR-side subscription-receipt panel): MC v1.0.1 added a Topic Matrix
    // on its own diagnostic that shows the publish/subscribe graph from MC's
    // vantage point. The complement we want HERE is the RECEIVE side: for each
    // OWNED_SUBSCRIPTIONS pattern, prove HR actually consumed the events, not
    // just that a subscription row exists. Sketch:
    //   - Per pattern: last event received (from manager_core_event_log joined
    //     on fnmatch, or simpler — from HR's own member_history_events /
    //     fc_activity write timestamps which prove the handler ran), count in
    //     24h, and what the handler DID (e.g. "12 member.* events -> 3
    //     assessment-cache invalidations, 1 watchlist nudge").
    //   - Surface on a new "Integration" sub-section of the Health or a
    //     dedicated tab. Flags a subscription that exists but has never fired a
    //     handler (the silent-failure case the MC matrix can't see from its
    //     side, because MC only knows it dispatched, not that HR acted).
    //   - Pairs with MC's matrix: MC says "delivered", HR says "and here's what
    //     I did with it". Together they close the loop end-to-end.
    // Not blocking; do when next touching HR's diagnostic. See MC's
    // DiagnosticController::integrationMatrix for the publish-side reference.

    /** EventBus topics HR publishes (must be registered in MC). */
    private const OWNED_PUBLISHED_TOPICS = [
        'hr.application.submitted', 'hr.application.accepted', 'hr.application.rejected',
        'hr.application.withdrawn', 'hr.application.status_changed', 'hr.application.joined_corp',
        'hr.player.classification_changed', 'hr.player.flagged_at_risk', 'hr.player.flagged_inactive',
        'hr.player.flagged_dead_weight', 'hr.player.recovered', 'hr.player.flagged_wallet_stalled',
        'hr.player.flagged_wallet_compliance_low', 'hr.player.flagged_negative_contribution',
        'hr.player.inactive_director', 'hr.player.silent_wallet_director', 'hr.player.milestone_reached',
        'hr.purge.reminder', 'hr.purge.executed',
    ];

    /**
     * The HR scheduled commands (signatures) that should be on the schedule.
     * Mirror ScheduleSeeder::getSchedules() exactly — a stale entry here makes
     * the health check warn when nothing is actually wrong.
     */
    private const HR_SCHEDULE = [
        'hr-manager:cache-assessments', 'hr-manager:cleanup',
        'hr-manager:classify-players', 'hr-manager:dispatch-purge-reminders',
        'hr-manager:detect-corp-joins', 'hr-manager:detect-token-loss',
        'hr-manager:scan-watchlist', 'hr-manager:sweep-access-grants',
    ];

    private const VALID_TABS = ['health', 'validation', 'settings', 'integrity', 'notifications', 'trace'];

    public function index(Request $request)
    {
        $activeTab = (string) $request->input('diag_tab', 'health');
        if (!in_array($activeTab, self::VALID_TABS, true)) {
            $activeTab = 'health';
        }

        // Health Checks (default landing) is always computed and cheap.
        $health  = $this->healthChecks();
        $summary = $this->summarise($health);

        // Heavy sections build only when their tab is active.
        $validation = $activeTab === 'validation' ? $this->systemValidation() : null;
        $settings   = $activeTab === 'settings'   ? $this->settingsHealth()   : null;
        $integrity  = $activeTab === 'integrity'  ? $this->dataIntegrity()    : null;

        $traceResult = null;
        if ($activeTab === 'trace' && $request->filled('application_id')) {
            $traceResult = $this->traceApplication((int) $request->input('application_id'));
        }

        $webhooks = Schema::hasTable('hr_manager_webhook_configurations')
            ? DB::table('hr_manager_webhook_configurations')->where('is_enabled', true)->get()
            : collect();

        return view('hr-manager::diagnostic.index', compact(
            'activeTab', 'health', 'summary', 'validation',
            'settings', 'integrity', 'traceResult', 'webhooks'
        ));
    }

    /**
     * Send a test notification to a configured webhook so operators can
     * confirm Discord/Slack delivery without waiting for a real event.
     */
    public function sendTestNotification(Request $request)
    {
        $webhookId = (int) $request->input('webhook_id');
        $webhook = Schema::hasTable('hr_manager_webhook_configurations')
            ? \HrManager\Models\WebhookConfiguration::find($webhookId)
            : null;

        if (!$webhook) {
            return back()->with('error', 'Webhook not found.');
        }

        try {
            $ok = app(\HrManager\Services\WebhookService::class)->testWebhook($webhook);
            return $ok
                ? back()->with('success', 'Test message dispatched to "' . ($webhook->name ?? ('#' . $webhook->id)) . '". Check the channel.')
                : back()->with('error', 'Test send returned failure — check the webhook URL and channel permissions.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Test send failed: ' . $e->getMessage());
        }
    }

    // =================================================================
    // Tab 1 — Health Checks (always computed)
    // =================================================================

    private function healthChecks(): array
    {
        $checks = [];

        // Environment
        $cacheOk = true;
        try {
            // Probe with a string token: some cache backends round-trip scalars
            // as strings, so a strict === against an int (1) would falsely fail
            // even on a perfectly healthy cache. A string survives every driver.
            $token = bin2hex(random_bytes(8));
            \Illuminate\Support\Facades\Cache::put('hr-diag-probe', $token, 5);
            $cacheOk = (string) \Illuminate\Support\Facades\Cache::get('hr-diag-probe') === $token;
        } catch (\Throwable $e) {
            $cacheOk = false;
        }
        $checks[] = $this->result('PHP version', version_compare(PHP_VERSION, '8.1', '>=') ? 'ok' : 'warn', PHP_VERSION);
        $checks[] = $this->result('Cache backend', $cacheOk ? 'ok' : 'fail', $cacheOk ? 'reachable' : 'cache read/write failed');

        // SeAT tables
        $missingSeat = array_values(array_filter(self::SEAT_TABLES, fn ($t) => !Schema::hasTable($t)));
        $checks[] = $this->result(
            'SeAT core tables',
            empty($missingSeat) ? 'ok' : 'warn',
            empty($missingSeat) ? count(self::SEAT_TABLES) . ' present' : 'missing: ' . implode(', ', $missingSeat),
            $missingSeat
        );

        // HR tables
        $missingHr = array_values(array_filter(self::HR_TABLES, fn ($t) => !Schema::hasTable($t)));
        $checks[] = $this->result(
            'HR Manager tables',
            empty($missingHr) ? 'ok' : 'fail',
            empty($missingHr) ? count(self::HR_TABLES) . ' present' : 'MISSING: ' . implode(', ', $missingHr),
            $missingHr
        );

        // Manager Core presence
        $mcPresent = class_exists('\\ManagerCore\\Services\\PluginBridge');
        $checks[] = $this->result(
            'Manager Core (optional hub)',
            $mcPresent ? 'ok' : 'warn',
            $mcPresent ? 'installed — cross-plugin features active' : 'absent — HR runs standalone with reduced data'
        );

        // EventBus subscriptions persisted
        if ($mcPresent && Schema::hasTable('manager_core_event_subscriptions')) {
            $subCount = DB::table('manager_core_event_subscriptions')
                ->where('subscriber_plugin', 'hr-manager')->count();
            $checks[] = $this->result(
                'EventBus subscriptions',
                $subCount >= count(self::OWNED_SUBSCRIPTIONS) ? 'ok' : 'warn',
                $subCount . ' of ' . count(self::OWNED_SUBSCRIPTIONS) . ' registered'
            );
        } else {
            $checks[] = $this->result('EventBus subscriptions', 'warn', 'Manager Core absent — no EventBus');
        }

        // Schedules
        $scheduleOk = Schema::hasTable('schedules');
        if ($scheduleOk) {
            $present = DB::table('schedules')
                ->where(function ($q) {
                    foreach (self::HR_SCHEDULE as $cmd) {
                        $q->orWhere('command', 'like', '%' . $cmd . '%');
                    }
                })->count();
            $checks[] = $this->result(
                'Scheduled commands',
                $present >= count(self::HR_SCHEDULE) ? 'ok' : 'warn',
                $present . ' of ' . count(self::HR_SCHEDULE) . ' HR crons on the schedule'
            );
        }

        // Webhooks (the column is is_enabled, not is_active)
        $whCount = Schema::hasTable('hr_manager_webhook_configurations')
            ? DB::table('hr_manager_webhook_configurations')->where('is_enabled', true)->count() : 0;
        $checks[] = $this->result(
            'Active webhooks',
            $whCount > 0 ? 'ok' : 'warn',
            $whCount > 0 ? $whCount . ' configured' : 'none configured — notifications will not deliver'
        );

        return $checks;
    }

    // =================================================================
    // Tab 2 — System Validation (cross-plugin contract health)
    // =================================================================

    private function systemValidation(): array
    {
        $out = ['plugins' => [], 'capabilities' => [], 'direct' => [], 'subscriptions' => [], 'topics' => []];
        // Resolve via ::class, NOT a leading-backslash string. MC binds the
        // bridge as a singleton under 'ManagerCore\Services\PluginBridge';
        // app('\Manager...') with a leading backslash misses that binding and
        // builds a FRESH empty bridge, so every hasCapability() reads false even
        // though CWM registered its capabilities into the real singleton. (HR's
        // CrossPluginDataService resolves via ::class, which is why the data
        // flows in the real UI but the diagnostic falsely reported it absent.)
        $bridge = class_exists(\ManagerCore\Services\PluginBridge::class)
            ? app(\ManagerCore\Services\PluginBridge::class) : null;

        // Suite-plugin presence. Answers "is the provider even installed?" so the
        // capability rows below read as expected-absent (optional sibling not
        // installed) rather than alarming when nothing is actually wrong.
        $installed = $this->detectSuitePlugins();
        foreach ($installed as $row) {
            $out['plugins'][] = $this->result(
                $row['label'],
                $row['installed'] ? 'ok' : 'warn',
                $row['detail']
            );
        }
        // Consumed capabilities, grouped by the providing sibling (CWM +
        // Blueprint Manager). The provider slug is the part before ':' in each
        // entry, so the messaging can name the exact plugin that's missing.
        foreach (self::CONSUMED_CAPABILITIES as $cap) {
            [$plugin, $name] = explode(':', $cap, 2);
            $providerInstalled = $installed[$plugin]['installed'] ?? false;
            $providerLabel = $installed[$plugin]['label'] ?? $plugin;
            $available = false;
            if ($bridge && method_exists($bridge, 'hasCapability')) {
                try {
                    $available = (bool) $bridge->hasCapability($plugin, $name);
                } catch (\Throwable $e) {
                    $available = false;
                }
            }
            if ($available) {
                $out['capabilities'][] = $this->result($cap, 'ok', 'available');
            } elseif (!$bridge) {
                $out['capabilities'][] = $this->result($cap, 'warn', 'Manager Core absent (capabilities are brokered through it)');
            } elseif (!$providerInstalled) {
                $out['capabilities'][] = $this->result($cap, 'warn', $providerLabel . ' not installed (optional)');
            } else {
                $out['capabilities'][] = $this->result($cap, 'warn', $providerLabel . ' installed but capability not exposed (check its version)');
            }
        }

        // Direct-query + event integrations (NOT bridge capabilities). HR reads
        // Mining Manager via direct model queries; SeAT Broadcast and Mining
        // Manager events arrive via the EventBus subscriptions listed below.
        try {
            $mmAvailable = app(\HrManager\Services\CrossPluginDataService::class)->isPluginAvailable('mining-manager');
        } catch (\Throwable $e) {
            $mmAvailable = false;
        }
        $out['direct'][] = $this->result(
            'Mining Manager (direct model queries)',
            $mmAvailable ? 'ok' : 'warn',
            $mmAvailable
                ? 'available — mining ledger, tax history and event attendance read directly'
                : 'Mining Manager not installed (optional)'
        );

        // Subscriptions persisted + active
        if (Schema::hasTable('manager_core_event_subscriptions')) {
            foreach (self::OWNED_SUBSCRIPTIONS as $pattern) {
                $row = DB::table('manager_core_event_subscriptions')
                    ->where('subscriber_plugin', 'hr-manager')
                    ->where('event_pattern', $pattern)->first();
                $status = !$row ? 'fail' : ((isset($row->is_active) && !$row->is_active) ? 'warn' : 'ok');
                $msg = !$row ? 'MISSING' : '-> ' . ($row->handler_capability ?? '?');
                $out['subscriptions'][] = $this->result($pattern, $status, $msg);
            }
        } else {
            $out['subscriptions'][] = $this->result('manager_core_event_subscriptions', 'warn', 'table absent — Manager Core not installed/migrated');
        }

        // Published topics registered in MC
        $topicsKnown = class_exists('\\ManagerCore\\Topics');
        foreach (self::OWNED_PUBLISHED_TOPICS as $topic) {
            if (!$topicsKnown) {
                $out['topics'][] = $this->result($topic, 'warn', 'Manager Core absent — events stay local');
                continue;
            }
            $entry = \ManagerCore\Topics::describe($topic);
            $ok = $entry && (($entry['publisher'] ?? null) === 'hr-manager');
            $out['topics'][] = $this->result(
                $topic,
                $entry ? ($ok ? 'ok' : 'fail') : 'fail',
                $entry ? ($ok ? 'registered' : 'publisher mismatch') : 'NOT REGISTERED (would be dropped)'
            );
        }

        return $out;
    }

    // =================================================================
    // Tab 3 — Settings Health
    // =================================================================

    private function settingsHealth(): array
    {
        $out = [];

        $tierMaps = Schema::hasTable('hr_manager_role_tier_mappings')
            ? DB::table('hr_manager_role_tier_mappings')->count() : 0;
        $out[] = $this->result('Activity tier mappings', $tierMaps > 0 ? 'ok' : 'warn', $tierMaps . ' configured');

        // recruitment_landings is NOT soft-deletable (no deleted_at column), so
        // we count rows directly — a whereNull('deleted_at') here 500s.
        $landings = Schema::hasTable('hr_manager_recruitment_landings')
            ? DB::table('hr_manager_recruitment_landings')->count() : 0;
        $published = Schema::hasTable('hr_manager_recruitment_landings')
            ? DB::table('hr_manager_recruitment_landings')->where('is_published', true)->count() : 0;
        $out[] = $this->result('Recruitment landings', 'ok', $landings . ' total, ' . $published . ' published');

        $templates = Schema::hasTable('hr_manager_form_templates')
            ? DB::table('hr_manager_form_templates')->whereNull('deleted_at')->where('is_active', true)->count() : 0;
        $out[] = $this->result('Active application templates', $templates > 0 ? 'ok' : 'warn', $templates . ' active');

        // SSO scope-profile sufficiency — does the recruitment funnel's
        // effective profile carry the scopes HR uses to assess applicants?
        try {
            $ssoSvc = app(\HrManager\Services\RecruitmentSsoService::class);
            $sso = $ssoSvc->analyze();
            $profileLabel = $sso['profile_name'] ?: '(none)';
            if ($sso['stale']) {
                $out[] = $this->result('SSO recruitment profile', 'warn', 'Chosen profile no longer exists; falling back to SeAT default');
            }
            if (!$sso['minimal_ok']) {
                $out[] = $this->result('SSO scope sufficiency', 'fail', 'Profile "' . $profileLabel . '" is missing required publicData scope');
            } elseif (!$sso['full_ok']) {
                $missing = implode(', ', $sso['missing_recommended']);
                $out[] = $this->result('SSO scope sufficiency', 'warn', 'Profile "' . $profileLabel . '" works but lacks assessment scopes: ' . $missing);
            } else {
                $out[] = $this->result('SSO scope sufficiency', 'ok', 'Profile "' . $profileLabel . '" carries all assessment scopes');
            }

            // Scope-downgrade safety: SeAT overwrites token scopes on login,
            // so a recruitment profile narrower than the default reduces an
            // existing character's scopes on a fresh login through the funnel.
            $lost = $ssoSvc->scopesLostVsDefault();
            if (!empty($lost)) {
                $out[] = $this->result(
                    'SSO scope downgrade risk',
                    'warn',
                    'Recruitment profile is narrower than the SeAT default; a fresh login through recruitment would strip these scopes from an existing character: ' . implode(', ', $lost)
                );
            } else {
                $out[] = $this->result('SSO scope downgrade risk', 'ok', 'Recruitment login will not reduce existing characters\' scopes');
            }
        } catch (\Throwable $e) {
            $out[] = $this->result('SSO scope sufficiency', 'warn', 'Could not analyse SSO scopes: ' . $e->getMessage());
        }

        // Webhook config sanity — HTTPS + non-empty
        if (Schema::hasTable('hr_manager_webhook_configurations')) {
            $webhooks = DB::table('hr_manager_webhook_configurations')->where('is_enabled', true)->get();
            foreach ($webhooks as $wh) {
                $url = (string) ($wh->webhook_url ?? '');
                $https = str_starts_with($url, 'https://');
                $out[] = $this->result(
                    'Webhook: ' . ($wh->name ?? ('#' . $wh->id)),
                    $https ? 'ok' : 'fail',
                    $https ? ucfirst($wh->type ?? 'discord') . ', HTTPS' : 'NOT HTTPS — will be rejected'
                );
            }
        }

        return $out;
    }

    // =================================================================
    // Tab 4 — Data Integrity
    // =================================================================

    private function dataIntegrity(): array
    {
        $out = [];

        // Classification freshness
        if (Schema::hasTable('hr_manager_player_classifications')) {
            $latest = DB::table('hr_manager_player_classifications')->max('classified_at');
            $total = DB::table('hr_manager_player_classifications')->count();
            $stale = $latest && \Illuminate\Support\Carbon::parse($latest)->lt(now()->subDays(2));
            $out[] = $this->result(
                'Classifier freshness',
                !$latest ? 'warn' : ($stale ? 'warn' : 'ok'),
                !$latest ? 'never run — run hr-manager:classify-players' : ($total . ' players, last run ' . \Illuminate\Support\Carbon::parse($latest)->diffForHumans())
            );
        }

        // Assessment cache staleness
        if (Schema::hasTable('hr_manager_member_assessments')) {
            $count = DB::table('hr_manager_member_assessments')->count();
            $out[] = $this->result('Assessment cache', $count > 0 ? 'ok' : 'warn', $count . ' rows');
        }

        // CWM signal traffic (last 30d)
        if (Schema::hasTable('hr_manager_member_history_events')) {
            $since = now()->subDays(30);
            $types = ['wallet_stalled', 'wallet_compliance_dropped', 'wallet_contribution_drop', 'wallet_unusual_recipient', 'wallet_milestone'];
            $counts = DB::table('hr_manager_member_history_events')
                ->where('occurred_at', '>=', $since)
                ->whereIn('event_type', $types)
                ->selectRaw('event_type, COUNT(*) as c')
                ->groupBy('event_type')->pluck('c', 'event_type')->toArray();
            $total = array_sum($counts);
            $out[] = $this->result('CWM signal traffic (30d)', 'ok', $total . ' events: ' . (empty($counts) ? 'none' : http_build_query($counts, '', ', ')));
        }

        // Orphan applications (no template)
        if (Schema::hasTable('hr_manager_applications') && Schema::hasTable('hr_manager_form_templates')) {
            $orphans = DB::table('hr_manager_applications as a')
                ->leftJoin('hr_manager_form_templates as t', 't.id', '=', 'a.template_id')
                ->whereNull('a.deleted_at')->whereNull('t.id')->count();
            $out[] = $this->result('Orphan applications', $orphans === 0 ? 'ok' : 'warn', $orphans === 0 ? 'none' : $orphans . ' reference a deleted template');
        }

        // FC activity accumulation
        if (Schema::hasTable('hr_manager_fc_activity')) {
            $fc = DB::table('hr_manager_fc_activity')->count();
            $out[] = $this->result('FC activity (EventBus)', 'ok', $fc . ' broadcasts accumulated');
        }

        return $out;
    }

    // =================================================================
    // Tab 6 — Application Trace
    // =================================================================

    private function traceApplication(int $applicationId): array
    {
        if (!Schema::hasTable('hr_manager_applications')) {
            return ['found' => false, 'message' => 'applications table missing'];
        }
        $app = DB::table('hr_manager_applications')->where('id', $applicationId)->first();
        if (!$app) {
            return ['found' => false, 'message' => 'No application #' . $applicationId];
        }

        $steps = [];
        $submittedAt = $app->created_at ?? null;
        $steps[] = ['label' => 'Application row', 'status' => 'ok', 'detail' => 'status=' . ($app->status ?? '?') . ', corp=' . ($app->corporation_id ?? '?')
            . ($submittedAt ? ', submitted ' . \Illuminate\Support\Carbon::parse($submittedAt)->diffForHumans() : '')];

        // Template
        $tpl = Schema::hasTable('hr_manager_form_templates')
            ? DB::table('hr_manager_form_templates')->where('id', $app->template_id)->first() : null;
        $steps[] = ['label' => 'Template', 'status' => $tpl ? 'ok' : 'warn', 'detail' => $tpl ? ('#' . $tpl->id . ' ' . ($tpl->name ?? '')) : 'missing/deleted'];

        // Answers
        $answers = Schema::hasTable('hr_manager_application_answers')
            ? DB::table('hr_manager_application_answers')->where('application_id', $applicationId)->count() : 0;
        $steps[] = ['label' => 'Answers', 'status' => 'ok', 'detail' => $answers . ' submitted'];

        // Handlers
        $handlerRows = Schema::hasTable('hr_manager_application_handlers')
            ? DB::table('hr_manager_application_handlers')->where('application_id', $applicationId)->orderBy('joined_at')->get() : collect();
        $steps[] = ['label' => 'Assigned recruiters', 'status' => 'ok', 'detail' => $handlerRows->count() . ' handler(s)'];

        // Status history
        $history = Schema::hasTable('hr_manager_application_status_history')
            ? DB::table('hr_manager_application_status_history')->where('application_id', $applicationId)->orderBy('created_at')->get() : collect();
        $steps[] = ['label' => 'Status transitions', 'status' => 'ok', 'detail' => $history->count() . ' recorded'];

        // Notes (application-scoped, excluding soft-deleted)
        $notes = Schema::hasTable('hr_manager_notes')
            ? DB::table('hr_manager_notes')->where('noteable_type', 'application')->where('noteable_id', $applicationId)
                ->whereNull('deleted_at')->orderBy('created_at')->get() : collect();
        $steps[] = ['label' => 'Notes', 'status' => 'ok', 'detail' => $notes->count() . ' recorded'];

        // Character resolution
        $charName = app(\HrManager\Services\NameResolutionService::class)->getCharacterName((int) $app->character_id) ?? ('#' . $app->character_id);
        $steps[] = ['label' => 'Applicant', 'status' => 'ok', 'detail' => $charName . ' (' . $app->character_id . ')'];

        // ---- Unified, chronological timeline ("when was what done, by whom") ----
        // Resolve every SeAT actor (status changer, note author, recruiter) to a
        // name in one batch + flag superusers, mirroring the players-page notes UI.
        $actorIds = array_values(array_unique(array_filter(array_map('intval', array_merge(
            $history->pluck('changed_by')->all(),
            $notes->pluck('author_id')->all(),
            $handlerRows->pluck('user_id')->all()
        )), fn ($id) => $id > 0)));
        $actorNames = app(\HrManager\Services\NameResolutionService::class)->getUserNames($actorIds);
        $admins = empty($actorIds) ? [] : DB::table('users')->whereIn('id', $actorIds)->where('admin', true)
            ->pluck('id')->map(fn ($i) => (int) $i)->all();
        $actor = function ($userId) use ($actorNames, $admins) {
            $uid = (int) $userId;
            if ($uid <= 0) {
                return ['name' => 'system', 'admin' => false];
            }
            return ['name' => $actorNames[$uid] ?? ('User #' . $uid), 'admin' => in_array($uid, $admins, true)];
        };
        $fmt = function ($when) {
            if (!$when) {
                return ['ts' => PHP_INT_MAX, 'abs' => '-', 'rel' => ''];
            }
            $c = \Illuminate\Support\Carbon::parse($when);
            return ['ts' => $c->timestamp, 'abs' => $c->format('Y-m-d H:i'), 'rel' => $c->diffForHumans()];
        };

        $timeline = [];
        $timeline[] = array_merge($fmt($submittedAt), [
            'type' => 'submitted', 'icon' => 'fa-paper-plane', 'title' => 'Application submitted',
            'actor' => null, 'admin' => false, 'private' => false, 'detail' => $charName,
        ]);
        foreach ($history as $h) {
            $a = $actor($h->changed_by);
            $from = $h->old_status ? $this->humanStatus($h->old_status) . ' -> ' : '';
            $timeline[] = array_merge($fmt($h->created_at), [
                'type' => 'status', 'icon' => 'fa-exchange-alt',
                'title' => 'Status: ' . $from . $this->humanStatus($h->new_status),
                'actor' => $a['name'], 'admin' => $a['admin'], 'private' => false,
                'detail' => (string) ($h->comment ?? ''),
            ]);
        }
        foreach ($handlerRows as $hd) {
            $a = $actor($hd->user_id);
            $timeline[] = array_merge($fmt($hd->joined_at ?? $hd->created_at), [
                'type' => 'handler', 'icon' => 'fa-user-plus',
                'title' => 'Recruiter joined' . ($hd->role_label ? ' (' . $hd->role_label . ')' : ''),
                'actor' => $a['name'], 'admin' => $a['admin'], 'private' => false, 'detail' => '',
            ]);
        }
        foreach ($notes as $n) {
            $a = $actor($n->author_id);
            $timeline[] = array_merge($fmt($n->created_at), [
                'type' => 'note', 'icon' => 'fa-sticky-note',
                'title' => $n->is_private ? 'Private note' : 'Note',
                'actor' => $a['name'], 'admin' => $a['admin'], 'private' => (bool) $n->is_private,
                'detail' => \Illuminate\Support\Str::limit((string) $n->content, 180),
            ]);
        }
        usort($timeline, fn ($x, $y) => $x['ts'] <=> $y['ts']);

        return ['found' => true, 'application' => $app, 'steps' => $steps, 'timeline' => $timeline];
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function result(string $label, string $status, string $message, array $details = []): array
    {
        return ['label' => $label, 'status' => $status, 'message' => $message, 'details' => $details];
    }

    private function summarise(array $checks): array
    {
        $counts = ['ok' => 0, 'warn' => 0, 'fail' => 0];
        foreach ($checks as $c) {
            $s = $c['status'] ?? 'ok';
            if (isset($counts[$s])) {
                $counts[$s]++;
            }
        }
        return $counts;
    }

    /**
     * Detect which sibling plugins of the suite are installed, via Composer's
     * runtime API (authoritative for a Composer-managed SeAT). Keyed by plugin
     * SLUG (the same token capabilities use before ':') so the capability loop
     * can resolve a provider's install state + display name directly.
     *
     * @return array<string,array{label:string,package:string,installed:bool,detail:string}>
     */
    private function detectSuitePlugins(): array
    {
        // slug => [packagist name, display name]. Display names follow the suite
        // naming convention (e.g. the Discord pings package is "SeAT Broadcast").
        $suite = [
            'manager-core'        => ['mattfalahe/manager-core', 'Manager Core'],
            'corp-wallet-manager' => ['mattfalahe/corp-wallet-manager', 'Corp Wallet Manager'],
            'mining-manager'      => ['mattfalahe/mining-manager', 'Mining Manager'],
            'structure-manager'   => ['mattfalahe/structure-manager', 'Structure Manager'],
            'buyback-manager'     => ['mattfalahe/buyback-manager', 'Buyback Manager'],
            'seat-discord-pings'  => ['mattfalahe/seat-discord-pings', 'SeAT Broadcast'],
            'blueprint-manager'   => ['mattfalahe/blueprint-manager', 'Blueprint Manager'],
        ];

        $hasApi = class_exists('\\Composer\\InstalledVersions');
        $out = [];
        foreach ($suite as $slug => [$pkg, $label]) {
            if (!$hasApi) {
                $out[$slug] = ['label' => $label, 'package' => $pkg, 'installed' => false, 'detail' => 'cannot detect (Composer runtime API unavailable)'];
                continue;
            }
            $isIn = false;
            $ver = null;
            try {
                $isIn = \Composer\InstalledVersions::isInstalled($pkg);
                $ver = $isIn ? \Composer\InstalledVersions::getPrettyVersion($pkg) : null;
            } catch (\Throwable $e) {
                $isIn = false;
            }
            $out[$slug] = [
                'label'     => $label,
                'package'   => $pkg,
                'installed' => $isIn,
                'detail'    => $isIn ? ('installed' . ($ver ? ' (' . $ver . ')' : '')) : 'not installed (optional)',
            ];
        }
        return $out;
    }

    /** Human-readable form of a stored status slug (e.g. "in_review" -> "In review"). */
    private function humanStatus(?string $status): string
    {
        if ($status === null || $status === '') {
            return '?';
        }
        return ucfirst(str_replace('_', ' ', $status));
    }
}
