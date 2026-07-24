<?php

namespace HrManager\Services;

use HrManager\Models\AuditLog;
use HrManager\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Director-facing activity trail. Two write paths:
 *   - logView() — called by the AuditViewLogger middleware for every internal
 *     GET, so "who looked at whom, and when" is captured with zero call sites.
 *   - action()  — called explicitly from the privilege / decision / security /
 *     notification flows for the things that MUTATE state.
 *
 * Everything here is best-effort and self-guarded: auditing must never break or
 * slow a real request, so writes swallow errors, do no extra look-ups on the hot
 * (view) path, and no-op entirely when the feature is off or the table is absent.
 * Actor + target NAMES are resolved in batch on the audit page, not per write.
 */
class AuditService
{
    public const SETTING_ENABLED = 'enable_audit_log';
    public const RETENTION_DAYS  = 180;

    public const CAT_VIEW         = 'view';
    public const CAT_PRIVILEGE    = 'privilege';
    public const CAT_DECISION     = 'decision';
    public const CAT_SECURITY     = 'security';
    public const CAT_NOTIFICATION = 'notification';
    public const CAT_MANAGEMENT   = 'management';
    public const CAT_SYSTEM       = 'system';

    /**
     * Route name → [category, summary] for the generic write logger. Every
     * mutating request whose route isn't SELF_LOGGED or WRITE_SKIP is recorded
     * from this map (falling back to a humanised label for anything not listed),
     * so "all actions" are captured with one middleware and no per-controller
     * call sites. The high-value / context-rich actions (access, decisions,
     * watchlist, intel) hand-instrument themselves instead — see SELF_LOGGED.
     */
    public const WRITE_ACTIONS = [
        'hr-manager.applications.destroy'            => ['decision',   'Deleted an application'],
        'hr-manager.applications.handlers.join'      => ['decision',   'Joined an application as handler'],
        'hr-manager.applications.handlers.leave'     => ['decision',   'Left an application handler list'],
        'hr-manager.applications.handlers.role'      => ['decision',   'Changed a handler role'],
        'hr-manager.applications.refresh-assessment' => ['management', 'Refreshed an applicant assessment'],

        'hr-manager.notes.store'   => ['management', 'Added a note'],
        'hr-manager.notes.update'  => ['management', 'Edited a note'],
        'hr-manager.notes.destroy' => ['management', 'Deleted a note'],

        'hr-manager.players.loa'                 => ['decision',   'Marked a player on leave (LOA)'],
        'hr-manager.players.clear-status'        => ['decision',   'Cleared a player status'],
        'hr-manager.players.mark-for-purge'      => ['decision',   'Marked a player for purge'],
        'hr-manager.players.purge-executed'      => ['decision',   'Marked a purge as executed'],
        'hr-manager.players.merge-identity'      => ['decision',   'Merged a player identity'],
        'hr-manager.players.reassign-character'  => ['decision',   'Reassigned a character to another player'],
        'hr-manager.players.remove-squads'       => ['decision',   'Removed a player from squads'],
        'hr-manager.players.refresh'             => ['management', 'Refreshed player data'],
        'hr-manager.players.notes'               => ['management', 'Updated player notes'],

        'hr-manager.members.refresh' => ['management', 'Refreshed a member assessment'],

        'hr-manager.corp-health.run-now'             => ['management', 'Ran the Corp Health classifier'],
        'hr-manager.corp-health.membership-review'   => ['decision',   'Reviewed a membership flag'],
        'hr-manager.corp-health.purge-step'          => ['decision',   'Advanced a purge step'],
        'hr-manager.corp-health.purge-note'          => ['decision',   'Added a purge note'],
        'hr-manager.corp-health.purge-remove-squads' => ['decision',   'Removed squads during purge'],

        'hr-manager.templates.store'       => ['management', 'Created a form template'],
        'hr-manager.templates.update'      => ['management', 'Edited a form template'],
        'hr-manager.templates.destroy'     => ['management', 'Deleted a form template'],
        'hr-manager.templates.duplicate'   => ['management', 'Duplicated a form template'],
        'hr-manager.templates.set-default' => ['management', 'Set the default form template'],

        'hr-manager.landings.store'          => ['management', 'Created a recruitment page'],
        'hr-manager.landings.update'         => ['management', 'Edited a recruitment page'],
        'hr-manager.landings.destroy'        => ['management', 'Deleted a recruitment page'],
        'hr-manager.landings.toggle-publish' => ['management', 'Toggled a recruitment page publish state'],

        'hr-manager.settings.update'           => ['management', 'Updated settings'],
        'hr-manager.settings.webhooks.store'   => ['management', 'Added a webhook'],
        'hr-manager.settings.webhooks.update'  => ['management', 'Edited a webhook'],
        'hr-manager.settings.webhooks.destroy' => ['management', 'Deleted a webhook'],
        'hr-manager.settings.tiers.store'      => ['management', 'Added a tier mapping'],
        'hr-manager.settings.tiers.update'     => ['management', 'Edited a tier mapping'],
        'hr-manager.settings.tiers.destroy'    => ['management', 'Deleted a tier mapping'],
        'hr-manager.settings.tiers.defaults'   => ['management', 'Updated tier defaults'],
        'hr-manager.settings.buyback.policy'   => ['management', 'Updated the buyback contribution policy'],
    ];

    // Routes that log themselves with richer context (names, reasons, before-delete
    // state) — the generic write logger skips these to avoid double rows.
    public const SELF_LOGGED_ROUTES = [
        'hr-manager.applications.status',        // ApplicationController: decision + override + notification
        'hr-manager.applications.grant-access',  // ApplicantAccessService::grant
        'hr-manager.watchlist.store',            // WatchlistController: security, with character
        'hr-manager.watchlist.destroy',
        'hr-manager.intel.store',                // IntelController: security, with character
        'hr-manager.intel.destroy',
    ];

    // Mutating routes never worth a row (test/no-op actions).
    public const WRITE_SKIP = [
        'hr-manager.settings.webhooks.test',
        'hr-manager.diagnostic.test-notification',
    ];

    // Non-secret input keys safe to snapshot into a write row's context.
    private const SAFE_INPUT_KEYS = [
        'list_type', 'severity', 'reason', 'role', 'milestone', 'name', 'title',
        'label', 'status', 'tier', 'scope_corporation_id', 'corporation_id', 'template_id',
    ];

    /**
     * Route name → [target_type] for the detail views. The target id is the
     * first route parameter regardless of its name (id / characterId / …).
     * Anything not listed is treated as a list/section view (no target id).
     */
    private const VIEW_DETAIL = [
        'hr-manager.applications.show' => 'application',
        'hr-manager.players.show'      => 'player',
        'hr-manager.members.show'      => 'member',
        'hr-manager.intel.show'        => 'intel',
        'hr-manager.watchlist.dossier' => 'dossier',
        'hr-manager.templates.edit'    => 'template',
        'hr-manager.landings.edit'     => 'landing',
    ];

    // GET routes that are AJAX sub-requests or the audit page itself — never
    // logged as views (they would be noise / recursion).
    private const VIEW_SKIP = [
        'hr-manager.applications.pvp',
        'hr-manager.applications.refresh-assessment',
        'hr-manager.audit.index',
        'hr-manager.audit.export',
        'hr-manager.diagnostic.test-notification',
    ];

    // Target types whose target_id IS an EVE character id (name resolvable
    // directly). NOTE 'player' is deliberately NOT here: a player target_id is a
    // SeAT user_id (the canonical id the player routes use), resolved via
    // getUserNames — not a character id. 'application'/'player' ids are resolved
    // via their own lookups on the audit page instead.
    public const CHARACTER_TARGETS = ['member', 'intel', 'dossier'];

    public function isEnabled(): bool
    {
        return (bool) Setting::getValue(self::SETTING_ENABLED, config('hr-manager.features.enable_audit_log', false));
    }

    /**
     * Low-level append. Never throws — a failed audit write must not surface to
     * the user or abort their action.
     */
    public function record(array $attrs): void
    {
        if (!Schema::hasTable('hr_manager_audit_log')) {
            return;
        }

        try {
            $attrs['occurred_at'] = $attrs['occurred_at'] ?? now();
            AuditLog::create($attrs);
        } catch (\Throwable $e) {
            // Intentionally swallowed; auditing is never allowed to break a request.
        }
    }

    /**
     * View row from an internal GET request. Cheap: reads the matched route +
     * actor from the session only, no DB look-ups. Called by the middleware.
     */
    public function logView(Request $request): void
    {
        if (!$this->isEnabled() || !$request->isMethod('GET')) {
            return;
        }

        $route = $request->route();
        $name  = $route?->getName();
        if (!$name || !str_starts_with($name, 'hr-manager.') || in_array($name, self::VIEW_SKIP, true)) {
            return;
        }

        [$userId, $charId] = $this->actor();
        if (!$userId) {
            return; // no authenticated actor — nothing meaningful to attribute
        }

        $isDetail   = array_key_exists($name, self::VIEW_DETAIL);
        $targetType = $isDetail ? self::VIEW_DETAIL[$name] : $this->sectionOf($name);
        $targetId   = null;
        if ($isDetail) {
            $params = $route->parameters();
            $first  = $params ? reset($params) : null;
            $targetId = is_numeric($first) ? (int) $first : null;
        }

        // Light context worth capturing off the query string (which corp / tab a
        // director was looking at, what they searched) — no DB work. Corp Health
        // names its tab param `ch_tab`; fold it into the generic `tab` chip.
        $context = ['route' => $name];
        foreach (['tab', 'ch_tab', 'corp', 'corporation_id', 'status', 'q', 'filter'] as $key) {
            $val = $request->query($key);
            if ($val !== null && $val !== '') {
                $ctxKey = $key === 'ch_tab' ? 'tab' : $key;
                $context[$ctxKey] = is_scalar($val) ? (string) $val : $val;
            }
        }

        $this->record([
            'actor_user_id'      => $userId,
            'actor_character_id' => $charId,
            'action'             => 'view',
            'category'           => self::CAT_VIEW,
            'target_type'        => $targetType,
            'target_id'          => $targetId,
            'summary'            => $this->viewSummary($name, $isDetail, $targetType),
            'context'            => $context,
            'ip_hash'            => $this->ipHash($request),
        ]);
    }

    /**
     * Generic write-action row from a mutating request. Called by the
     * AuditViewLogger middleware AFTER it has confirmed the request succeeded
     * (see the middleware for the success gate). Uses WRITE_ACTIONS for a clean
     * category + summary, falling back to a humanised label, so no per-controller
     * call site is needed for the long tail of actions.
     */
    public function logAction(Request $request): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        $route = $request->route();
        $name  = $route?->getName();
        if (!$name || !str_starts_with($name, 'hr-manager.')) {
            return;
        }
        if (in_array($name, self::WRITE_SKIP, true) || in_array($name, self::SELF_LOGGED_ROUTES, true)) {
            return; // never logged here, or logged with richer context at the call site
        }

        [$userId, $charId] = $this->actor();
        if (!$userId) {
            return;
        }

        [$category, $summary] = self::WRITE_ACTIONS[$name] ?? [self::CAT_MANAGEMENT, $this->humanizeAction($name)];

        $section  = $this->sectionOf($name);
        $params   = $route->parameters();
        $first    = $params ? reset($params) : null;
        $targetId = is_numeric($first) ? (int) $first : null;

        $context = ['route' => $name, 'method' => $request->method()];
        foreach (self::SAFE_INPUT_KEYS as $key) {
            $val = $request->input($key);
            if ($val !== null && $val !== '' && is_scalar($val)) {
                $context[$key] = mb_substr((string) $val, 0, 120);
            }
        }

        $this->record([
            'actor_user_id'      => $userId,
            'actor_character_id' => $charId,
            'action'             => substr($name, strlen('hr-manager.')),
            'category'           => $category,
            'target_type'        => $this->writeTargetType($section),
            'target_id'          => $targetId,
            'summary'            => $summary,
            'context'            => $context,
            'ip_hash'            => $this->ipHash($request),
        ]);
    }

    /**
     * Explicit action row (privilege / decision / security / notification).
     * Resolves the actor from auth unless one is supplied (system/cron events).
     *
     * @param array{
     *   target_type?:string, target_id?:int|null, target_label?:string|null,
     *   summary?:string|null, context?:array|null, corporation_id?:int|null,
     *   actor_user_id?:int|null, actor_character_id?:int|null, actor_name?:string|null
     * } $opts
     */
    public function action(string $action, string $category, array $opts = []): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        [$userId, $charId] = $this->actor();

        $this->record([
            'actor_user_id'      => $opts['actor_user_id']      ?? $userId,
            'actor_character_id' => $opts['actor_character_id'] ?? $charId,
            'actor_name'         => $opts['actor_name']         ?? null,
            'action'             => $action,
            'category'           => $category,
            'target_type'        => $opts['target_type']   ?? null,
            'target_id'          => $opts['target_id']     ?? null,
            'target_label'       => $opts['target_label']  ?? null,
            'summary'            => $opts['summary']        ?? null,
            'context'            => $opts['context']        ?? null,
            'corporation_id'     => $opts['corporation_id'] ?? null,
        ]);
    }

    /**
     * System / plugin-initiated action (no human actor) — e.g. the HR
     * Intelligence auto-actions or the prune job. Attributed to "HR Intelligence".
     */
    public function system(string $action, string $category, array $opts = []): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->record([
            'actor_user_id'      => null,
            'actor_character_id' => null,
            'actor_name'         => $opts['actor_name'] ?? 'HR Intelligence',
            'action'             => $action,
            'category'           => $category,
            'target_type'        => $opts['target_type']   ?? null,
            'target_id'          => $opts['target_id']     ?? null,
            'target_label'       => $opts['target_label']  ?? null,
            'summary'            => $opts['summary']        ?? null,
            'context'            => $opts['context']        ?? null,
            'corporation_id'     => $opts['corporation_id'] ?? null,
        ]);
    }

    /**
     * Delete rows past the retention window. Returns rows removed. Safe to call
     * when the feature is off (retention still applies so a disabled log drains).
     */
    public function prune(?int $days = null): int
    {
        if (!Schema::hasTable('hr_manager_audit_log')) {
            return 0;
        }

        $days   = $days ?? self::RETENTION_DAYS;
        $cutoff = Carbon::now()->subDays(max(1, $days));

        try {
            return (int) AuditLog::where('occurred_at', '<', $cutoff)->delete();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Filterable, paginated query for the audit page. Filters: category, action,
     * actor_user_id, target_type, from, to, q (summary/label search).
     */
    public function query(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        $q = AuditLog::query()->orderByDesc('occurred_at')->orderByDesc('id');

        if (!empty($filters['category']) && in_array($filters['category'], AuditLog::CATEGORIES, true)) {
            $q->where('category', $filters['category']);
        }
        if (!empty($filters['action'])) {
            $q->where('action', $filters['action']);
        }
        if (!empty($filters['actor_user_id'])) {
            $q->where('actor_user_id', (int) $filters['actor_user_id']);
        }
        if (!empty($filters['target_type'])) {
            $q->where('target_type', $filters['target_type']);
        }
        if (!empty($filters['from'])) {
            try { $q->where('occurred_at', '>=', Carbon::parse($filters['from'])->startOfDay()); } catch (\Throwable $e) {}
        }
        if (!empty($filters['to'])) {
            try { $q->where('occurred_at', '<=', Carbon::parse($filters['to'])->endOfDay()); } catch (\Throwable $e) {}
        }
        if (!empty($filters['q'])) {
            $needle = trim($filters['q']);
            $q->where(function ($w) use ($needle) {
                $w->where('summary', 'like', "%{$needle}%")
                  ->orWhere('target_label', 'like', "%{$needle}%")
                  ->orWhere('actor_name', 'like', "%{$needle}%");
            });
        }

        return $q->paginate($perPage)->withQueryString();
    }

    /** Distinct action names present, for the filter dropdown. */
    public function knownActions(): array
    {
        if (!Schema::hasTable('hr_manager_audit_log')) {
            return [];
        }
        return AuditLog::query()->distinct()->orderBy('action')->pluck('action')->all();
    }

    // ---- internals -------------------------------------------------------

    /** @return array{0:?int,1:?int} [user_id, main_character_id] */
    private function actor(): array
    {
        $user = auth()->user();
        if (!$user) {
            return [null, null];
        }
        return [(int) $user->id, $user->main_character_id ? (int) $user->main_character_id : null];
    }

    /** 'hr-manager.applications.show' → 'applications'. */
    private function sectionOf(string $routeName): string
    {
        $tail = substr($routeName, strlen('hr-manager.'));
        $seg  = explode('.', $tail)[0];
        return $seg ?: 'dashboard';
    }

    /**
     * Target type for a generic write row, from the route section. Write routes
     * carry these id kinds: a members-* write takes a character id (resolvable
     * directly), an applications-* write takes an application id (resolved via
     * the applications join), and a players-* write takes a SeAT user_id — the
     * canonical id the player routes use — resolved via getUserNames (NOT a
     * character id). Anything else keeps its section name (renders as
     * "section #id").
     */
    private function writeTargetType(string $section): string
    {
        return match ($section) {
            'members'      => 'member',       // {characterId} — a character id
            'applications' => 'application',  // {id} — application id (join)
            'players'      => 'player',       // {id} — SeAT user_id (getUserNames)
            default        => $section,
        };
    }

    /** Fallback label for an unmapped write route: 'foo.bar-baz' → 'Foo bar baz'. */
    private function humanizeAction(string $routeName): string
    {
        $tail = substr($routeName, strlen('hr-manager.'));
        return ucfirst(trim(str_replace(['.', '-', '_'], ' ', $tail)));
    }

    private function viewSummary(string $routeName, bool $isDetail, string $targetType): string
    {
        if ($isDetail) {
            return 'Viewed ' . str_replace('_', ' ', $targetType);
        }

        $tail = substr($routeName, strlen('hr-manager.'));
        return match ($tail) {
            'dashboard'          => 'Viewed dashboard',
            'applications.index' => 'Viewed applications list',
            'players.index'      => 'Viewed players list',
            'members.index'      => 'Viewed members list',
            'intel.index'        => 'Viewed intel database',
            'watchlist.index'    => 'Viewed watchlist',
            'corp-health.index'  => 'Viewed corp health',
            'settings.index'     => 'Viewed settings',
            'diagnostic'         => 'Viewed diagnostics',
            'templates.index'    => 'Viewed templates',
            'landings.index'     => 'Viewed landing pages',
            'help'               => 'Viewed help',
            default              => 'Viewed ' . str_replace(['.', '-'], ' ', $tail),
        };
    }

    /** Salted daily-rotating hash so an IP is traceable within a day but not stored raw. */
    private function ipHash(Request $request): ?string
    {
        $ip = $request->ip();
        if (!$ip) {
            return null;
        }
        return substr(hash('sha256', $ip . '|' . config('app.key') . '|' . now()->toDateString()), 0, 32);
    }
}
