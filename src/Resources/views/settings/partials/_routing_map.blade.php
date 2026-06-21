{{--
    Notification Routing Map — read-only delivery snapshot.

    For every notification category it shows each webhook that has the
    category enabled and the Discord role the webhook pings. Mirrors
    Structure Manager's Routing Map pattern (each plugin owns the
    data shape; partials live per-plugin).

    HR Manager's webhook model is FLAT: each WebhookConfiguration row
    carries the per-category bool (`notify_application_submitted`,
    `notify_purge_reminder`, etc.) plus a single `discord_role_id`. No
    binding pivot, no precedence chain. The map just resolves "which
    webhooks have this category enabled" + "what role does each ping"
    into a single table.

    Required variables:
      $routingCategories  — grouped category metadata (label + items)
      $webhooks           — collection of WebhookConfiguration
      $corpNameLookup     — corp_id => name for corp-scoped webhooks
      $discordRoleMap     — DiscordRoleResolver::roleLookupMap() result
      $discordRolesProvider — provider label string (or null)
--}}
<style>
    .hr-routing-map .routing-summary {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin: 0.8rem 0 1.1rem;
    }
    .hr-routing-map .routing-stat {
        background: #1e222b;
        border: 1px solid #3a4049;
        border-radius: 6px;
        padding: 0.45rem 0.8rem;
        font-size: 0.8rem;
        color: #c2c7d0;
    }
    .hr-routing-map .routing-stat strong {
        color: #fff;
        font-size: 1.05rem;
        margin-right: 4px;
    }
    .hr-routing-map .routing-stat.warn {
        background: #3a2e16;
        border-color: #6b5424;
        color: #d4c69a;
    }
    .hr-routing-map .routing-stat.warn strong { color: #ffd96a; }

    .hr-routing-map .routing-group-label {
        font-size: 0.74rem;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        color: #8b95a5;
        font-weight: 600;
        margin: 1.1rem 0 0.4rem;
    }
    .hr-routing-map .routing-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.83rem;
        margin-bottom: 0.4rem;
    }
    .hr-routing-map .routing-table th {
        text-align: left;
        color: #8b95a5;
        font-weight: 500;
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 0.35rem 0.6rem;
        border-bottom: 1px solid #3a4049;
    }
    .hr-routing-map .routing-table td {
        padding: 0.5rem 0.6rem;
        border-bottom: 1px solid #2a2f3a;
        vertical-align: top;
    }
    .hr-routing-map .routing-table tr:last-child td { border-bottom: none; }
    .hr-routing-map .routing-cat-cell {
        background: #20242e;
        border-right: 1px solid #313845;
        min-width: 200px;
    }
    .hr-routing-map .routing-cat-name { color: #fff; font-weight: 600; }
    .hr-routing-map .routing-cat-key {
        font-size: 0.69rem;
        color: #666c76;
        font-family: 'Courier New', monospace;
        margin-top: 2px;
    }
    .hr-routing-map .routing-row-orphan { opacity: 0.7; }
    .hr-routing-map .routing-orphan-pill {
        display: inline-block;
        font-size: 0.7rem;
        background: rgba(245, 158, 11, 0.15);
        color: #fdba74;
        border: 1px solid rgba(245, 158, 11, 0.4);
        padding: 1px 8px;
        border-radius: 999px;
    }
    .hr-routing-map .routing-webhook-row {
        display: flex;
        flex-direction: column;
        gap: 4px;
        padding: 4px 0;
        border-bottom: 1px dashed rgba(255,255,255,0.06);
    }
    .hr-routing-map .routing-webhook-row:last-child { border-bottom: none; }
    .hr-routing-map .routing-webhook-name {
        font-weight: 600;
        color: #e2e8f0;
    }
    .hr-routing-map .routing-webhook-meta {
        font-size: 0.74rem;
        color: #8b95a5;
    }
    .hr-routing-map .routing-webhook-meta .corp-scope {
        color: #c4b3e8;
    }
    .hr-routing-map .routing-webhook-meta .global-scope {
        color: #6ee7b7;
    }
    .hr-routing-map .routing-role-pill {
        display: inline-block;
        background: rgba(102, 126, 234, 0.2);
        color: #c7d2fe;
        border: 1px solid rgba(102, 126, 234, 0.4);
        padding: 1px 8px;
        border-radius: 999px;
        font-size: 0.72rem;
        font-weight: 500;
    }
    .hr-routing-map .routing-role-pill.no-role {
        background: rgba(255,255,255,0.05);
        color: #8b95a5;
        border-color: rgba(255,255,255,0.1);
    }
    .hr-routing-map .routing-disabled-pill {
        display: inline-block;
        background: rgba(239, 68, 68, 0.15);
        color: #fca5a5;
        border: 1px solid rgba(239, 68, 68, 0.4);
        padding: 1px 6px;
        border-radius: 4px;
        font-size: 0.65rem;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        margin-left: 4px;
    }
</style>

<div class="hr-routing-map">
    @php
        // Pre-compute per-category bindings + counts so the summary
        // chips above the table don't re-walk webhooks per render.
        $perCategory = [];
        $totalBindings = 0;
        $orphanCount = 0;

        foreach ($routingCategories as $groupKey => $group) {
            foreach ($group['items'] as $cat) {
                $bound = $webhooks->filter(fn($w) => (bool) ($w->{$cat['key']} ?? false))->values();
                $perCategory[$cat['key']] = $bound;
                $totalBindings += $bound->count();
                if ($bound->isEmpty()) $orphanCount++;
            }
        }

        $enabledWebhookCount = $webhooks->filter(fn($w) => $w->is_enabled)->count();
    @endphp

    <p class="text-muted" style="margin-bottom: 0.6rem;">
        {{ trans('hr-manager::settings.routing_intro') }}
    </p>

    {{-- Summary chips at the top — at-a-glance "is anything broken". --}}
    <div class="routing-summary">
        <div class="routing-stat">
            <strong>{{ $webhooks->count() }}</strong> {{ trans('hr-manager::settings.routing_stat_webhooks') }}
        </div>
        <div class="routing-stat">
            <strong>{{ $enabledWebhookCount }}</strong> {{ trans('hr-manager::settings.routing_stat_enabled') }}
        </div>
        <div class="routing-stat">
            <strong>{{ $totalBindings }}</strong> {{ trans('hr-manager::settings.routing_stat_bindings') }}
        </div>
        @if($orphanCount > 0)
            <div class="routing-stat warn">
                <strong>{{ $orphanCount }}</strong> {{ trans('hr-manager::settings.routing_stat_orphans') }}
            </div>
        @endif
    </div>

    @foreach($routingCategories as $groupKey => $group)
        <div class="routing-group-label">{{ $group['label'] }}</div>
        <table class="routing-table">
            <thead>
                <tr>
                    <th style="width: 220px;">{{ trans('hr-manager::settings.routing_col_category') }}</th>
                    <th>{{ trans('hr-manager::settings.routing_col_webhooks') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($group['items'] as $cat)
                    @php $bound = $perCategory[$cat['key']]; @endphp
                    <tr class="{{ $bound->isEmpty() ? 'routing-row-orphan' : '' }}">
                        <td class="routing-cat-cell">
                            <div class="routing-cat-name">{{ $cat['label'] }}</div>
                            <div class="routing-cat-key">{{ $cat['key'] }}</div>
                        </td>
                        <td>
                            @if($bound->isEmpty())
                                <span class="routing-orphan-pill">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    {{ trans('hr-manager::settings.routing_no_binding') }}
                                </span>
                            @else
                                @foreach($bound as $webhook)
                                    @php
                                        $roleId = $webhook->discord_role_id ?: null;
                                        $roleLabel = null;
                                        if ($roleId && isset($discordRoleMap[$roleId])) {
                                            $roleLabel = '@' . $discordRoleMap[$roleId];
                                        } elseif ($roleId) {
                                            $roleLabel = $roleId;
                                        }
                                    @endphp
                                    <div class="routing-webhook-row">
                                        <div class="routing-webhook-name">
                                            {{ $webhook->name }}
                                            @if(!$webhook->is_enabled)
                                                <span class="routing-disabled-pill">{{ trans('hr-manager::settings.routing_disabled') }}</span>
                                            @endif
                                        </div>
                                        <div class="routing-webhook-meta">
                                            <span class="{{ $webhook->corporation_id ? 'corp-scope' : 'global-scope' }}">
                                                @if($webhook->corporation_id)
                                                    <i class="fas fa-building"></i>
                                                    {{ $corpNameLookup[$webhook->corporation_id] ?? ('Corp #' . $webhook->corporation_id) }}
                                                @else
                                                    <i class="fas fa-globe"></i>
                                                    {{ trans('hr-manager::settings.routing_scope_global') }}
                                                @endif
                                            </span>
                                            ·
                                            <i class="fa{{ $webhook->type === 'slack' ? 'b fa-slack' : 'b fa-discord' }}"></i>
                                            {{ ucfirst($webhook->type) }}
                                            @if($roleLabel)
                                                <span class="routing-role-pill" style="margin-left: 6px;">
                                                    <i class="fas fa-at"></i> {{ $roleLabel }}
                                                </span>
                                            @else
                                                <span class="routing-role-pill no-role" style="margin-left: 6px;">
                                                    {{ trans('hr-manager::settings.routing_no_role') }}
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach

    <small class="text-muted d-block mt-3">
        {{ trans('hr-manager::settings.routing_footnote') }}
    </small>
</div>
