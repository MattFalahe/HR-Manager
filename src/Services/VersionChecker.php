<?php

namespace HrManager\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Compares the currently-installed HR Manager version against the
 * latest stable release on Packagist and reports an update-available
 * status. Powers the Version Status card on the Help & Documentation
 * Overview tab.
 *
 * Resilience:
 *   - Result is cached for 6 hours so each Help-page visit doesn't
 *     hit Packagist
 *   - HTTP request has a 3-second timeout
 *   - Every failure path returns 'unknown' status so the Help page
 *     never errors out from a Packagist hiccup
 *
 * Pattern source: ported from SeAT Broadcast / Structure Manager,
 * where it was introduced first. Same return shape across the suite
 * so Blade renderers can be copied verbatim.
 */
class VersionChecker
{
    /** Packagist v2 metadata URL for this package. */
    private const PACKAGIST_URL = 'https://repo.packagist.org/p2/mattfalahe/hr-manager.json';

    /** Composer package key under packages.* in the Packagist response. */
    private const PACKAGE_KEY = 'mattfalahe/hr-manager';

    /** Cache key for the fetched-latest result. */
    private const CACHE_KEY = 'hr-manager:packagist_latest';

    /** Cache TTL (seconds). 6 hours: generous to Packagist + responsive. */
    private const CACHE_TTL = 6 * 60 * 60;

    /** Guzzle request timeout (seconds). Help page can't block longer. */
    private const HTTP_TIMEOUT = 3;

    /**
     * Return a structured status array for the Version Status card.
     * Always returns a complete shape, even on failure.
     *
     * Shape:
     *   [
     *     'current'        => '1.0.0' | 'dev-dev' | ...,
     *     'current_source' => 'composer' | 'config',
     *     'is_dev_branch'  => bool,
     *     'latest'         => '1.0.0' | null,
     *     'status'         => 'current' | 'outdated' | 'ahead'
     *                       | 'dev_branch' | 'unknown',
     *     'message'        => 'human-readable explanation',
     *     'release_url'    => 'https://github.com/...' | null,
     *   ]
     */
    public function getStatus(): array
    {
        [$current, $source] = $this->resolveInstalledVersion();
        $isDevBranch = $this->looksLikeDevBranch($current);
        $latest = $this->fetchLatestVersion();

        if ($isDevBranch) {
            return [
                'current'        => $current,
                'current_source' => $source,
                'is_dev_branch'  => true,
                'latest'         => $latest,
                'status'         => 'dev_branch',
                'message'        => 'You are running a development branch (' . $current . '), not a tagged release. The "Latest release" pill above is the most recent stable Packagist tag — your branch may be ahead of or behind that depending on local commits. Switch to a tagged version (composer require mattfalahe/hr-manager:^X.Y.Z) for a definitive "up to date" comparison.',
                'release_url'    => null,
            ];
        }

        if ($latest === null) {
            return [
                'current'        => $current,
                'current_source' => $source,
                'is_dev_branch'  => false,
                'latest'         => null,
                'status'         => 'unknown',
                'message'        => 'Unable to check the latest version. Packagist may be unreachable or the network call timed out. The plugin is unaffected — this is informational only.',
                'release_url'    => null,
            ];
        }

        $cmp = version_compare($current, $latest);

        if ($cmp < 0) {
            return [
                'current'        => $current,
                'current_source' => $source,
                'is_dev_branch'  => false,
                'latest'         => $latest,
                'status'         => 'outdated',
                'message'        => 'A newer release is available. Update via your standard SeAT Docker upgrade path (docker compose down then up brings in the new composer package automatically). See the CHANGELOG for the upgrade recipe.',
                'release_url'    => 'https://github.com/MattFalahe/HR-Manager/releases/tag/' . $latest,
            ];
        }

        if ($cmp > 0) {
            return [
                'current'        => $current,
                'current_source' => $source,
                'is_dev_branch'  => false,
                'latest'         => $latest,
                'status'         => 'ahead',
                'message'        => 'You are running a tagged pre-release newer than the latest stable Packagist release. Common when testing a release-candidate tag before promoting it.',
                'release_url'    => null,
            ];
        }

        return [
            'current'        => $current,
            'current_source' => $source,
            'is_dev_branch'  => false,
            'latest'         => $latest,
            'status'         => 'current',
            'message'        => 'You are running the latest tagged release.',
            'release_url'    => null,
        ];
    }

    protected function resolveInstalledVersion(): array
    {
        if (class_exists('\\Composer\\InstalledVersions')) {
            try {
                if (\Composer\InstalledVersions::isInstalled(self::PACKAGE_KEY)) {
                    $version = \Composer\InstalledVersions::getPrettyVersion(self::PACKAGE_KEY);
                    if (is_string($version) && $version !== '') {
                        return [$version, 'composer'];
                    }
                }
            } catch (\Throwable $e) {
                // fall through to config fallback
            }
        }

        return [(string) config('hr-manager.version', '0.0.0'), 'config'];
    }

    protected function looksLikeDevBranch(string $version): bool
    {
        return $version === ''
            || str_starts_with($version, 'dev-')
            || str_ends_with($version, '-dev');
    }

    protected function fetchLatestVersion(): ?string
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            try {
                $client = new Client([
                    'timeout'         => self::HTTP_TIMEOUT,
                    'connect_timeout' => self::HTTP_TIMEOUT,
                    'verify'          => true,
                    'headers'         => [
                        'Accept'     => 'application/json',
                        'User-Agent' => 'SeAT-HrManager/' . config('hr-manager.version', 'unknown'),
                    ],
                ]);

                $response = $client->get(self::PACKAGIST_URL);
                $data     = json_decode((string) $response->getBody(), true);

                if (! is_array($data) || ! isset($data['packages'][self::PACKAGE_KEY]) || ! is_array($data['packages'][self::PACKAGE_KEY])) {
                    Log::warning('[HR Manager] VersionChecker: Packagist response missing expected packages.' . self::PACKAGE_KEY . ' shape');
                    return null;
                }

                foreach ($data['packages'][self::PACKAGE_KEY] as $release) {
                    $version = $release['version'] ?? '';
                    if ($version === '' || str_starts_with($version, 'dev-') || str_contains($version, '-dev')) {
                        continue;
                    }
                    return ltrim((string) $version, 'v');
                }

                return null;
            } catch (\Throwable $e) {
                Log::warning('[HR Manager] VersionChecker: failed to fetch latest version from Packagist: ' . $e->getMessage());
                return null;
            }
        });
    }
}
