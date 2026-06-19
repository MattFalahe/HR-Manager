<?php

namespace HrManager\Services;

use HrManager\Models\WebhookConfiguration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebhookService
{
    public function sendDiscordWebhook(WebhookConfiguration $config, array $embed): bool
    {
        $payload = [
            'username' => $config->discord_username ?? 'HR Manager',
            'embeds'   => [$embed],
        ];

        if ($config->discord_role_id) {
            $payload['content'] = "<@&{$config->discord_role_id}>";
        }

        return $this->sendPayload($config, $payload);
    }

    public function sendSlackWebhook(WebhookConfiguration $config, array $data): bool
    {
        $payload = [
            'username' => $config->slack_username ?? 'HR Manager',
            'channel'  => $config->slack_channel,
            'text'     => $data['text'] ?? '',
            'attachments' => $data['attachments'] ?? [],
        ];

        return $this->sendPayload($config, $payload);
    }

    /**
     * Send a test message. v1.0.0 only supports discord + slack — the
     * 'custom' type was removed.
     */
    public function testWebhook(WebhookConfiguration $config): bool
    {
        if ($config->type === 'discord') {
            return $this->sendDiscordWebhook($config, [
                'title'       => 'HR Manager - Test Webhook',
                'description' => 'This is a test message from HR Manager. If you see this, the webhook is configured correctly.',
                'color'       => 0x667eea,
                'timestamp'   => now()->toIso8601String(),
                'footer'      => ['text' => 'HR Manager'],
            ]);
        }

        if ($config->type === 'slack') {
            return $this->sendSlackWebhook($config, [
                'text' => 'HR Manager - Test Webhook. If you see this, the webhook is configured correctly.',
            ]);
        }

        Log::warning('[HR Manager] testWebhook called with unsupported type: ' . $config->type);
        return false;
    }

    public function buildApplicationEmbed(string $event, array $data): array
    {
        $colors = [
            'submitted' => 0x17a2b8,
            'accepted'  => 0x28a745,
            'rejected'  => 0xdc3545,
            'status_change' => 0xffc107,
            'inactive_director' => 0xdc3545,
            'dead_weight' => 0xfd7e14,
            'purge_reminder' => 0xe83e8c,
            'intel_scope_match' => 0x17a2b8,
            'player_status_loa_marked' => 0x17a2b8,
            'player_status_marked_for_purge' => 0xe83e8c,
            'player_status_status_cleared' => 0x28a745,
        ];

        // Human-readable embed titles. The raw event key (e.g.
        // player_status_marked_for_purge) is an internal identifier, not
        // something to show an operator. Fall back to a humanised key for any
        // event not mapped here.
        $titles = [
            'submitted'                      => 'New application',
            'accepted'                       => 'Application accepted',
            'rejected'                       => 'Application rejected',
            'status_change'                  => 'Application status changed',
            'inactive_director'              => 'Inactive director',
            'dead_weight'                    => 'Dead-weight member',
            'purge_reminder'                 => 'Purge reminder',
            'intel_scope_match'              => 'Watchlist match',
            'player_status_loa_marked'       => 'Player marked on LOA',
            'player_status_marked_for_purge' => 'Player marked for purge',
            'player_status_status_cleared'   => 'Player status cleared',
        ];
        $title = $titles[$event]
            ?? ucfirst(trim(str_replace(['player_status_', '_'], ['', ' '], $event)));

        return [
            'title'       => $title,
            'description' => $data['description'] ?? '',
            'color'       => $colors[$event] ?? 0x667eea,
            'fields'      => $data['fields'] ?? [],
            'timestamp'   => now()->toIso8601String(),
            'footer'      => ['text' => 'HR Manager'],
            'thumbnail'   => isset($data['character_id']) ? [
                'url' => "https://images.evetech.net/characters/{$data['character_id']}/portrait?size=64",
            ] : null,
        ];
    }

    /**
     * Send payload to webhook URL.
     * Uses explicit connect + total timeouts to avoid blocking queue workers
     * (SeAT v5 queue retry_after=960s — must finish well inside).
     */
    private function sendPayload(WebhookConfiguration $config, array $payload): bool
    {
        try {
            $response = Http::connectTimeout(5)
                ->timeout(10)
                ->retry(2, 500, function ($exception) {
                    if ($exception instanceof \Illuminate\Http\Client\ConnectionException) {
                        return true;
                    }
                    if ($exception instanceof \Illuminate\Http\Client\RequestException) {
                        $status = $exception->response->status();
                        return in_array($status, [429, 500, 502, 503, 504]);
                    }
                    return false;
                }, throw: false)
                ->post($config->webhook_url, $payload);

            if ($response->successful()) {
                $config->recordSuccess();
                return true;
            }

            $body = \Illuminate\Support\Str::limit($response->body(), 500);
            $config->recordFailure("HTTP {$response->status()}: {$body}");
            return false;
        } catch (\Exception $e) {
            Log::error("[HR Manager] Webhook delivery failed: {$e->getMessage()}");
            $config->recordFailure(\Illuminate\Support\Str::limit($e->getMessage(), 500));
            return false;
        }
    }
}
