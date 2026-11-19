<?php

namespace HrManager\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Discord REST over Laravel's HTTP client, with the bot token SeAT Connector's
 * Discord driver already holds. Never retries on its own: the poller decides
 * what a 429 or a 5xx means for the run.
 */
class DiscordHttpVoiceTransport implements DiscordVoiceTransport
{
    private const BASE_URL = 'https://discord.com/api/v10';
    private const TIMEOUT_SECONDS = 8;

    // Discord rejects requests without a User-Agent in its documented form.
    private const USER_AGENT = 'DiscordBot (https://github.com/MattFalahe/HR-Manager, 1.0)';

    private string $botToken;

    public function __construct(string $botToken)
    {
        $this->botToken = $botToken;
    }

    public function voiceState(string $guildId, string $discordUserId): array
    {
        try {
            $response = $this->client()->get(self::BASE_URL . '/guilds/' . rawurlencode($guildId) . '/voice-states/' . rawurlencode($discordUserId));
        } catch (\Throwable $e) {
            // The message can carry the request, never the token, but keep it short.
            Log::info('[HR Manager] Discord voice-state request failed: ' . substr($e->getMessage(), 0, 200));

            return ['status' => 0, 'headers' => [], 'body' => null];
        }

        $headers = [];
        foreach ($response->headers() as $name => $values) {
            $headers[strtolower($name)] = is_array($values) ? (string) ($values[0] ?? '') : (string) $values;
        }

        $body = $response->json();

        return [
            'status'  => $response->status(),
            'headers' => $headers,
            'body'    => is_array($body) ? $body : null,
        ];
    }

    public function channelNames(string $guildId): array
    {
        try {
            $response = $this->client()->get(self::BASE_URL . '/guilds/' . rawurlencode($guildId) . '/channels');
        } catch (\Throwable $e) {
            Log::info('[HR Manager] Discord channel list request failed: ' . substr($e->getMessage(), 0, 200));

            return [];
        }

        if (!$response->successful() || !is_array($response->json())) {
            return [];
        }

        $names = [];
        foreach ($response->json() as $channel) {
            if (isset($channel['id'], $channel['name'])) {
                $names[(string) $channel['id']] = (string) $channel['name'];
            }
        }

        return $names;
    }

    private function client()
    {
        return Http::timeout(self::TIMEOUT_SECONDS)
            ->withHeaders([
                'Authorization' => 'Bot ' . $this->botToken,
                'User-Agent'    => self::USER_AGENT,
            ]);
    }
}
