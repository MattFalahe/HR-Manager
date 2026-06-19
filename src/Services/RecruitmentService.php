<?php

namespace HrManager\Services;

use Carbon\Carbon;
use HrManager\Models\RecruitmentLanding;
use HrManager\Models\RecruitmentView;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Recruitment landing CRUD, slug uniqueness, view tracking, analytics.
 *
 * Slug strategy: unique per (corporation_id, slug). Public URL format is
 * /recruit/{corp_ticker}/{slug} — ticker resolved from corporation_infos
 * at render time so the URL works even if the operator changes the ticker
 * (rare; CCP allows it).
 */
class RecruitmentService
{
    /**
     * Resolve a public URL to a landing. Returns null if no match.
     */
    public function resolveByTickerAndSlug(string $ticker, string $slug): ?RecruitmentLanding
    {
        $corpIds = DB::table('corporation_infos')
            ->where('ticker', $ticker)
            ->pluck('corporation_id')
            ->all();

        if (empty($corpIds)) {
            return null;
        }

        return RecruitmentLanding::whereIn('corporation_id', $corpIds)
            ->where('slug', $slug)
            ->first();
    }

    /**
     * Generate a unique slug for a landing under a given corp. Appends
     * `-2`, `-3` etc. if the base slug is taken.
     */
    public function generateUniqueSlug(int $corporationId, string $base): string
    {
        $slug = Str::slug($base);
        if (!$slug) {
            $slug = 'landing';
        }

        $candidate = $slug;
        $n = 2;
        while (RecruitmentLanding::where('corporation_id', $corporationId)
            ->where('slug', $candidate)
            ->exists()) {
            $candidate = $slug . '-' . $n;
            $n++;
        }
        return $candidate;
    }

    /**
     * Record a view of a landing. Best-effort; failures log + swallow so
     * the public page never fails to render due to analytics noise.
     */
    public function recordView(RecruitmentLanding $landing, Request $request, bool $clickedApply = false): ?RecruitmentView
    {
        try {
            $view = RecruitmentView::create([
                'landing_id'      => $landing->id,
                'ip_hash'         => $this->hashIp($request->ip()),
                'user_agent_hash' => $this->hashUa($request->userAgent()),
                'referrer_domain' => $this->extractReferrerDomain($request->headers->get('referer')),
                'clicked_apply'   => $clickedApply,
                'viewed_at'       => now(),
            ]);
            $landing->incrementCounters(['view_count']);
            return $view;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[HR Manager] recordView failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Aggregate analytics for the director-facing dashboard.
     *
     *   - views by day (last N days)
     *   - apply-click count + conversion %
     *   - top referrer domains
     *   - applications submitted from this landing
     *
     * @return array
     */
    public function analytics(RecruitmentLanding $landing, int $days = 30): array
    {
        $since = now()->subDays($days);
        $views = RecruitmentView::forLanding($landing->id)->since($since)->get();

        $byDay = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $byDay[$date] = ['date' => $date, 'views' => 0, 'apply_clicks' => 0];
        }
        foreach ($views as $v) {
            $d = $v->viewed_at->toDateString();
            if (!isset($byDay[$d])) continue;
            $byDay[$d]['views']++;
            if ($v->clicked_apply) {
                $byDay[$d]['apply_clicks']++;
            }
        }

        $totalViews = $views->count();
        $totalClicks = $views->where('clicked_apply', true)->count();
        $uniqueViewers = $views->pluck('ip_hash')->unique()->count();

        $referrers = $views->whereNotNull('referrer_domain')
            ->groupBy('referrer_domain')
            ->map(fn($group) => $group->count())
            ->sortDesc()
            ->take(10)
            ->map(fn($c, $domain) => ['domain' => $domain, 'count' => $c])
            ->values()
            ->all();

        $applicationCount = $landing->applications()
            ->where('submitted_at', '>=', $since)
            ->count();

        return [
            'days'              => $days,
            'total_views'       => $totalViews,
            'unique_viewers'    => $uniqueViewers,
            'apply_clicks'      => $totalClicks,
            'applications'      => $applicationCount,
            'conversion_clicks' => $totalViews > 0 ? round($totalClicks / $totalViews * 100, 1) : 0,
            'conversion_apply'  => $totalViews > 0 ? round($applicationCount / $totalViews * 100, 1) : 0,
            'by_day'            => array_values($byDay),
            'top_referrers'     => $referrers,
        ];
    }

    /**
     * Store an uploaded hero image. Returns the relative storage path
     * suitable for the hero-stream route. Defensive: verifies the file
     * landed on disk before returning the path, and rethrows with a
     * concrete reason so the controller can flash it to the operator
     * instead of failing silently.
     *
     * Common failure modes this surfaces:
     *   - PHP upload limits (upload_max_filesize / post_max_size) —
     *     UploadedFile arrives as invalid()
     *   - Filesystem disk not writable (perms / unmounted volume) —
     *     storeAs() returns false
     *   - Unusual extensions (no extension at all on raw paste) —
     *     guessed via mime type
     */
    public function storeHeroImage(\Illuminate\Http\UploadedFile $file): string
    {
        if (!$file->isValid()) {
            throw new \RuntimeException(
                'Upload was rejected by PHP. Likely cause: file exceeded '
                . 'upload_max_filesize / post_max_size. Error code: '
                . $file->getError()
            );
        }

        $disk = config('hr-manager.recruitment.upload_disk', 'public');

        // Prefer the client-provided extension; if missing (some browsers
        // strip it on paste-upload), derive from MIME so we still end up
        // with a sensible filename the streaming route can serve.
        $ext = strtolower($file->getClientOriginalExtension() ?: '');
        if ($ext === '' || strlen($ext) > 5) {
            $ext = match ($file->getMimeType()) {
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp',
                default      => 'bin',
            };
        }

        $filename = Str::random(40) . '.' . $ext;

        try {
            $path = $file->storeAs('hr-manager/landings', $filename, $disk);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "Filesystem write failed on disk '{$disk}': " . $e->getMessage(),
                0,
                $e
            );
        }

        if ($path === false || $path === null || $path === '') {
            throw new \RuntimeException(
                "Filesystem write returned no path. Disk '{$disk}' is likely "
                . 'not writable. Check the filesystems config + container '
                . 'volume mounts.'
            );
        }

        // Belt + braces: confirm the bytes are actually there. Disks that
        // silently swallow writes (mis-mounted volumes, full disks) would
        // otherwise leave us with a DB row pointing at a 404.
        if (!Storage::disk($disk)->exists($path)) {
            throw new \RuntimeException(
                "Stored path '{$path}' does not exist on disk '{$disk}' "
                . 'immediately after write. The disk is misconfigured or '
                . 'the volume is read-only.'
            );
        }

        return $path;
    }

    public function deleteHeroImage(?string $path): void
    {
        if (!$path) return;
        $disk = config('hr-manager.recruitment.upload_disk', 'public');
        try {
            if (Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[HR Manager] deleteHeroImage failed: ' . $e->getMessage());
        }
    }

    private function hashIp(?string $ip): ?string
    {
        if (!$ip) return null;
        return hash('sha256', $ip . config('app.key', 'hr-fallback-key'));
    }

    private function hashUa(?string $ua): ?string
    {
        if (!$ua) return null;
        return hash('sha256', $ua);
    }

    private function extractReferrerDomain(?string $referer): ?string
    {
        if (!$referer) return null;
        $host = parse_url($referer, PHP_URL_HOST);
        return $host ?: null;
    }
}
