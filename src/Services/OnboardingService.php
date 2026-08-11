<?php

namespace HrManager\Services;

use HrManager\Models\OnboardingTemplate;
use HrManager\Models\OnboardingWelcome;
use HrManager\Models\Setting;
use HrManager\Models\WebhookConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Seat\Eveapi\Models\Corporation\CorporationInfo;

/**
 * Member onboarding welcome (opt-in). A genuinely new person joining the corp
 * queues a member-facing welcome that fires after a delay, so SeAT Connector has
 * time to assign the Discord role that lets them see the channel before the ping
 * lands. The message @-mentions the new member and renders a per-corp Markdown
 * template (with a hardcoded casual default), plus the operator's new-member-care
 * @-mention roles.
 *
 * Delivery is a plain-content Discord webhook post (mentions only ping from
 * `content`, never an embed). Connector-centric: without a resolvable Discord
 * identity the welcome still posts, just names the member instead of pinging.
 */
class OnboardingService
{
    public const SETTING_ENABLED       = 'onboarding_welcome_enabled';
    public const SETTING_DELAY_MINUTES = 'onboarding_welcome_delay_minutes';

    /**
     * How long a welcome may keep waiting for its preconditions (a webhook to
     * exist, SeAT to sync, the member to link Discord) before it is abandoned.
     * Long enough to survive a weekend of misconfiguration; short enough that
     * nobody gets greeted a month after joining.
     */
    private const MAX_DEFER_DAYS = 7;

    public function isEnabled(): bool
    {
        return (bool) Setting::getValue(self::SETTING_ENABLED, false);
    }

    /**
     * Is this corp running onboarding? The global toggle arms the feature; a
     * corp then has to be turned on individually.
     *
     * OPT-IN: a corp with no template row is OFF. Flipping the master switch
     * shouldn't quietly start greeting new members of every corp HR can see —
     * an operator picks which corps run onboarding, and the rest stay silent.
     */
    public function isEnabledForCorp(int $corporationId): bool
    {
        if (!Schema::hasTable('hr_manager_onboarding_templates')
            || !Schema::hasColumn('hr_manager_onboarding_templates', 'is_enabled')) {
            return false;
        }

        $row = OnboardingTemplate::where('corporation_id', $corporationId)->first();

        return $row ? (bool) $row->is_enabled : false;
    }

    /** Delay from join to welcome, in minutes. Default 30, capped at 24h. */
    public function delayMinutes(): int
    {
        $m = (int) Setting::getValue(self::SETTING_DELAY_MINUTES, 30);

        return max(0, min($m, 1440));
    }

    /**
     * Queue a welcome for a newly-joined person. ONE per account per corp: skips
     * if a welcome for this user+corp is already pending, or was sent within the
     * last 30 days (so a leave/rejoin blip doesn't re-welcome). No-op when the
     * feature is off or the table is absent.
     */
    public function enqueue(int $corporationId, int $userId, int $characterId): void
    {
        if (!$this->isEnabled() || !Schema::hasTable('hr_manager_onboarding_welcomes')) {
            return;
        }

        // Per-corp opt-out. Queuing for a corp that isn't running onboarding
        // just parks a welcome that can never be delivered until it expires.
        if (!$this->isEnabledForCorp($corporationId)) {
            return;
        }

        $exists = OnboardingWelcome::where('corporation_id', $corporationId)
            ->where('user_id', $userId)
            ->where(function ($q) {
                $q->whereNull('sent_at')->orWhere('sent_at', '>=', now()->subDays(30));
            })
            ->exists();
        if ($exists) {
            return;
        }

        OnboardingWelcome::create([
            'corporation_id' => $corporationId,
            'user_id'        => $userId,
            'character_id'   => $characterId,
            'send_after'     => now()->addMinutes($this->delayMinutes()),
            'sent_at'        => null,
        ]);
    }

    /**
     * Cron sweep over welcomes whose delay has elapsed.
     *
     * A welcome is only CONSUMED when it is genuinely resolved: sent, or the
     * member turned out to have left, or it waited past MAX_DEFER_DAYS.
     * Everything else — SeAT not synced yet, no webhook subscribing, the member
     * not linked to Discord yet, a webhook hiccup — leaves it pending for the
     * next pass. Stamping those as done was silently eating people's greetings,
     * most obviously when the feature was switched on before the webhook
     * category was ticked.
     *
     * @return array{sent:int, dropped:int, deferred:int, expired:int}
     */
    public function dispatchDue(): array
    {
        $out = ['sent' => 0, 'dropped' => 0, 'deferred' => 0, 'expired' => 0];

        if (!Schema::hasTable('hr_manager_onboarding_welcomes')) {
            return $out;
        }

        $due = OnboardingWelcome::whereNull('sent_at')
            ->where('send_after', '<=', now())
            ->get();

        foreach ($due as $welcome) {
            $userId = (int) $welcome->user_id;
            $corpId = (int) $welcome->corporation_id;

            // Waiting is only worth doing for so long. Past the window, stop —
            // a month-late "welcome to the corp" is worse than none.
            $stale = $welcome->send_after
                && $welcome->send_after->lt(now()->subDays(self::MAX_DEFER_DAYS));

            // 1. Are they actually in the corp? Tri-state on purpose: SeAT's
            // affiliation table can lag the roster scan that queued this, and
            // "not synced yet" must not be read as "already left".
            $presence = $this->corpPresence($userId, $corpId);
            if ($presence === 'out') {
                $welcome->update(['sent_at' => now()]);
                $out['dropped']++;
                continue;
            }
            if ($presence === 'unknown') {
                $this->deferOrExpire($welcome, $stale, $out, 'no affiliation data yet');
                continue;
            }

            // 2. Somewhere to send it. Previously this consumed the welcome even
            // with no webhook subscribed, so turning the feature on before
            // ticking the category silently burned every new member's greeting.
            if (!$this->hasSubscriber($corpId)) {
                $this->deferOrExpire($welcome, $stale, $out, 'no webhook subscribes to the onboarding category');
                continue;
            }

            // 3. Can they actually SEE it? The whole point of the delay is to
            // let Connector link them and grant the corp-member role. If they
            // have no Discord identity yet, the ping would resolve to nothing
            // and land in a channel they cannot read — so wait for them.
            if (!$this->discordReady($userId)) {
                $this->deferOrExpire($welcome, $stale, $out, 'no linked Discord identity yet');
                continue;
            }

            try {
                $this->send($welcome);
                $welcome->update(['sent_at' => now()]);
                $out['sent']++;
            } catch (\Throwable $e) {
                // A transient webhook failure shouldn't burn the welcome either.
                $this->deferOrExpire($welcome, $stale, $out, 'send failed: ' . $e->getMessage());
            }
        }

        return $out;
    }

    /** Leave it pending for another pass, or give up once it's too old. */
    private function deferOrExpire(OnboardingWelcome $welcome, bool $stale, array &$out, string $why): void
    {
        if ($stale) {
            $welcome->update(['sent_at' => now()]);
            $out['expired']++;
            Log::info('[HR Manager] onboarding welcome expired for user ' . $welcome->user_id . ' (' . $why . ')');
            return;
        }

        $out['deferred']++;
    }

    /**
     * Is the account in this corp? 'in' | 'out' | 'unknown'.
     *
     * Any character on the account counts — someone can join on an alt, and
     * the welcome is for the person. 'unknown' means SeAT has no affiliation
     * row for them yet, which is a reason to wait, not to drop.
     */
    private function corpPresence(int $userId, int $corporationId): string
    {
        $charIds = DB::table('refresh_tokens')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->pluck('character_id')
            ->map(fn ($c) => (int) $c)
            ->all();

        if (empty($charIds)) {
            return 'out'; // every token gone — nothing left to reach them by
        }

        $affiliations = DB::table('character_affiliations')
            ->whereIn('character_id', $charIds)
            ->pluck('corporation_id')
            ->map(fn ($c) => (int) $c)
            ->all();

        if (empty($affiliations)) {
            return 'unknown'; // not synced yet
        }

        return in_array($corporationId, $affiliations, true) ? 'in' : 'out';
    }

    /** Is any enabled webhook actually listening for this corp's welcomes? */
    private function hasSubscriber(int $corporationId): bool
    {
        return WebhookConfiguration::enabled()
            ->forCorporation($corporationId)
            ->where('notify_onboarding_welcome', true)
            ->exists();
    }

    /**
     * Ready to be @-mentioned? True when Connector isn't installed at all (we
     * post naming them in plain text and there is nothing to wait for), or
     * when it is installed AND has resolved them to a Discord identity.
     */
    private function discordReady(int $userId): bool
    {
        $connector = app(SeatConnectorService::class);
        if (!$connector->isAvailable()) {
            return true;
        }

        $identity = $connector->getIdentityForUser($userId);

        return !empty($identity['available']) && !empty($identity['connector_id']);
    }

    /**
     * Render + deliver one welcome. Returns false when no enabled webhook
     * subscribes to the onboarding category (nothing to send to).
     */
    private function send(OnboardingWelcome $welcome): bool
    {
        $corporationId = (int) $welcome->corporation_id;

        $webhooks = WebhookConfiguration::enabled()
            ->forCorporation($corporationId)
            ->where('notify_onboarding_welcome', true)
            ->get();
        if ($webhooks->isEmpty()) {
            return false;
        }

        $memberName = app(NameResolutionService::class)->getCharacterName((int) $welcome->character_id)
            ?? ('#' . $welcome->character_id);

        // Resolve the new member's Discord @-mention (Connector). Degrade to the
        // bolded character name when there's no linked identity.
        $memberToken = '**' . $memberName . '**';
        $connector = app(SeatConnectorService::class);
        if ($connector->isAvailable()) {
            $identity = $connector->getIdentityForUser((int) $welcome->user_id);
            if (!empty($identity['available']) && !empty($identity['connector_id'])) {
                $memberToken = '<@' . $identity['connector_id'] . '>';
            }
        }

        $corpName = CorporationInfo::where('corporation_id', $corporationId)->value('name') ?? ('#' . $corporationId);

        [$body, $roleIds] = $this->resolveTemplate($corporationId);
        $careMentions = collect($roleIds)
            ->filter()
            ->map(fn ($id) => '<@&' . preg_replace('/\D/', '', (string) $id) . '>')
            ->implode(' ');

        $content = strtr($body, [
            '{member}' => $memberToken,
            '{corp}'   => $corpName,
            '{care}'   => $careMentions,
        ]);

        $webhookService = app(WebhookService::class);
        foreach ($webhooks as $webhook) {
            if ($webhook->type === 'discord') {
                $webhookService->sendDiscordContent($webhook, $content);
            } elseif ($webhook->type === 'slack') {
                // Slack can't resolve Discord mention syntax; render it readable.
                $webhookService->sendSlackWebhook($webhook, ['text' => $this->plainForSlack($content, $memberName)]);
            }
        }

        return true;
    }

    /**
     * The welcome body + care-team role ids for a corp: its own template row if
     * present, otherwise the hardcoded casual default (and no extra mentions).
     *
     * @return array{0:string, 1:array}
     */
    private function resolveTemplate(int $corporationId): array
    {
        if (Schema::hasTable('hr_manager_onboarding_templates')) {
            $row = OnboardingTemplate::where('corporation_id', $corporationId)->first();
            if ($row && trim((string) $row->body) !== '') {
                return [(string) $row->body, is_array($row->mention_role_ids) ? $row->mention_role_ids : []];
            }
        }

        return [(string) trans('hr-manager::onboarding.default_body'), []];
    }

    /** Strip Discord mention syntax for the Slack fallback. */
    private function plainForSlack(string $content, string $memberName): string
    {
        $content = preg_replace('/<@&\d+>/', '', $content);   // role mentions
        $content = preg_replace('/<@\d+>/', $memberName, $content); // member mention

        return trim((string) $content);
    }
}
