<?php

namespace HrManager\Services;

/**
 * Validates webhook URLs against type-specific host allowlists.
 *
 * SeAT v5 pitfall #12: stripos($host, 'discord.com') passes
 * 'evil.discord.com.attacker.example'. Uses end-anchored host checks.
 *
 * Only `discord` and `slack` types are supported in v1.0.0; the `custom`
 * type was removed because it required DNS resolution checks that are
 * still rebinding-vulnerable, and neither Matt's suite nor typical
 * SeAT installs need arbitrary-host webhook delivery.
 */
class WebhookUrlValidator
{
    private const ALLOWED_HOSTS = [
        'discord' => ['discord.com'],
        'slack'   => ['hooks.slack.com', 'slack.com'],
    ];

    /**
     * Validate a webhook URL for a given type.
     * Returns null if valid, error string otherwise.
     */
    public function validate(string $type, string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || empty($parts['host']) || empty($parts['scheme'])) {
            return 'Invalid URL format.';
        }

        if (strtolower($parts['scheme']) !== 'https') {
            return 'Webhook URL must use HTTPS.';
        }

        if (!empty($parts['user']) || !empty($parts['pass'])) {
            return 'Webhook URL must not contain credentials.';
        }

        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return 'Webhook URL must use default HTTPS port (443).';
        }

        $host = strtolower($parts['host']);

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return 'Webhook URL must not be an IP address.';
        }

        $allowed = self::ALLOWED_HOSTS[$type] ?? [];
        if (empty($allowed)) {
            return "Unsupported webhook type: {$type}";
        }
        foreach ($allowed as $allowedHost) {
            if ($host === $allowedHost || str_ends_with($host, '.' . $allowedHost)) {
                return null;
            }
        }

        return "Webhook URL host must match {$type} allowlist (" . implode(', ', $allowed) . ').';
    }
}
