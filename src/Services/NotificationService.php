<?php

namespace HrManager\Services;

use HrManager\Models\Application;
use HrManager\Models\PlayerClassification;
use HrManager\Models\PlayerStatus;
use HrManager\Models\WebhookConfiguration;
use HrManager\Services\CharacterTitleService;
use Illuminate\Support\Facades\Log;
use Seat\Eveapi\Models\Corporation\CorporationInfo;

class NotificationService
{
    private WebhookService $webhookService;

    /** Memoized master webhook toggle (resolved once per request). */
    private ?bool $webhooksEnabled = null;

    public function __construct(WebhookService $webhookService)
    {
        $this->webhookService = $webhookService;
    }

    /**
     * The global "Enable Webhooks" master switch (Features tab). When off, no
     * automatic notification is sent regardless of per-webhook config. Manual
     * "Test" sends bypass this (they call WebhookService directly), so an
     * operator can still verify a URL while notifications are paused.
     */
    private function webhooksEnabled(): bool
    {
        if ($this->webhooksEnabled === null) {
            $this->webhooksEnabled = (bool) \HrManager\Models\Setting::getValue(
                'enable_webhooks',
                config('hr-manager.features.enable_webhooks', true)
            );
        }

        return $this->webhooksEnabled;
    }

    public function notifyApplicationSubmitted(Application $application): void
    {
        $application->loadMissing(['character', 'template']);

        $webhooks = WebhookConfiguration::enabled()
            ->forCorporation($application->corporation_id)
            ->where('notify_application_submitted', true)
            ->get();

        $characterName = $application->character->name ?? 'Unknown';

        foreach ($webhooks as $webhook) {
            $this->send($webhook, 'submitted', [
                'character_id' => $application->character_id,
                'description'  => "**{$characterName}** has submitted a new application.",
                'fields'       => [
                    ['name' => 'Applicant', 'value' => $characterName, 'inline' => true],
                    ['name' => 'Status', 'value' => 'Applied', 'inline' => true],
                    ['name' => 'Template', 'value' => $application->template->name ?? 'N/A', 'inline' => true],
                ],
            ]);
        }
    }

    public function notifyStatusChange(Application $application, string $oldStatus, string $newStatus, ?int $actorUserId = null, ?string $comment = null): void
    {
        $application->loadMissing(['character']);

        $webhooks = WebhookConfiguration::enabled()
            ->forCorporation($application->corporation_id)
            ->where(function ($q) use ($newStatus) {
                $q->where('notify_status_change', true);
                if ($newStatus === 'accepted') {
                    $q->orWhere('notify_application_accepted', true);
                }
                if ($newStatus === 'rejected') {
                    $q->orWhere('notify_application_rejected', true);
                }
            })
            ->get();

        $characterName = $application->character->name ?? $this->characterName((int) $application->character_id);
        $event = in_array($newStatus, ['accepted', 'rejected']) ? $newStatus : 'status_change';

        // Who made the change (resolve the SeAT user_id to their main char).
        $actorName = $actorUserId
            ? (app(NameResolutionService::class)->getUserNames([$actorUserId])[$actorUserId] ?? ('User #' . $actorUserId))
            : null;

        // Human-readable status labels (e.g. under_review -> "Under review") used
        // in BOTH the embed description and the fields, so the description never
        // shows raw status keys like "under_review -> rejected".
        $oldLabel = ucfirst(str_replace('_', ' ', $oldStatus));
        $newLabel = ucfirst(str_replace('_', ' ', $newStatus));

        foreach ($webhooks as $webhook) {
            $fields = [
                ['name' => 'Applicant', 'value' => $characterName, 'inline' => true],
                ['name' => 'Old Status', 'value' => $oldLabel, 'inline' => true],
                ['name' => 'New Status', 'value' => $newLabel, 'inline' => true],
            ];
            if ($actorName) {
                $fields[] = ['name' => 'Changed by', 'value' => $actorName, 'inline' => true];
            }
            // Note / reason / comment the recruiter left on the transition.
            $note = trim((string) $comment);
            if ($note !== '') {
                $fields[] = ['name' => 'Note', 'value' => mb_substr($note, 0, 1000), 'inline' => false];
            }

            $this->send($webhook, $event, [
                'character_id' => $application->character_id,
                'description'  => "**{$characterName}**'s application status changed: **{$oldLabel}** -> **{$newLabel}**"
                    . ($actorName ? " by **{$actorName}**" : ''),
                'fields'       => $fields,
            ]);
        }
    }

    /**
     * Critical: an L3 director just crossed into inactive/dead_weight.
     * Fires to every webhook with notify_inactive_director enabled for
     * the affected corp.
     */
    public function notifyInactiveDirector(int $userId, int $corporationId, PlayerClassification $classification): void
    {
        $webhooks = WebhookConfiguration::enabled()
            ->forCorporation($corporationId)
            ->where('notify_inactive_director', true)
            ->get();

        if ($webhooks->isEmpty()) {
            return;
        }

        $mainCharId = $this->mainCharacterIdFor($userId);
        $mainName = $mainCharId ? $this->characterName($mainCharId) : ('User #' . $userId);
        $corpName = CorporationInfo::where('corporation_id', $corporationId)->value('name') ?? '#' . $corporationId;

        foreach ($webhooks as $webhook) {
            $this->send($webhook, 'inactive_director', [
                'character_id' => $mainCharId,
                'description'  => "[CRITICAL] Director **{$mainName}** in **{$corpName}** is inactive for {$classification->days_inactive} days (threshold {$classification->threshold_days}d). Corp survival depends on active directors.",
                'fields'       => [
                    ['name' => 'Director', 'value' => $mainName, 'inline' => true],
                    ['name' => 'Days Inactive', 'value' => (string) $classification->days_inactive, 'inline' => true],
                    ['name' => 'Threshold', 'value' => $classification->threshold_days . 'd', 'inline' => true],
                ],
            ]);
        }
    }

    /**
     * Purge milestone notification (T-7d / T-3d / T-48h / T-0). The T-48h
     * notification is the headline — it lists every in-corp character on
     * the account so a human can act before the deadline.
     */
    public function notifyPurgeReminder(PlayerStatus $status, string $milestone, array $charactersInCorp): void
    {
        $webhooks = WebhookConfiguration::enabled()
            ->forCorporation($status->corporation_id)
            ->where('notify_purge_reminder', true)
            ->get();

        if ($webhooks->isEmpty()) {
            return;
        }

        $userId = $status->user_id;
        $mainCharId = $this->mainCharacterIdFor($userId);
        $mainName = $mainCharId ? $this->characterName($mainCharId) : ('User #' . $userId);
        $corpName = CorporationInfo::where('corporation_id', $status->corporation_id)->value('name') ?? '#' . $status->corporation_id;
        $scheduledAt = $status->purge_scheduled_for?->format('Y-m-d') ?? 'unscheduled';

        $milestoneLabel = match ($milestone) {
            't7'  => 'T-7 days',
            't3'  => 'T-3 days',
            't48' => 'T-48 hours',
            't0'  => 'TODAY (T-0)',
            default => $milestone,
        };

        $charsField = empty($charactersInCorp)
            ? 'No characters currently in corp.'
            : implode(', ', array_map(fn($c) => $c['name'], $charactersInCorp));

        // Pull consolidated in-game title/role snapshot across all of
        // this user's alts in the corp so the message lists exactly
        // what the operator needs to strip in the corp management
        // window. EVE applies a 24-hour cooldown on role removal, so
        // the T-48h milestone is the latest reasonable moment to start.
        $charIds = array_map(fn($c) => (int) ($c['character_id'] ?? 0), $charactersInCorp);
        $charIds = array_values(array_filter($charIds));
        $titleSnapshot = !empty($charIds)
            ? app(CharacterTitleService::class)->snapshotForUser($charIds, (int) $status->corporation_id)
            : ['titles' => [], 'roles' => [], 'has_anything' => false];

        $highImpactRoles = array_values(array_intersect($titleSnapshot['roles'], [
            'Director', 'Personnel_Manager', 'Accountant',
            'Junior_Accountant', 'Diplomat', 'Security_Officer',
        ]));
        $rolesField = empty($highImpactRoles)
            ? 'No high-impact in-game roles detected.'
            : implode(', ', array_map(fn($r) => str_replace('_', ' ', $r), $highImpactRoles));
        $titlesField = empty($titleSnapshot['titles'])
            ? 'No in-game titles detected.'
            : implode(', ', array_map(fn($t) => $t['name'], $titleSnapshot['titles']));

        // Per-character strip list. EVE kicks + strips roles ONE CHARACTER at a
        // time, so list each alt's own roles/titles, not just the account
        // aggregate above. (Discord field values cap at 1024 chars.)
        $charNames = [];
        foreach ($charactersInCorp as $c) {
            $charNames[(int) ($c['character_id'] ?? 0)] = $c['name'] ?? ('#' . ($c['character_id'] ?? 0));
        }
        $perCharLines = [];
        foreach ($titleSnapshot['by_character'] ?? [] as $cid => $snap) {
            $bits = [];
            if (!empty($snap['roles'])) {
                $bits[] = implode(', ', array_map(fn($r) => str_replace('_', ' ', $r), $snap['roles']));
            }
            if (!empty($snap['titles'])) {
                $bits[] = 'titles: ' . implode(', ', array_map(fn($t) => $t['name'], $snap['titles']));
            }
            if (!empty($bits)) {
                $perCharLines[] = '**' . ($charNames[(int) $cid] ?? ('#' . $cid)) . '**: ' . implode(' | ', $bits);
            }
        }
        $perCharField = mb_substr(implode("\n", $perCharLines), 0, 1024);

        // Cooldown reminder is loudest at T-48h (latest moment to start
        // stripping for the 24h removal cooldown to clear before T-0).
        // Quiet at T-7/T-3 (still informational), repeated at T-0.
        $cooldownLine = match ($milestone) {
            't48'   => " [ROLE STRIP] EVE applies a 24-hour cooldown on in-game role removal. Strip titles and roles within the next 24h or they will still be effective at kick time.",
            't0'    => " [ROLE STRIP] Verify all in-game titles and roles have been removed before the kick. Anything stripped less than 24h ago is still active.",
            default => '',
        };

        $description = match ($milestone) {
            't48'   => "[T-48h] **{$mainName}** is scheduled for purge on {$scheduledAt}. In-game kick required within 48h.{$cooldownLine}",
            't0'    => "[TODAY] **{$mainName}** purge day. Execute the in-game kick.{$cooldownLine}",
            default => "[{$milestoneLabel}] **{$mainName}** is scheduled for purge on {$scheduledAt}.{$cooldownLine}",
        };

        $fields = [
            ['name' => 'Player', 'value' => $mainName, 'inline' => true],
            ['name' => 'Corp', 'value' => $corpName, 'inline' => true],
            ['name' => 'Scheduled', 'value' => $scheduledAt, 'inline' => true],
            ['name' => 'Characters in corp', 'value' => $charsField, 'inline' => false],
        ];

        // Only attach title/role fields when at least one milestone
        // actually needs the operator to act on them (T-48h / T-0) AND
        // the snapshot found something. Keeps T-7/T-3 messages tight.
        if (in_array($milestone, ['t48', 't0'], true) && $titleSnapshot['has_anything']) {
            $fields[] = ['name' => 'High-impact roles to strip', 'value' => $rolesField, 'inline' => false];
            $fields[] = ['name' => 'In-game titles to strip',   'value' => $titlesField, 'inline' => false];
            if (!empty($perCharLines)) {
                $fields[] = ['name' => 'Per character (kick + strip each individually)', 'value' => $perCharField, 'inline' => false];
            }
        }

        $fields[] = ['name' => 'Reason', 'value' => $status->reason ?: '-', 'inline' => false];

        foreach ($webhooks as $webhook) {
            $this->send($webhook, 'purge_reminder', [
                'character_id' => $mainCharId,
                'description'  => $description,
                'fields'       => $fields,
            ]);
        }
    }

    /**
     * Fire the moment a director changes a player's HR status: marks them
     * on LOA, marks them for purge, or clears the status (LOA ended / purge
     * cancelled). Immediate (called inline from the action), so the team
     * hears about it within the request, not on a cron. Routed to the
     * notify_player_status webhook category for the player's corp.
     *
     * @param string $event one of: loa_marked, marked_for_purge, status_cleared
     */
    public function notifyPlayerStatusChange(PlayerStatus $status, string $event, ?int $actorUserId = null): void
    {
        $webhooks = WebhookConfiguration::enabled()
            ->forCorporation($status->corporation_id)
            ->where('notify_player_status', true)
            ->get();

        if ($webhooks->isEmpty()) {
            return;
        }

        $userId = (int) $status->user_id;
        $mainCharId = $this->mainCharacterIdFor($userId);
        $mainName = $mainCharId ? $this->characterName($mainCharId) : ('User #' . $userId);
        $corpName = CorporationInfo::where('corporation_id', $status->corporation_id)->value('name') ?? ('#' . $status->corporation_id);
        $actorName = null;
        if ($actorUserId) {
            $actorMainId = $this->mainCharacterIdFor((int) $actorUserId);
            $actorName = $actorMainId ? $this->characterName((int) $actorMainId) : ('User #' . $actorUserId);
        }

        $loaUntil = $status->loa_until?->format('Y-m-d');
        $purgeOn  = $status->purge_scheduled_for?->format('Y-m-d');

        $description = match ($event) {
            'loa_marked'       => "[LOA] **{$mainName}** is now on leave of absence" . ($loaUntil ? " until **{$loaUntil}**" : '') . ". The activity classifier will hold them as active while on LOA.",
            'marked_for_purge' => "[PURGE SCHEDULED] **{$mainName}** has been marked for purge" . ($purgeOn ? ", scheduled for **{$purgeOn}**" : ' (no date set)') . ". Purge reminders will follow as the date nears.",
            'status_cleared'   => "[STATUS CLEARED] **{$mainName}**'s LOA / purge status has been cleared. They're back to normal classification.",
            default            => "[STATUS] **{$mainName}**'s player status changed.",
        };

        $fields = [
            ['name' => 'Player', 'value' => $mainName, 'inline' => true],
            ['name' => 'Corp',   'value' => $corpName, 'inline' => true],
        ];
        if ($event === 'loa_marked' && $loaUntil) {
            $fields[] = ['name' => 'LOA until', 'value' => $loaUntil, 'inline' => true];
        }
        if ($event === 'marked_for_purge') {
            $fields[] = ['name' => 'Scheduled', 'value' => $purgeOn ?: 'unscheduled', 'inline' => true];
        }
        if ($actorName) {
            $fields[] = ['name' => 'By', 'value' => $actorName, 'inline' => true];
        }
        $fields[] = ['name' => 'Reason', 'value' => $status->reason ?: '-', 'inline' => false];

        foreach ($webhooks as $webhook) {
            $this->send($webhook, 'player_status_' . $event, [
                'character_id' => $mainCharId,
                'description'  => $description,
                'fields'       => $fields,
            ]);
        }
    }

    /**
     * A known person joined the corp: an existing member's alt, or someone with
     * a valid application. The message names the main. Routes to
     * notify_member_joined.
     *
     * @param string $type 'known_alt' | 'applied'
     */
    public function notifyMemberJoined(int $corporationId, int $characterId, string $type, ?int $mainCharacterId = null): void
    {
        $webhooks = WebhookConfiguration::enabled()
            ->forCorporation($corporationId)
            ->where('notify_member_joined', true)
            ->get();
        if ($webhooks->isEmpty()) {
            return;
        }

        $charName = $this->characterName($characterId);
        $corpName = CorporationInfo::where('corporation_id', $corporationId)->value('name') ?? ('#' . $corporationId);
        $mainName = ($mainCharacterId && $mainCharacterId !== $characterId) ? $this->characterName($mainCharacterId) : null;

        if ($type === 'known_alt' && $mainName) {
            $description = "[MEMBER JOINED] **{$charName}** joined **{$corpName}** — an alt of **{$mainName}** (an existing member).";
        } elseif ($mainName) {
            $description = "[MEMBER JOINED] **{$charName}** joined **{$corpName}** (applied; account main: **{$mainName}**).";
        } else {
            $description = "[MEMBER JOINED] **{$charName}** joined **{$corpName}** (applied).";
        }

        $fields = [
            ['name' => 'Character', 'value' => $charName, 'inline' => true],
            ['name' => 'Main', 'value' => $mainName ?: $charName, 'inline' => true],
            ['name' => 'Corp', 'value' => $corpName, 'inline' => true],
            ['name' => 'Route', 'value' => $type === 'known_alt' ? 'Alt of a current member' : 'Valid application', 'inline' => false],
        ];

        foreach ($webhooks as $webhook) {
            $this->send($webhook, 'member_joined', [
                'character_id' => $mainCharacterId ?: $characterId,
                'description'  => $description,
                'fields'       => $fields,
            ]);
        }
    }

    /**
     * A NEW person joined the corp with no valid application — the security
     * flag. Forward-only (current members are never flagged). Routes to
     * notify_join_no_application.
     */
    public function notifyJoinNoApplication(int $corporationId, int $characterId, ?int $mainCharacterId = null): void
    {
        $webhooks = WebhookConfiguration::enabled()
            ->forCorporation($corporationId)
            ->where('notify_join_no_application', true)
            ->get();
        if ($webhooks->isEmpty()) {
            return;
        }

        $charName = $this->characterName($characterId);
        $corpName = CorporationInfo::where('corporation_id', $corporationId)->value('name') ?? ('#' . $corporationId);
        $mainName = ($mainCharacterId && $mainCharacterId !== $characterId) ? $this->characterName($mainCharacterId) : null;

        $description = "[NO APPLICATION] **{$charName}** joined **{$corpName}** with **no valid HR application**"
            . ($mainName ? " (account main: **{$mainName}**)" : '')
            . '. Verify this join was expected.';

        $fields = [
            ['name' => 'Character', 'value' => $charName, 'inline' => true],
            ['name' => 'Corp', 'value' => $corpName, 'inline' => true],
        ];
        if ($mainName) {
            $fields[] = ['name' => 'Account main', 'value' => $mainName, 'inline' => true];
        }
        $fields[] = ['name' => 'zKillboard', 'value' => "https://zkillboard.com/character/{$characterId}/", 'inline' => false];

        foreach ($webhooks as $webhook) {
            $this->send($webhook, 'join_no_application', [
                'character_id' => $characterId,
                'description'  => $description,
                'fields'       => $fields,
            ]);
        }
    }

    /**
     * A character left the corp. Routes to notify_member_left, noting whether
     * the account still has another character in the corp.
     */
    public function notifyMemberLeft(int $corporationId, int $characterId, ?int $mainCharacterId = null, bool $playerStillPresent = false): void
    {
        $webhooks = WebhookConfiguration::enabled()
            ->forCorporation($corporationId)
            ->where('notify_member_left', true)
            ->get();
        if ($webhooks->isEmpty()) {
            return;
        }

        $charName = $this->characterName($characterId);
        $corpName = CorporationInfo::where('corporation_id', $corporationId)->value('name') ?? ('#' . $corporationId);
        $mainName = ($mainCharacterId && $mainCharacterId !== $characterId) ? $this->characterName($mainCharacterId) : null;

        if ($mainName && !$playerStillPresent) {
            $description = "[MEMBER LEFT] **{$charName}** left **{$corpName}** — this was **{$mainName}**'s last character in the corp.";
        } elseif ($mainName && $playerStillPresent) {
            $description = "[MEMBER LEFT] **{$charName}** left **{$corpName}** — an alt of **{$mainName}**, who still has characters in the corp.";
        } else {
            $description = "[MEMBER LEFT] **{$charName}** left **{$corpName}**.";
        }

        $fields = [
            ['name' => 'Character', 'value' => $charName, 'inline' => true],
            ['name' => 'Main', 'value' => $mainName ?: $charName, 'inline' => true],
            ['name' => 'Corp', 'value' => $corpName, 'inline' => true],
        ];

        foreach ($webhooks as $webhook) {
            $this->send($webhook, 'member_left', [
                'character_id' => $characterId,
                'description'  => $description,
                'fields'       => $fields,
            ]);
        }
    }

    /**
     * CWM contribution-stalled notification. character-scoped (the upstream
     * event is per-character), with the character's user resolved for the
     * main-character portrait.
     */
    public function notifyWalletStalled(int $characterId, int $corporationId, string $reason, array $payload = []): void
    {
        $webhooks = WebhookConfiguration::enabled()
            ->forCorporation($corporationId)
            ->where('notify_wallet_stalled', true)
            ->get();
        if ($webhooks->isEmpty()) {
            return;
        }

        $charName = $this->characterName($characterId);

        foreach ($webhooks as $webhook) {
            $this->send($webhook, 'wallet_stalled', [
                'character_id' => $characterId,
                'description'  => "[Wallet] **{$charName}** wallet contributions stalled. {$reason}",
                'fields'       => [
                    ['name' => 'Character', 'value' => $charName, 'inline' => true],
                    ['name' => 'Longest gap', 'value' => ($payload['longest_gap_months'] ?? '?') . 'mo', 'inline' => true],
                    ['name' => 'Last active', 'value' => $payload['last_active_period'] ?? 'unknown', 'inline' => true],
                ],
            ]);
        }
    }

    /**
     * CWM tax-compliance-dropped notification — critical severity. Per-webhook
     * notify_wallet_compliance_dropped toggle.
     */
    public function notifyWalletComplianceDropped(int $characterId, int $corporationId, string $reason, array $payload = []): void
    {
        $webhooks = WebhookConfiguration::enabled()
            ->forCorporation($corporationId)
            ->where('notify_wallet_compliance_dropped', true)
            ->get();
        if ($webhooks->isEmpty()) {
            return;
        }

        $charName = $this->characterName($characterId);

        foreach ($webhooks as $webhook) {
            $this->send($webhook, 'wallet_compliance_dropped', [
                'character_id' => $characterId,
                'description'  => "[CRITICAL] **{$charName}** tax compliance dropped. {$reason}",
                'fields'       => [
                    ['name' => 'Character', 'value' => $charName, 'inline' => true],
                    ['name' => 'Compliance %', 'value' => ($payload['compliance_pct'] ?? '?') . '%', 'inline' => true],
                    ['name' => 'Overdue cycles', 'value' => (string) ($payload['consecutive_overdue'] ?? '?'), 'inline' => true],
                ],
            ]);
        }
    }

    /**
     * CWM milestone notification — positive event ("1B contributor club" etc).
     * Per-webhook notify_wallet_milestone toggle.
     */
    public function notifyWalletMilestone(int $characterId, int $corporationId, array $payload = []): void
    {
        $webhooks = WebhookConfiguration::enabled()
            ->forCorporation($corporationId)
            ->where('notify_wallet_milestone', true)
            ->get();
        if ($webhooks->isEmpty()) {
            return;
        }

        $charName = $this->characterName($characterId);
        $milestoneIsk = $this->formatIsk($payload['milestone_isk'] ?? null);
        $lifetimeIsk  = $this->formatIsk($payload['lifetime_total'] ?? null);

        foreach ($webhooks as $webhook) {
            $this->send($webhook, 'wallet_milestone', [
                'character_id' => $characterId,
                'description'  => "**{$charName}** reached a contribution milestone: {$milestoneIsk} ISK (lifetime {$lifetimeIsk} ISK)",
                'fields'       => [
                    ['name' => 'Character', 'value' => $charName, 'inline' => true],
                    ['name' => 'Milestone', 'value' => $milestoneIsk, 'inline' => true],
                    ['name' => 'Lifetime total', 'value' => $lifetimeIsk, 'inline' => true],
                ],
            ]);
        }
    }

    /**
     * Fire a webhook on a new watchlist detection. Three detection
     * types each get a slightly different framing:
     *
     *   joined_managed_corp     -> "blacklisted char joined our corp"
     *                              high-severity alert
     *   joined_managed_alliance -> "blacklisted char joined a corp in
     *                              our alliance" — warning
     *   external_corp_change    -> "blacklisted char moved corps
     *                              (per public ESI)" — informational
     */
    public function notifyWatchlistDetection(\HrManager\Models\WatchlistEntry $entry, string $detectionType, ?int $detectedCorpId, ?int $detectedAllianceId, ?int $previousCorpId): void
    {
        $charName = $entry->character_name ?: $this->characterName((int) $entry->character_id);
        $corpName = $detectedCorpId
            ? (CorporationInfo::where('corporation_id', $detectedCorpId)->value('name') ?? '#' . $detectedCorpId)
            : null;
        $prevCorpName = $previousCorpId
            ? (CorporationInfo::where('corporation_id', $previousCorpId)->value('name') ?? '#' . $previousCorpId)
            : null;

        // Resolve the target corp(s) this notification should go to.
        // Detection happens on a specific corp — that corp gets the
        // alert (or the alliance scope falls through to every corp in
        // the alliance). Always fall back to the entry's scope so
        // global / alliance entries still notify.
        $corpsForNotification = [];
        if ($detectedCorpId !== null) {
            $corpsForNotification[] = $detectedCorpId;
        }
        if ($entry->scope_corporation_id !== null) {
            $corpsForNotification[] = (int) $entry->scope_corporation_id;
        }
        $corpsForNotification = array_unique($corpsForNotification);

        if (empty($corpsForNotification)) {
            return;
        }

        $webhooks = WebhookConfiguration::enabled()
            ->whereIn('corporation_id', $corpsForNotification)
            ->where(function ($q) {
                $q->where('notify_inactive_director', true)
                  ->orWhere('notify_wallet_compliance_dropped', true);
            })
            ->get();

        if ($webhooks->isEmpty()) {
            return;
        }

        $severityLabel = strtoupper($entry->severity ?? 'medium');

        $description = match ($detectionType) {
            'joined_managed_corp'     => "[BLACKLIST ALERT · {$severityLabel}] **{$charName}** has joined **{$corpName}**. This character is on the blacklist with reason: {$entry->reason}",
            'joined_managed_alliance' => "[BLACKLIST WARNING · {$severityLabel}] **{$charName}** has joined **{$corpName}** which is in your alliance. Be vigilant. Original reason: {$entry->reason}",
            'external_corp_change'    => "[BLACKLIST INFO · {$severityLabel}] **{$charName}** has moved from **{$prevCorpName}** to **{$corpName}** (per public ESI). They remain on the blacklist for: {$entry->reason}",
            default                   => "[BLACKLIST] **{$charName}** matched watchlist entry: {$entry->reason}",
        };

        $fields = [
            ['name' => 'Character', 'value' => $charName, 'inline' => true],
            ['name' => 'Severity',  'value' => $severityLabel, 'inline' => true],
        ];
        if ($corpName !== null) {
            $fields[] = ['name' => 'Detected in corp', 'value' => $corpName, 'inline' => true];
        }
        if ($prevCorpName !== null) {
            $fields[] = ['name' => 'Previous corp', 'value' => $prevCorpName, 'inline' => true];
        }
        $fields[] = ['name' => 'Reason on file', 'value' => $entry->reason ?: '-', 'inline' => false];

        foreach ($webhooks as $webhook) {
            $this->send($webhook, 'watchlist_detection', [
                'character_id' => (int) $entry->character_id,
                'description'  => $description,
                'fields'       => $fields,
            ]);
        }
    }

    /**
     * Fire when an intel-flagged character is found inside a corp the
     * operator watches (the note's scope corp, or any tracked corp for
     * a global note). Lighter-weight sibling of the blacklist corp-match
     * alert: intel can be positive OR negative, so the copy is neutral
     * ("an intel note exists for this member"), and the recipient is the
     * detected corp's webhook. Routed via the same security/compliance
     * webhook categories the watchlist uses.
     */
    public function notifyIntelInScopeCorp(\HrManager\Models\IntelNote $note, int $detectedCorpId): void
    {
        $charName = $note->character_name ?: $this->characterName((int) $note->character_id);
        $corpName = CorporationInfo::where('corporation_id', $detectedCorpId)->value('name') ?? ('#' . $detectedCorpId);

        $webhooks = WebhookConfiguration::enabled()
            ->forCorporation($detectedCorpId)
            ->where(function ($q) {
                $q->where('notify_inactive_director', true)
                  ->orWhere('notify_wallet_compliance_dropped', true);
            })
            ->get();

        if ($webhooks->isEmpty()) {
            return;
        }

        $tags = is_array($note->tags) ? array_filter($note->tags) : [];
        $tagLabel = !empty($tags) ? implode(', ', $tags) : '-';
        $excerpt = trim((string) $note->body);
        if (mb_strlen($excerpt) > 280) {
            $excerpt = mb_substr($excerpt, 0, 277) . '...';
        }

        $description = "[INTEL MATCH] An intel note is on file for **{$charName}**, who is currently a member of **{$corpName}**. Review the note before any role or access decisions.";

        $fields = [
            ['name' => 'Character', 'value' => $charName, 'inline' => true],
            ['name' => 'In corp',   'value' => $corpName, 'inline' => true],
            ['name' => 'Tags',      'value' => $tagLabel, 'inline' => true],
            ['name' => 'Note',      'value' => $excerpt !== '' ? $excerpt : '-', 'inline' => false],
        ];

        foreach ($webhooks as $webhook) {
            $this->send($webhook, 'intel_scope_match', [
                'character_id' => (int) $note->character_id,
                'description'  => $description,
                'fields'       => $fields,
            ]);
        }
    }

    /**
     * Fire a webhook on SeAT refresh-token revocation. Treated as a
     * security alert — high severity. Reuses the wallet-compliance
     * webhook category since both signal "this person became a risk".
     */
    public function notifyTokenRevoked(int $userId, int $characterId, int $corporationId, string $charName): void
    {
        $webhooks = WebhookConfiguration::enabled()
            ->forCorporation($corporationId)
            ->where('notify_token_revoked', true)
            ->get();

        if ($webhooks->isEmpty()) {
            return;
        }

        $corpName = CorporationInfo::where('corporation_id', $corporationId)
            ->value('name') ?? ('#' . $corporationId);

        foreach ($webhooks as $webhook) {
            $this->send($webhook, 'token_revoked', [
                'character_id' => $characterId,
                'description'  => "[SECURITY] **{$charName}** revoked their SeAT refresh token. Visibility into this character is now lost while they may still hold corp access. Security-policy purge has been scheduled if enabled.",
                'fields'       => [
                    ['name' => 'Character', 'value' => $charName, 'inline' => true],
                    ['name' => 'Corp', 'value' => $corpName, 'inline' => true],
                    ['name' => 'SeAT user', 'value' => '#' . $userId, 'inline' => true],
                ],
            ]);
        }
    }

    /**
     * Periodic token-coverage digest for one corp. Opt-in per webhook
     * (notify_token_coverage, default off). Summarises the corp's token + scope
     * health and names who needs attention. No-op when no webhook subscribes or
     * the corp has no roster.
     */
    public function notifyTokenCoverage(int $corporationId): void
    {
        $webhooks = WebhookConfiguration::enabled()
            ->forCorporation($corporationId)
            ->where('notify_token_coverage', true)
            ->get();

        if ($webhooks->isEmpty()) {
            return;
        }

        $health = app(\HrManager\Services\TokenHealthService::class)->corpTokenHealth($corporationId);
        if (!($health['available'] ?? false) || ($health['total'] ?? 0) === 0) {
            return;
        }

        $corpName = CorporationInfo::where('corporation_id', $corporationId)->value('name') ?? ('#' . $corporationId);
        $pct = $health['total'] > 0 ? (int) round($health['valid'] / $health['total'] * 100) : 0;

        $sample = function (array $list, int $n = 15): string {
            $names = array_map(fn ($m) => $m['name'], array_slice($list, 0, $n));
            $extra = count($list) - count($names);
            $text  = implode(', ', $names);
            if ($extra > 0) {
                $text .= " (+{$extra} more)";
            }

            return $text !== '' ? $text : '-';
        };

        $fields = [
            ['name' => 'Corp', 'value' => $corpName, 'inline' => true],
            ['name' => 'Valid', 'value' => $health['valid'] . ' / ' . $health['total'] . " ({$pct}%)", 'inline' => true],
        ];
        if ($health['requirement_active']) {
            $fields[] = ['name' => 'Missing scopes', 'value' => (string) $health['insufficient'], 'inline' => true];
        }
        $fields[] = ['name' => 'Lost', 'value' => $health['lost'] . ($health['lost_recent'] > 0 ? " (+{$health['lost_recent']} recent)" : ''), 'inline' => true];
        $fields[] = ['name' => 'Never linked', 'value' => (string) $health['never_linked'], 'inline' => true];

        if ($health['requirement_active'] && !empty($health['lists']['insufficient'])) {
            $fields[] = ['name' => 'Members missing scopes', 'value' => $sample($health['lists']['insufficient']), 'inline' => false];
        }
        if (!empty($health['lists']['lost'])) {
            $fields[] = ['name' => 'Token lost', 'value' => $sample($health['lists']['lost']), 'inline' => false];
        }

        $profileNote = $health['requirement_active']
            ? "Measured against profile **{$health['profile_name']}**."
            : 'Token existence only (no requirement profile set).';

        foreach ($webhooks as $webhook) {
            $this->send($webhook, 'token_coverage', [
                'character_id' => null,
                'description'  => "Token coverage for **{$corpName}**: **{$health['valid']}/{$health['total']}** members hold a valid token ({$pct}%). {$profileNote}",
                'fields'       => $fields,
            ]);
        }
    }

    private function characterName(int $characterId): string
    {
        // Resolve through HR's shared NameResolutionService (character_infos
        // -> universe_names -> bulk /universe/names/ ESI -> zKill) so
        // notifications about UNREGISTERED members (CWM fires wallet events for
        // every corp member, not just authed ones) show a real name instead of
        // a bare #id. Falls back to #id only when no source can resolve it.
        return app(NameResolutionService::class)->getCharacterName($characterId)
            ?? ('#' . $characterId);
    }

    private function formatIsk($value): string
    {
        if ($value === null || !is_numeric($value)) return '?';
        $abs = abs((float) $value);
        if ($abs >= 1e12) return number_format($value / 1e12, 2) . 'T';
        if ($abs >= 1e9)  return number_format($value / 1e9, 2)  . 'B';
        if ($abs >= 1e6)  return number_format($value / 1e6, 2)  . 'M';
        if ($abs >= 1e3)  return number_format($value / 1e3, 2)  . 'k';
        return number_format($value, 0);
    }

    private function send(WebhookConfiguration $webhook, string $event, array $data): void
    {
        // Master switch: when webhooks are disabled, send nothing. Every
        // notify* method funnels through here, so this single guard covers
        // them all.
        if (!$this->webhooksEnabled()) {
            return;
        }

        try {
            if ($webhook->type === 'discord') {
                $embed = $this->webhookService->buildApplicationEmbed($event, $data);
                $this->webhookService->sendDiscordWebhook($webhook, $embed);
            } elseif ($webhook->type === 'slack') {
                $this->webhookService->sendSlackWebhook($webhook, [
                    'text' => $data['description'] ?? "HR Manager: {$event}",
                ]);
            }
        } catch (\Exception $e) {
            Log::error("[HR Manager] Notification failed: {$e->getMessage()}");
        }
    }

    private function mainCharacterIdFor(int $userId): ?int
    {
        $user = \Seat\Web\Models\User::find($userId);
        return $user?->main_character_id;
    }
}
