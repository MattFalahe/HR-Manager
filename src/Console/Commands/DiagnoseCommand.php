<?php

namespace HrManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DiagnoseCommand extends Command
{
    protected $signature = 'hr-manager:diagnose';

    protected $description = 'Run diagnostics on the HR Manager plugin';

    /**
     * Capabilities HR Manager CONSUMES from sibling plugins. Diagnostic
     * checks each is registered on MC's PluginBridge so operators can spot
     * a missing capability before it bites the classifier or the wallet
     * activity panel.
     */
    private const CONSUMED_CAPABILITIES = [
        // Ratting
        'corp-wallet-manager:ratting.getCharacterIncome',
        'corp-wallet-manager:ratting.getCharacterMonthly',
        'corp-wallet-manager:ratting.getCharacterBreakdown',
        // Contribution / wallet signals
        'corp-wallet-manager:contribution.getCharacterTrend',
        'corp-wallet-manager:contribution.getActivityGaps',
        'corp-wallet-manager:contribution.getNetPosition',
        'corp-wallet-manager:contribution.getLifetimeSummary',
        'corp-wallet-manager:contribution.getCharacterPercentile',
        'corp-wallet-manager:contribution.getCharacterTaxCompliance',
        'corp-wallet-manager:contribution.getCharacterByCategory',
        'corp-wallet-manager:contribution.getCharacterEntries',
        'corp-wallet-manager:wallet.getDirectorAttribution',
        // Wallet Insights (Corp Health Economy tab)
        'corp-wallet-manager:wallet.getCorpOutflows',
        // Corp-wide member financials (all-member Wallet Insights)
        'corp-wallet-manager:contribution.getCorpMemberSummary',
    ];

    /**
     * EventBus subscriptions HR Manager owns. Diagnostic verifies each is
     * present in manager_core_event_subscriptions so a partial provider
     * boot can be spotted.
     */
    private const OWNED_SUBSCRIPTIONS = [
        // Mining cache invalidation + history
        'mining.*',
        // CWM wallet signals (publisher: corp-wallet-manager, member.* prefix)
        'member.contribution.stalled',
        'member.contribution.milestone',
        'member.contribution.drop_detected',
        'member.tax.compliance_dropped',
        'wallet.unusual_recipient_detected',
        // SeAT Broadcast FC activity + formup planning
        'pings.broadcast.sent',
        'pings.formup.scheduled',
    ];

    /**
     * EventBus topics HR Manager publishes via Topics::publish. Diagnostic
     * verifies each is registered in MC's topic registry — an unregistered
     * topic is silently dropped by Topics::publish (logged as "unknown
     * topic"), so this catches a publish site that outran the registry.
     */
    private const OWNED_PUBLISHED_TOPICS = [
        // Recruitment application lifecycle (ApplicationService + DetectCorpJoinsCommand)
        'hr.application.submitted',
        'hr.application.accepted',
        'hr.application.rejected',
        'hr.application.withdrawn',
        'hr.application.status_changed',
        'hr.application.joined_corp',
        // Player classification + director signals (ClassifierService)
        'hr.player.classification_changed',
        'hr.player.flagged_at_risk',
        'hr.player.flagged_inactive',
        'hr.player.flagged_dead_weight',
        'hr.player.recovered',
        'hr.player.flagged_wallet_stalled',
        'hr.player.flagged_wallet_compliance_low',
        'hr.player.flagged_negative_contribution',
        'hr.player.inactive_director',
        'hr.player.silent_wallet_director',
        // Milestone (WalletEventHandler)
        'hr.player.milestone_reached',
        // Purge workflow (PurgeService)
        'hr.purge.reminder',
        'hr.purge.executed',
    ];

    public function handle(): int
    {
        $this->info('=== HR Manager Diagnostics ===');
        $this->newLine();

        $this->checkDatabaseTables();
        $this->newLine();

        $this->checkPluginBridge();
        $this->newLine();

        $this->checkConnectorAccess();
        $this->newLine();

        $this->checkConsumedCapabilities();
        $this->newLine();

        $this->checkEventBusSubscriptions();
        $this->newLine();

        $this->checkPublishedTopics();
        $this->newLine();

        $this->checkCwmTrafficSummary();
        $this->newLine();

        $this->checkQuickStats();
        $this->newLine();

        $this->info('Diagnostics complete.');
        return 0;
    }

    private function checkDatabaseTables(): void
    {
        $this->info('Database Tables:');
        $tables = [
            'hr_manager_settings',
            'hr_manager_webhook_configurations',
            'hr_manager_form_templates',
            'hr_manager_form_template_questions',
            'hr_manager_applications',
            'hr_manager_application_answers',
            'hr_manager_application_status_history',
            'hr_manager_notes',
            'hr_manager_member_assessments',
            'hr_manager_role_tier_mappings',
            'hr_manager_player_status',
            'hr_manager_member_history_events',
            'hr_manager_player_classifications',
            'hr_manager_purge_reminders',
            'hr_manager_recruitment_landings',
            'hr_manager_recruitment_views',
            // Post-v1.0.0 additions
            'hr_manager_watchlist_entries',
            'hr_manager_watchlist_detections',
            'hr_manager_intel_notes',
            'hr_manager_player_identities',
            'hr_manager_character_identity_mappings',
            'hr_manager_recruiter_access_grants',
            'hr_manager_applicant_connector_grants',
            'hr_manager_fc_activity',
        ];

        foreach ($tables as $table) {
            $exists = Schema::hasTable($table);
            $count = $exists ? DB::table($table)->count() : 'N/A';
            $status = $exists ? '<fg=green>OK</>' : '<fg=red>MISSING</>';
            $this->line("  {$table}: {$status} ({$count} rows)");
        }
    }

    private function checkPluginBridge(): void
    {
        $this->info('Plugin Bridge:');
        if (!class_exists('ManagerCore\Services\PluginBridge')) {
            $this->line('  Manager Core: <fg=yellow>Not installed</> (standalone mode)');
            return;
        }
        $bridge = app(\ManagerCore\Services\PluginBridge::class);
        $this->line('  Manager Core: <fg=green>Available</>');
        $this->line('  Mining Manager: ' . ($bridge->hasPlugin('mining-manager') ? '<fg=green>Detected</>' : '<fg=yellow>Not found</>'));
        $this->line('  Corp Wallet Manager: ' . ($bridge->hasPlugin('corp-wallet-manager') ? '<fg=green>Detected</>' : '<fg=yellow>Not found</>'));
        $this->line('  SeAT Connector (warlof): ' . (Schema::hasTable('seat_connector_users') ? '<fg=green>Detected</>' : '<fg=yellow>Not found</>'));
    }

    private function checkConnectorAccess(): void
    {
        $this->info('Applicant Connector-link Access:');

        $svc = app(\HrManager\Services\ApplicantConnectorAccessService::class);
        $enabled   = $svc->isFeatureEnabled();
        $available = $svc->connectorAvailable();

        $this->line('  Feature: ' . ($enabled ? '<fg=green>Enabled</>' : '<fg=yellow>Disabled</>'));
        $this->line('  SeAT Connector: ' . ($available ? '<fg=green>Detected</>' : '<fg=yellow>Not installed</>'));
        $this->line('  Granted permission: ' . $svc->resolvePermission());
        $this->line('  Max grant duration: ' . $svc->resolveMaxDurationDays() . ' days');

        if ($enabled && !$available) {
            $this->line('  <fg=yellow>Note: feature is on but no Connector is installed — grants no-op until one is.</>');
        }

        if (Schema::hasTable('hr_manager_applicant_connector_grants')) {
            $active = DB::table('hr_manager_applicant_connector_grants')
                ->whereNull('revoked_at')
                ->where('expires_at', '>=', now())
                ->count();
            $this->line("  Active grants: {$active}");
        }
    }

    private function checkConsumedCapabilities(): void
    {
        $this->info('Consumed Capabilities (CWM/MM via PluginBridge):');
        if (!class_exists('ManagerCore\Services\PluginBridge')) {
            $this->line('  <fg=yellow>Skipped — Manager Core not installed</>');
            return;
        }
        $bridge = app(\ManagerCore\Services\PluginBridge::class);

        foreach (self::CONSUMED_CAPABILITIES as $entry) {
            [$plugin, $capability] = explode(':', $entry, 2);
            try {
                $present = $bridge->hasCapability($plugin, $capability);
            } catch (\Throwable $e) {
                $present = false;
            }
            $status = $present ? '<fg=green>Registered</>' : '<fg=yellow>NOT REGISTERED</>';
            $this->line("  {$plugin} :: {$capability}: {$status}");
        }
    }

    private function checkEventBusSubscriptions(): void
    {
        $this->info('EventBus Subscriptions (HR-owned):');
        if (!Schema::hasTable('manager_core_event_subscriptions')) {
            $this->line('  <fg=yellow>manager_core_event_subscriptions table missing — Manager Core absent or not migrated</>');
            return;
        }

        foreach (self::OWNED_SUBSCRIPTIONS as $pattern) {
            $row = DB::table('manager_core_event_subscriptions')
                ->where('subscriber_plugin', 'hr-manager')
                ->where('event_pattern', $pattern)
                ->first();
            if (!$row) {
                $this->line("  {$pattern}: <fg=red>MISSING</>");
                continue;
            }
            $active = isset($row->is_active) ? (bool) $row->is_active : true;
            $status = $active ? '<fg=green>Active</>' : '<fg=yellow>Disabled</>';
            $handler = $row->handler_capability ?? '?';
            $this->line("  {$pattern} -> {$handler}: {$status}");
        }
    }

    private function checkPublishedTopics(): void
    {
        $this->info('EventBus Topics (HR-published) registered in Manager Core:');
        if (!class_exists('\\ManagerCore\\Topics')) {
            $this->line('  <fg=yellow>Manager Core absent — HR events stay local (Topics::publish no-ops)</>');
            return;
        }

        foreach (self::OWNED_PUBLISHED_TOPICS as $topic) {
            // describe() returns the registry entry or null for an unknown
            // topic. A null here means a publish site outran the registry and
            // Topics::publish would silently drop the event.
            $entry = \ManagerCore\Topics::describe($topic);
            if (!$entry) {
                $this->line("  {$topic}: <fg=red>NOT REGISTERED (would be dropped at publish)</>");
                continue;
            }
            $publisher = $entry['publisher'] ?? '?';
            $ok = $publisher === 'hr-manager';
            $note = $ok ? '<fg=green>registered</>' : "<fg=red>publisher mismatch ({$publisher})</>";
            $this->line("  {$topic}: {$note}");
        }
    }

    private function checkCwmTrafficSummary(): void
    {
        $this->info('CWM Event Traffic (last 30 days):');
        if (!Schema::hasTable('hr_manager_member_history_events')) {
            $this->line('  <fg=red>history_events table missing</>');
            return;
        }
        $since = now()->subDays(30);
        $eventTypes = [
            'wallet_stalled',
            'wallet_milestone',
            'wallet_compliance_dropped',
            'hr.player.flagged_wallet_stalled',
            'hr.player.flagged_wallet_compliance_low',
            'hr.player.flagged_negative_contribution',
            'hr.player.silent_wallet_director',
            'hr.player.milestone_reached',
        ];

        foreach ($eventTypes as $type) {
            $count = DB::table('hr_manager_member_history_events')
                ->where('event_type', $type)
                ->where('occurred_at', '>=', $since)
                ->count();
            $colour = $count > 0 ? 'green' : 'yellow';
            $this->line("  {$type}: <fg={$colour}>{$count}</>");
        }
    }

    private function checkQuickStats(): void
    {
        $this->info('Quick Stats:');
        if (Schema::hasTable('hr_manager_applications')) {
            $total = DB::table('hr_manager_applications')->whereNull('deleted_at')->count();
            $pending = DB::table('hr_manager_applications')->whereNull('deleted_at')->whereIn('status', ['applied', 'under_review', 'interview'])->count();
            $templates = DB::table('hr_manager_form_templates')->whereNull('deleted_at')->where('is_active', true)->count();
            $notes = DB::table('hr_manager_notes')->whereNull('deleted_at')->count();
            $assessments = DB::table('hr_manager_member_assessments')->count();
            $classifications = Schema::hasTable('hr_manager_player_classifications')
                ? DB::table('hr_manager_player_classifications')->count() : 0;
            $landings = Schema::hasTable('hr_manager_recruitment_landings')
                ? DB::table('hr_manager_recruitment_landings')->where('is_published', true)->count() : 0;

            $this->line("  Total applications: {$total}");
            $this->line("  Pending applications: {$pending}");
            $this->line("  Active templates: {$templates}");
            $this->line("  Notes: {$notes}");
            $this->line("  Cached assessments: {$assessments}");
            $this->line("  Player classifications: {$classifications}");
            $this->line("  Published recruitment pages: {$landings}");
        }
    }
}
