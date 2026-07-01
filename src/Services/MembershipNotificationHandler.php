<?php

namespace HrManager\Services;

use Illuminate\Support\Facades\Log;

/**
 * Manager Core ESI fast-poll handler for corp-membership EVE notifications.
 *
 * Registered with MC's EsiNotificationRegistry when MC is installed, this
 * delivers joins (and voluntary leaves) ~2 minutes after they happen — far
 * faster than the 30-minute roster-diff — by reading the director notification
 * feed MC already polls (/characters/{id}/notifications/).
 *
 * Handled EVE notification types (from the canonical ESI notification list):
 *   - CorpAppAcceptMsg — an application was accepted; the character JOINED.
 *   - CharLeftCorpMsg  — a member voluntarily LEFT. A kick is silent (EVE sends
 *                        no notification), so kicks are caught by the roster-diff
 *                        only.
 *
 * CorpAppNewMsg (the in-game application) is intentionally not acted on here —
 * an in-game application is a different signal from HR's own recruitment funnel.
 *
 * All the work + dedup against the reliable roster-diff lives in
 * MembershipChangeService; this class only parses the notification and routes.
 * Contract per MC: a static handle($notification) reading ->type + ->parsed_data
 * (MC pre-parses the EVE YAML text into parsed_data). Never throws.
 */
class MembershipNotificationHandler
{
    public const JOIN_TYPES  = ['CorpAppAcceptMsg'];
    public const LEAVE_TYPES = ['CharLeftCorpMsg'];

    /**
     * The webhook notification categories this fast-poll handler can accelerate:
     * the corp-membership joins + voluntary leaves. When Manager Core's fast-poll
     * is active these are picked up ~2 min from the director notification feed;
     * without it they still fire, just from the 30-min roster-diff scan instead.
     * Used to badge those categories in the webhook settings UI.
     *
     * @return array<int,string>
     */
    public static function fastPollCategories(): array
    {
        return [
            'notify_member_joined',
            'notify_member_left',
            'notify_join_no_application',
            'notify_member_unregistered',
        ];
    }

    /**
     * Whether Manager Core exposes the ESI fast-poll registry HR registers with.
     * The single source of truth for "is fast-poll available?" across the
     * diagnostic and the settings badge. Not installed => the roster-diff scan is
     * the sole detector (still fully functional, just slower).
     */
    public static function fastPollAvailable(): bool
    {
        return class_exists('\\ManagerCore\\Services\\ESI\\EsiNotificationRegistry');
    }

    /** Every EVE notification type this handler registers for. */
    public static function registeredTypes(): array
    {
        return array_merge(self::JOIN_TYPES, self::LEAVE_TYPES);
    }

    public static function handle($notification): void
    {
        try {
            $type = $notification->type ?? null;
            if ($type === null) {
                return;
            }

            $data = $notification->parsed_data ?? [];
            if (!is_array($data)) {
                return;
            }

            // EVE encodes these as charID / corpID; read defensively so a field
            // rename does not silently drop the signal (and log the keys when it
            // can't resolve, to make validation against a live feed trivial).
            $charId = self::intField($data, ['charID', 'characterID', 'char_id', 'character_id']);
            $corpId = self::intField($data, ['corpID', 'corporationID', 'corp_id', 'corporation_id']);
            if ($charId === null || $corpId === null) {
                Log::info('[HR Manager] membership notification ' . $type . ' missing char/corp id', [
                    'keys' => array_keys($data),
                ]);
                return;
            }

            $svc = app(MembershipChangeService::class);
            if (in_array($type, self::JOIN_TYPES, true)) {
                $svc->handleJoinNotification($corpId, $charId);
            } elseif (in_array($type, self::LEAVE_TYPES, true)) {
                $svc->handleLeaveNotification($corpId, $charId);
            }
        } catch (\Throwable $e) {
            Log::warning('[HR Manager] MembershipNotificationHandler failed: ' . $e->getMessage());
        }
    }

    private static function intField(array $data, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_numeric($data[$key]) && (int) $data[$key] > 0) {
                return (int) $data[$key];
            }
        }
        return null;
    }
}
