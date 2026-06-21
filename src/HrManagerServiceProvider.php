<?php

namespace HrManager;

use Seat\Services\AbstractSeatPlugin;
use Illuminate\Support\Facades\Log;

class HrManagerServiceProvider extends AbstractSeatPlugin
{
    public function boot()
    {
        if (!$this->app->routesAreCached()) {
            include __DIR__ . '/Http/routes.php';
        }

        $this->loadTranslationsFrom(__DIR__ . '/Resources/lang/', 'hr-manager');
        $this->loadViewsFrom(__DIR__ . '/Resources/views/', 'hr-manager');
        $this->loadMigrationsFrom(__DIR__ . '/Database/migrations/');

        // Register the post-SSO redirect catcher on the global `web`
        // middleware group. Fast-paths when the session value is
        // absent so the only cost on the steady-state hot path is
        // a single session read. See middleware docblock for the
        // SeAT AJAX-poll quirk this works around.
        try {
            $this->app['router']->pushMiddlewareToGroup(
                'web',
                \HrManager\Http\Middleware\RedirectAfterApplySso::class
            );
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] could not register RedirectAfterApplySso middleware: ' . $e->getMessage());
        }

        \Illuminate\Support\Facades\Blade::directive('hrDate', function ($expression) {
            return "<?php echo ($expression) ? \Carbon\Carbon::parse($expression)->format('M d, Y H:i') : '-'; ?>";
        });

        \Illuminate\Support\Facades\Blade::directive('hrDateShort', function ($expression) {
            return "<?php echo ($expression) ? \Carbon\Carbon::parse($expression)->format('M d, Y') : '-'; ?>";
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\Commands\CacheAssessmentDataCommand::class,
                Console\Commands\CleanupExpiredApplicationsCommand::class,
                Console\Commands\DiagnoseCommand::class,
                Console\Commands\ClassifyPlayersCommand::class,
                Console\Commands\DispatchPurgeRemindersCommand::class,
                Console\Commands\DetectCorpJoinsCommand::class,
                Console\Commands\DetectTokenLossCommand::class,
                Console\Commands\ScanWatchlistCommand::class,
                Console\Commands\SweepExpiredAccessGrantsCommand::class,
            ]);
        }

        // HR deliberately does NOT register a global morph map.
        //
        // hr_manager_notes stores its noteable_type as the literal strings
        // 'application' / 'member' / 'player', and every read is a plain string
        // match (the Note::noteable morphTo is never resolved anywhere), so no
        // morph map is needed for HR to function.
        //
        // A previous Relation::enforceMorphMap() here turned on app-wide
        // requireMorphMap enforcement, which made EVERY other plugin's
        // polymorphic relation throw ClassMorphViolationException for any model
        // not in HR's 3-entry map. seat-connector's Set->entity morph
        // (User/Role/Squad/Corporation/Alliance/Title) tripped this, breaking
        // the SeAT Squads page DataTables (members-table / roles-table) with
        // "Invalid JSON response". Even a non-enforced map would have globally
        // re-aliased core models (User -> 'player') and risked mismatching
        // seat-connector's stored entity_type. Do not re-add a global morph
        // map from this plugin.

        $this->add_publications();
        $this->registerPluginBridgeCapabilities();
    }

    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/Config/Menu/package.sidebar.php',
            'package.sidebar'
        );

        $this->registerPermissions(
            __DIR__ . '/Config/Permissions/hr-manager.permissions.php',
            'hr-manager'
        );

        $this->mergeConfigFrom(
            __DIR__ . '/Config/hr-manager.config.php',
            'hr-manager'
        );

        // Singletons
        foreach ([
            \HrManager\Services\ApplicationService::class,
            \HrManager\Services\AssessmentService::class,
            \HrManager\Services\CrossPluginDataService::class,
            \HrManager\Services\CharacterCheckService::class,
            \HrManager\Services\NotificationService::class,
            \HrManager\Services\WebhookService::class,
            \HrManager\Services\TierService::class,
            \HrManager\Services\PlayerService::class,
            \HrManager\Services\HistoryEventService::class,
            \HrManager\Services\ClassifierService::class,
            \HrManager\Services\PurgeService::class,
            \HrManager\Services\EligibilityService::class,
            \HrManager\Services\RecruitmentService::class,
            \HrManager\Services\WalletEventHandler::class,
            \HrManager\Services\CorpStatusService::class,
            \HrManager\Services\SeatConnectorService::class,
            \HrManager\Services\ZkillService::class,
            \HrManager\Services\CharacterRoleClassifier::class,
            \HrManager\Services\FcActivityService::class,
            \HrManager\Services\StructureIncidentService::class,
            \HrManager\Services\PublicCorpLookupService::class,
            \HrManager\Services\PurgeBoardService::class,
            \HrManager\Services\SeatSquadService::class,
        ] as $service) {
            $this->app->singleton($service);
        }

        $this->add_database_seeders();
    }

    /**
     * Manager Core PluginBridge capabilities exposed by HR Manager + persistent
     * EventBus subscriptions. All MC integration is class_exists-guarded so
     * the plugin runs standalone without MC.
     */
    private function registerPluginBridgeCapabilities()
    {
        if (!class_exists('ManagerCore\Services\PluginBridge')) {
            return;
        }

        try {
            $bridge = app(\ManagerCore\Services\PluginBridge::class);

            // hr.getAssessment — corp-scoped to prevent cross-corp data leaks
            $bridge->registerCapability('hr-manager', 'hr.getAssessment', function ($characterId, $callerCorporationId = null) {
                if ($callerCorporationId === null) return null;
                $charCorpId = \Illuminate\Support\Facades\DB::table('character_affiliations')
                    ->where('character_id', $characterId)
                    ->value('corporation_id');
                if ($charCorpId === null || (int) $charCorpId !== (int) $callerCorporationId) return null;
                return app(\HrManager\Services\AssessmentService::class)
                    ->getOrBuild($characterId, (int) $callerCorporationId);
            });

            $bridge->registerCapability('hr-manager', 'hr.getApplicationStatus', function ($characterId, $callerCorporationId = null) {
                if ($callerCorporationId === null) return null;
                return \HrManager\Models\Application::where('character_id', $characterId)
                    ->where('corporation_id', (int) $callerCorporationId)
                    ->latest('submitted_at')
                    ->first(['id', 'character_id', 'corporation_id', 'status', 'submitted_at', 'reviewed_at', 'decided_at']);
            });

            // Mining-event subscriber. v1.0.0 upgraded from invalidate-only to
            // persist-then-invalidate per the PINNED 2026-05-27 design.
            $bridge->registerCapability('hr-manager', 'hr.onMiningEvent', function ($eventName, $publisher, array $payload) {
                $characterId = $payload['character_id'] ?? null;

                // Persist the event for the player timeline (idempotent via
                // source_reference)
                app(\HrManager\Services\HistoryEventService::class)->record(
                    $eventName,
                    $payload,
                    [
                        'character_id'    => $characterId ? (int) $characterId : null,
                        'corporation_id'  => $payload['corporation_id'] ?? null,
                        'occurred_at'     => $payload['occurred_at'] ?? now(),
                        'source_plugin'   => $publisher,
                        'idempotency_key' => $payload['source_reference']
                            ?? $payload['event_id']
                            ?? null,
                    ]
                );

                // Then invalidate assessment cache so next read rebuilds with
                // the fresh signal
                if ($characterId) {
                    \HrManager\Models\MemberAssessment::where('character_id', (int) $characterId)
                        ->update(['cached_at' => null]);
                }
            });

            // CWM wallet-signal subscribers. Three classes of event:
            //   - member.contribution.stalled        (warning)
            //   - member.contribution.milestone      (positive — pure history)
            //   - member.tax.compliance_dropped      (critical)
            // The handler service has zero knowledge of CWM as a publisher;
            // it only contracts with the EventBus payload array shape.
            // IMPORTANT: MC's EventBus invokes a subscriber capability via
            // callOrFail($plugin, $cap, $eventName, $publisher, $payload)
            // — THREE positional args. A capability registered as
            // `fn (array $payload) => ...` binds the event-name STRING to
            // $payload and throws a TypeError on every dispatch (silently
            // — the error is caught + logged by the EventBus, the handler
            // never runs). Every event-handler capability MUST take the
            // 3-arg form. (The hr.onMiningEvent handler above already
            // does; these wallet + FC handlers were latently broken on
            // the single-arg form until 2026-06-08.)
            $bridge->registerCapability('hr-manager', 'hr.onWalletStalled',
                fn ($eventName, $publisher, array $payload) => app(\HrManager\Services\WalletEventHandler::class)->handleStalled($payload)
            );
            $bridge->registerCapability('hr-manager', 'hr.onWalletMilestone',
                fn ($eventName, $publisher, array $payload) => app(\HrManager\Services\WalletEventHandler::class)->handleMilestone($payload)
            );
            $bridge->registerCapability('hr-manager', 'hr.onWalletComplianceDropped',
                fn ($eventName, $publisher, array $payload) => app(\HrManager\Services\WalletEventHandler::class)->handleComplianceDropped($payload)
            );
            // Round-3 additions: contribution drop and unusual recipient.
            // Drop is character-scoped (nudges at_risk); unusual recipient
            // is corp-scoped (audit-only history event).
            $bridge->registerCapability('hr-manager', 'hr.onContributionDropDetected',
                fn ($eventName, $publisher, array $payload) => app(\HrManager\Services\WalletEventHandler::class)->handleContributionDropDetected($payload)
            );
            $bridge->registerCapability('hr-manager', 'hr.onUnusualRecipient',
                fn ($eventName, $publisher, array $payload) => app(\HrManager\Services\WalletEventHandler::class)->handleUnusualRecipient($payload)
            );

            // SeAT Broadcast FC activity. Records each broadcast into HR's
            // own hr_manager_fc_activity table; the FC profile (fleets led,
            // coverage window, cadence) is computed from that accumulated
            // table — HR never reads Broadcast's tables. EventBus-first,
            // forward-only. 3-arg signature per the note above.
            $bridge->registerCapability('hr-manager', 'hr.onBroadcastSent',
                fn ($eventName, $publisher, array $payload) => app(\HrManager\Services\FcActivityService::class)->record($payload)
            );

            // SeAT Broadcast formup scheduling (pings.formup.scheduled) — an FC
            // scheduling a fleet for a tactical event. A proactive PLANNING
            // signal (stronger than a reactive broadcast), accumulated into the
            // same fc_activity table with kind='formup'. 3-arg signature.
            $bridge->registerCapability('hr-manager', 'hr.onFormupScheduled',
                fn ($eventName, $publisher, array $payload) => app(\HrManager\Services\FcActivityService::class)->recordFormup($payload)
            );

            // Blueprint Manager request lifecycle (blueprint.request.*). One
            // wildcard subscription, one handler — accumulates the event onto
            // the requester's timeline + nudges the assessment cache. 3-arg
            // signature (the handler keys on $eventName to know the lifecycle
            // stage). Pull-side stats come via the blueprint.* capabilities.
            $bridge->registerCapability('hr-manager', 'hr.onBlueprintRequest',
                fn ($eventName, $publisher, array $payload) => app(\HrManager\Services\BlueprintActivityService::class)->recordEvent($eventName, $publisher, $payload)
            );

            // Structure Manager alerts (structure.alert.*). One handler
            // accumulates each incident (reinforcements / fuel-critical /
            // destroyed) into HR's own table for the Corp Health Structure
            // Health trend. 3-arg signature; the handler keys on $eventName
            // for the alert flavour. HR-side only: SM already publishes these.
            $bridge->registerCapability('hr-manager', 'hr.onStructureAlert',
                fn ($eventName, $publisher, array $payload) => app(\HrManager\Services\StructureIncidentService::class)->record($eventName, $publisher, $payload)
            );

            // Persistent EventBus subscription. Re-subscribes on every boot via
            // MC's updateOrCreate (subscriber_plugin, event_pattern, handler).
            if (class_exists('ManagerCore\Services\EventBus')) {
                $eventBus = app(\ManagerCore\Services\EventBus::class);
                $eventBus->subscribe(
                    'hr-manager',
                    'mining.*',
                    'hr.onMiningEvent',
                    ['queued' => false, 'priority' => 0]
                );

                // CWM wallet signals. Synchronous + priority 0 to match the
                // mining subscription; ordering across CWM events is
                // unimportant because each handler is keyed by character.
                $eventBus->subscribe(
                    'hr-manager',
                    'member.contribution.stalled',
                    'hr.onWalletStalled',
                    ['queued' => false, 'priority' => 0]
                );
                $eventBus->subscribe(
                    'hr-manager',
                    'member.contribution.milestone',
                    'hr.onWalletMilestone',
                    ['queued' => false, 'priority' => 0]
                );
                $eventBus->subscribe(
                    'hr-manager',
                    'member.tax.compliance_dropped',
                    'hr.onWalletComplianceDropped',
                    ['queued' => false, 'priority' => 0]
                );
                $eventBus->subscribe(
                    'hr-manager',
                    'member.contribution.drop_detected',
                    'hr.onContributionDropDetected',
                    ['queued' => false, 'priority' => 0]
                );
                $eventBus->subscribe(
                    'hr-manager',
                    'wallet.unusual_recipient_detected',
                    'hr.onUnusualRecipient',
                    ['queued' => false, 'priority' => 0]
                );

                // SeAT Broadcast FC activity (pings.broadcast.sent).
                // Forward-only accumulation into hr_manager_fc_activity.
                $eventBus->subscribe(
                    'hr-manager',
                    'pings.broadcast.sent',
                    'hr.onBroadcastSent',
                    ['queued' => false, 'priority' => 0]
                );

                // SeAT Broadcast formup planning (pings.formup.scheduled).
                $eventBus->subscribe(
                    'hr-manager',
                    'pings.formup.scheduled',
                    'hr.onFormupScheduled',
                    ['queued' => false, 'priority' => 0]
                );

                // Blueprint Manager request lifecycle. Wildcard covers
                // created / approved / rejected / fulfilled through one handler.
                $eventBus->subscribe(
                    'hr-manager',
                    'blueprint.request.*',
                    'hr.onBlueprintRequest',
                    ['queued' => false, 'priority' => 0]
                );

                // Structure Manager alerts (structure.alert.*). Wildcard covers
                // shield/armor reinforced, destroyed, fuel_critical, etc. through
                // one handler. Forward-only accumulation into
                // hr_manager_structure_incidents for the Structure Health trend.
                $eventBus->subscribe(
                    'hr-manager',
                    'structure.alert.*',
                    'hr.onStructureAlert',
                    ['queued' => false, 'priority' => 0]
                );

                // ===========================================================
                // TODO: Future EventBus wiring candidates
                // ===========================================================
                // The events below are already PUBLISHED by other plugins in
                // the suite (verified via MC's Topics registry) but HR doesn't
                // subscribe yet. Each one could enrich the player/corp
                // assessment cache, drive Corp Health classifier nudges, or
                // surface on the Member Profile timeline. None are blocking
                // for v1.0.x; document here so future-me has the candidate
                // list when fleshing out the assessment domain.
                //
                // From Mining Manager (mining.* — already subscribed via
                // wildcard above so these flow through hr.onMiningEvent;
                // the handler currently only acts on a subset — extend it):
                //   - mining.tax_created / .tax_paid / .tax_overdue / .invoice_sent
                //       → Per-character tax timeline; could roll up into a
                //         "tax health" sub-score on Corp Health
                //   - mining.theft_detected
                //       → Major signal for assessment + audit trail; the
                //         single highest-impact wiring on this list
                //   - mining.jackpot_detected
                //       → Positive history (was at a jackpot fleet)
                //   - mining.session_started / .session_ended
                //       → Activity timeline; volume + frequency stats
                //
                // From Mining Manager (mining.extraction_*):
                //   - mining.extraction_ready / .extraction_unstable / .extraction_expired
                //       → Who was around for the moon op; correlate with
                //         attendance on the Member Profile
                //
                // From Structure Manager (structure.alert.*): DONE. Subscribed
                // above for INCIDENT COUNTING (StructureIncidentService feeds the
                // Corp Health Structure Health trend: reinforcements / fuel-critical
                // / lost over a window).
                //   Still future: per-member DEFENSE PARTICIPATION (who acked /
                //   responded / formed up) needs a Pings correlation; the alert
                //   payload carries no responder identity, so this subscription
                //   alone can't attribute who showed up.
                //
                // From SeAT Broadcast (pings.*): DONE — both pings.broadcast.sent
                // (FC activity) and pings.formup.scheduled (FC planning /
                // organizer) are wired above into FcActivityService.
                //
                // From Buyback Manager (buyback.completed):
                //   - buyback.completed
                //       → Member used the buyback service; economic
                //         participation indicator
                //
                // Implementation pattern (mirror the existing subscriptions
                // above): one bridge capability registration via
                // PluginBridge + one EventBus::subscribe call. Handler
                // method on WalletEventHandler (or a new dedicated
                // FleetEventHandler / EconomicEventHandler service) that
                // writes to a per-character timeline table and nudges
                // the assessment cache. Each new wiring is independently
                // valuable — don't need to add them all at once.
            }
        } catch (\Exception $e) {
            Log::warning('[HR Manager] Could not register bridge capabilities: ' . $e->getMessage());
        }
    }

    private function add_publications()
    {
        $this->publishes([
            __DIR__ . '/Config/hr-manager.config.php' => config_path('hr-manager.php'),
        ], ['config', 'seat']);

        $this->publishes([
            __DIR__ . '/Resources/assets' => public_path('vendor/hr-manager'),
        ], ['public', 'seat']);

        // Publishable recruitment landing views — operators can override the
        // public landing templates by running `php artisan vendor:publish
        // --tag=hr-manager-recruit-views` and editing the copies in
        // resources/views/vendor/hr-manager/recruit/.
        $this->publishes([
            __DIR__ . '/Resources/views/recruit' => resource_path('views/vendor/hr-manager/recruit'),
        ], ['hr-manager-recruit-views']);
    }

    private function add_database_seeders()
    {
        $this->registerDatabaseSeeders([
            Database\Seeders\ScheduleSeeder::class,
        ]);
    }

    public function getName(): string
    {
        return 'HR Manager';
    }

    public function getPackageRepositoryUrl(): string
    {
        return 'https://github.com/MattFalahe/hr-manager';
    }

    public function getPackagistPackageName(): string
    {
        return 'hr-manager';
    }

    public function getPackagistVendorName(): string
    {
        return 'mattfalahe';
    }
}
