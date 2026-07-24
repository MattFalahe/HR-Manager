<?php

namespace HrManager\Http\Controllers;

use HrManager\Models\AuditLog;
use HrManager\Services\AuditService;
use HrManager\Services\NameResolutionService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Director-only activity / audit log. Reads the append-only hr_manager_audit_log
 * (populated by the AuditViewLogger middleware + explicit AuditService::action
 * calls) and renders it filtered + paginated. Actor + target NAMES are resolved
 * here in batch (50 rows/page) rather than being stored per write, so the write
 * path stays cheap.
 *
 * The whole feature is opt-in; when off the page renders a discovery/enable
 * state instead of the table.
 */
class AuditController extends Controller
{
    public function index(Request $request)
    {
        $audit = app(AuditService::class);

        if (!$audit->isEnabled()) {
            return view('hr-manager::audit.index', ['enabled' => false]);
        }

        $filters = $this->filters($request);
        $logs    = $audit->query($filters, 50);

        $this->hydrateLabels($logs->getCollection());

        return view('hr-manager::audit.index', [
            'enabled'     => true,
            'logs'        => $logs,
            'filters'     => $filters,
            'actors'      => $this->actorOptions(),
            'actions'     => $audit->knownActions(),
            'categories'  => AuditLog::CATEGORIES,
            'corpNames'   => $this->resolveCorpNames($logs->getCollection()),
            'retention'   => AuditService::RETENTION_DAYS,
        ]);
    }

    /**
     * Resolve every corporation id referenced by this page of rows (the
     * corporation_id column + context corp / corporation_id) to a name, so the
     * chips read "corp: Mercurialis Inc." instead of a bare id.
     *
     * @return array<int,string> corporation_id => name
     */
    private function resolveCorpNames($rows): array
    {
        $ids = [];
        foreach ($rows as $r) {
            if ($r->corporation_id) {
                $ids[] = (int) $r->corporation_id;
            }
            $ctx = is_array($r->context) ? $r->context : [];
            foreach (['corp', 'corporation_id'] as $key) {
                if (!empty($ctx[$key]) && is_numeric($ctx[$key])) {
                    $ids[] = (int) $ctx[$key];
                }
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if (empty($ids) || !Schema::hasTable('corporation_infos')) {
            return [];
        }

        return \Seat\Eveapi\Models\Corporation\CorporationInfo::whereIn('corporation_id', $ids)
            ->pluck('name', 'corporation_id')->toArray();
    }

    /**
     * Stream the current filter as CSV. Capped so a director can't accidentally
     * pull the entire table into memory.
     */
    public function export(Request $request): StreamedResponse
    {
        $audit = app(AuditService::class);
        abort_unless($audit->isEnabled(), 404);

        $filters = $this->filters($request);

        // Reuse the same filtered query, but stream ordered chunks rather than
        // paginate. Cap total rows for safety.
        $cap = 10000;

        $filename = 'hr-activity-log-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($filters, $cap) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['occurred_at', 'actor', 'action', 'category', 'target_type', 'target', 'summary', 'route', 'ip_hash']);

            $written = 0;
            $this->baseQuery($filters)->orderByDesc('occurred_at')->orderByDesc('id')
                ->chunk(500, function ($rows) use ($out, &$written, $cap) {
                    $this->hydrateLabels($rows);
                    foreach ($rows as $r) {
                        if ($written >= $cap) {
                            return false;
                        }
                        fputcsv($out, [
                            optional($r->occurred_at)->toDateTimeString(),
                            $r->display_actor ?? '',
                            $r->action,
                            $r->category,
                            $r->target_type ?? '',
                            $r->display_target ?? '',
                            $r->summary ?? '',
                            is_array($r->context) ? ($r->context['route'] ?? '') : '',
                            $r->ip_hash ?? '',
                        ]);
                        $written++;
                    }
                    return true;
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    // ---- internals -------------------------------------------------------

    private function filters(Request $request): array
    {
        return [
            'category'      => $request->query('category'),
            'action'        => $request->query('action'),
            'actor_user_id' => $request->query('actor'),
            'target_type'   => $request->query('target_type'),
            'from'          => $request->query('from'),
            'to'            => $request->query('to'),
            'q'             => $request->query('q'),
        ];
    }

    /** Mirror of AuditService::query() filter logic for the export stream. */
    private function baseQuery(array $filters)
    {
        $q = AuditLog::query();

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
            try { $q->where('occurred_at', '>=', \Illuminate\Support\Carbon::parse($filters['from'])->startOfDay()); } catch (\Throwable $e) {}
        }
        if (!empty($filters['to'])) {
            try { $q->where('occurred_at', '<=', \Illuminate\Support\Carbon::parse($filters['to'])->endOfDay()); } catch (\Throwable $e) {}
        }
        if (!empty($filters['q'])) {
            $needle = trim($filters['q']);
            $q->where(function ($w) use ($needle) {
                $w->where('summary', 'like', "%{$needle}%")
                  ->orWhere('target_label', 'like', "%{$needle}%")
                  ->orWhere('actor_name', 'like', "%{$needle}%");
            });
        }

        return $q;
    }

    /**
     * Attach display_actor + display_target to each row in the collection with a
     * single batch of name look-ups.
     *
     * @param \Illuminate\Support\Collection<AuditLog> $rows
     */
    private function hydrateLabels($rows): void
    {
        if ($rows->isEmpty()) {
            return;
        }

        // 1a) application target ids → character id (one join)
        $appIds = $rows->where('target_type', 'application')
            ->whereNull('target_label')
            ->pluck('target_id')->filter()->unique()->values()->all();

        $appToChar = [];
        if (!empty($appIds) && Schema::hasTable('hr_manager_applications')) {
            $appToChar = DB::table('hr_manager_applications')
                ->whereIn('id', $appIds)
                ->pluck('character_id', 'id')->toArray();
        }

        // 1b) player target ids → main character NAME. A 'player' target_id is a
        // SeAT user_id (the canonical id the player routes use), so it resolves
        // via getUserNames, not the character-name path.
        $playerUserIds = $rows->where('target_type', 'player')
            ->whereNull('target_label')
            ->pluck('target_id')->filter()->unique()->values()->all();

        $userNames = !empty($playerUserIds)
            ? app(NameResolutionService::class)->getUserNames(array_map('intval', $playerUserIds))
            : [];

        // 2) collect every character id we need a name for
        $charIds = [];
        foreach ($rows as $r) {
            if ($r->actor_character_id && !$r->actor_name) {
                $charIds[] = (int) $r->actor_character_id;
            }
            if ($r->target_label) {
                continue;
            }
            if (in_array($r->target_type, AuditService::CHARACTER_TARGETS, true) && $r->target_id) {
                $charIds[] = (int) $r->target_id;
            } elseif ($r->target_type === 'application' && isset($appToChar[$r->target_id])) {
                $charIds[] = (int) $appToChar[$r->target_id];
            }
        }
        $charIds = array_values(array_unique(array_filter($charIds)));

        $names = !empty($charIds)
            ? app(NameResolutionService::class)->getCharacterNamesWithFallback($charIds)
            : [];

        // 3) stamp display fields
        foreach ($rows as $r) {
            $r->display_actor = $r->actor_name
                ?: ($r->actor_character_id ? ($names[(int) $r->actor_character_id] ?? ('#' . $r->actor_character_id))
                    : ($r->actor_user_id ? ('User #' . $r->actor_user_id) : 'System'));

            $r->display_target     = $this->targetLabel($r, $names, $appToChar, $userNames);
            $r->display_target_url = $this->targetUrl($r);
        }
    }

    /** A deep link back to the referenced record, when one exists (may 404 if pruned). */
    private function targetUrl(AuditLog $r): ?string
    {
        if (!$r->target_id) {
            return null;
        }
        try {
            return match ($r->target_type) {
                'application' => route('hr-manager.applications.show', $r->target_id),
                'player'      => route('hr-manager.players.show', $r->target_id), // user_id = canonical
                'member'      => route('hr-manager.members.show', $r->target_id),
                'intel'       => route('hr-manager.intel.show', $r->target_id),
                'dossier'     => route('hr-manager.watchlist.dossier', $r->target_id),
                default       => null,
            };
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function targetLabel(AuditLog $r, array $names, array $appToChar, array $userNames = []): ?string
    {
        if ($r->target_label) {
            return $r->target_label;
        }
        if (!$r->target_type) {
            return null;
        }
        if ($r->target_type === 'player' && $r->target_id) {
            return $userNames[(int) $r->target_id] ?? ('User #' . $r->target_id);
        }
        if (in_array($r->target_type, AuditService::CHARACTER_TARGETS, true) && $r->target_id) {
            return $names[(int) $r->target_id] ?? ('#' . $r->target_id);
        }
        if ($r->target_type === 'application' && isset($appToChar[$r->target_id])) {
            $cid = (int) $appToChar[$r->target_id];
            return ($names[$cid] ?? ('#' . $cid)) . ' (application)';
        }
        if ($r->target_id) {
            return ucfirst(str_replace(['_', '-'], ' ', $r->target_type)) . ' #' . $r->target_id;
        }
        // List/section view — no id.
        return null;
    }

    /**
     * Distinct actors present in the log, for the filter dropdown.
     * @return array<int,string> user_id => name
     */
    private function actorOptions(): array
    {
        if (!Schema::hasTable('hr_manager_audit_log')) {
            return [];
        }

        $rows = AuditLog::query()
            ->whereNotNull('actor_user_id')
            ->select('actor_user_id', 'actor_character_id')
            ->distinct()
            ->limit(200)
            ->get();

        $charIds = $rows->pluck('actor_character_id')->filter()->unique()->values()->all();
        $names   = !empty($charIds)
            ? app(NameResolutionService::class)->getCharacterNamesWithFallback($charIds)
            : [];

        $out = [];
        foreach ($rows as $row) {
            $uid = (int) $row->actor_user_id;
            $out[$uid] = $row->actor_character_id
                ? ($names[(int) $row->actor_character_id] ?? ('User #' . $uid))
                : ('User #' . $uid);
        }
        asort($out);
        return $out;
    }
}
