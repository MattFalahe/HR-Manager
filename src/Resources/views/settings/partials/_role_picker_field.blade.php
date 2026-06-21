{{--
    Reusable Discord role picker INPUT field — AJAX-lazy-load variant.

    Mirrors the Mining Manager / Structure Manager picker pattern so the
    UX is identical across every plugin in Matt's suite. Compared to the
    old inline-render pattern this one:

      - Loads zero roles on initial page render (vs N roles × M pickers
        previously baked into the HTML)
      - Fetches the merged role list ONCE via AJAX on first
        "Pick from Discord" click, shares the cache across every picker
        on the page
      - Shows per-source badges (SeAT Broadcast / SeAT Connector /
        legacy warlof) so operators see where each role came from
      - Per-source filter dropdown when more than one provider exists
      - Text search across role name + ID

    REQUIRED params:
      $name        - form input name (e.g. 'discord_role_id')
      $id          - DOM id for the input (must be unique per page)
      $pickerId    - DOM id for the picker container (must be unique per page)

    OPTIONAL params:
      $value       - prefilled value (defaults to '')
      $placeholder - input placeholder (defaults to '123456789012345678')
      $helpText    - small-text hint under the field (raw HTML allowed)
      $disabled    - boolean, applies opacity + disables interaction

    No longer takes $roles / $provider — the picker fetches from
    hr-manager.settings.roles JSON endpoint on demand.

    See: feedback_plugin_role_picker_pattern memory — every role-mention
    surface in the suite uses this pattern.
--}}
@php
    $value       = $value ?? '';
    $placeholder = $placeholder ?? '123456789012345678';
    $helpText    = $helpText ?? '';
    $disabled    = $disabled ?? false;
    $providerAvailable = \HrManager\Services\DiscordRoleResolver::isAvailable();
    $providerLabel     = \HrManager\Services\DiscordRoleResolver::providerLabel();
@endphp

<div class="input-group hr-role-picker-group">
    <input type="text"
           id="{{ $id }}"
           name="{{ $name }}"
           class="form-control hr-role-id-input"
           value="{{ $value }}"
           placeholder="{{ $placeholder }}"
           pattern="\d{1,20}"
           autocomplete="off"
           {{ $disabled ? 'disabled' : '' }}
           style="{{ $disabled ? 'opacity: 0.5;' : '' }}">
    @if($providerAvailable)
        <div class="input-group-append">
            <button type="button"
                    class="btn btn-hr-secondary js-hr-toggle-role-picker"
                    data-picker-id="{{ $pickerId }}"
                    data-input-id="{{ $id }}"
                    title="{{ trans('hr-manager::settings.webhook_role_picker') }} ({{ $providerLabel }})"
                    {{ $disabled ? 'disabled' : '' }}
                    style="white-space: nowrap; {{ $disabled ? 'opacity: 0.5;' : '' }}">
                <i class="fas fa-tag"></i> {{ trans('hr-manager::settings.webhook_role_picker') }}
            </button>
        </div>
    @endif
</div>

@if($providerAvailable)
    {{-- Lazy-loaded picker body. Hidden by default; slides down on first
         click + fetches from the JSON endpoint then. Cache is shared
         across every picker on the page so subsequent pickers open
         instantly. --}}
    <div id="{{ $pickerId }}" class="hr-inline-role-picker mt-2"
         data-input-id="{{ $id }}"
         style="display: none; padding: 10px; background: #1e222b;
                border: 1px solid #454d55; border-radius: 4px;
                max-height: 380px; overflow-y: auto;">
        <div class="hr-inline-role-picker-body">
            <div style="text-align: center; color: #8b95a5; padding: 0.8rem;">
                <i class="fas fa-spinner fa-spin"></i>
                {{ trans('hr-manager::settings.webhook_role_picker_loading') }}
            </div>
        </div>
    </div>
@endif

@if($helpText)
    <small class="form-text" style="color: var(--hr-text-muted, #9ca3af);">{!! $helpText !!}</small>
@endif

@if(!$providerAvailable)
    @php
        $checked = [
            'discord_roles'                  => 'SeAT Broadcast (mattfalahe/seat-discord-pings)',
            'seat_connector_sets'            => 'SeAT Connector (warlof/seat-connector)',
            'warlof_discord_connector_roles' => 'Warlof Discord Connector (legacy)',
            'discord_connector_roles'        => 'Discord Connector (legacy)',
        ];
        $missing = [];
        foreach ($checked as $table => $label) {
            if (!\Illuminate\Support\Facades\Schema::hasTable($table)) {
                $missing[$table] = $label;
            }
        }
    @endphp
    <small class="form-text" style="color: var(--hr-text-muted, #9ca3af);">
        <em>{{ trans('hr-manager::settings.webhook_role_picker_unavailable') }}</em>
        <br><span style="opacity: 0.8;">No provider table found. Install one of:
            @foreach($missing as $t => $l)
                <code>{{ $t }}</code>@if(!$loop->last), @endif
            @endforeach
        </span>
    </small>
@endif

@once
@push('javascript')
<script>
(function () {
    // -----------------------------------------------------------------
    // AJAX-lazy-load role picker — mirrors MM/SM exactly so the same JS
    // pattern works across the plugin suite. Shared cache means opening
    // any picker after the first is instant.
    // -----------------------------------------------------------------
    @if(\HrManager\Services\DiscordRoleResolver::isAvailable())
    const HR_ROLE_LIST_URL = {!! json_encode(route('hr-manager.settings.roles', [], false), JSON_UNESCAPED_SLASHES) !!};
    @endif

    const SOURCE_LABELS = {
        'discord-roles-table': 'SeAT Broadcast',
        'seat-connector':      'SeAT Connector',
        'warlof-discord':      'Warlof (legacy)',
    };
    const SOURCE_COLORS = {
        'discord-roles-table': '#28a745',
        'seat-connector':      '#3498db',
        'warlof-discord':      '#95a5a6',
    };

    let rolesCache = null;
    const loadedFor = {};

    function rolesUrl() {
        return typeof HR_ROLE_LIST_URL === 'string' ? HR_ROLE_LIST_URL : null;
    }

    document.addEventListener('click', function (e) {
        const toggleBtn = e.target.closest('.js-hr-toggle-role-picker');
        if (toggleBtn) {
            e.preventDefault();
            const pickerId = toggleBtn.getAttribute('data-picker-id');
            const inputId  = toggleBtn.getAttribute('data-input-id');
            const picker   = document.getElementById(pickerId);
            if (!picker) return;

            if (picker.style.display !== 'none') {
                picker.style.display = 'none';
                return;
            }

            // Remember which input gets the value when an item is clicked.
            window.hrActiveRoleTarget = document.getElementById(inputId);
            picker.style.display = 'block';

            if (loadedFor[pickerId]) return;
            if (rolesCache) {
                renderPickerBody(picker.querySelector('.hr-inline-role-picker-body'), rolesCache, pickerId);
                loadedFor[pickerId] = true;
                return;
            }
            loadRoles(pickerId);
            return;
        }

        const pickItem = e.target.closest('.js-hr-role-pick-item');
        if (pickItem) {
            e.preventDefault();
            const roleId = pickItem.getAttribute('data-role-id');
            if (window.hrActiveRoleTarget) {
                window.hrActiveRoleTarget.value = roleId;
                window.hrActiveRoleTarget.dispatchEvent(new Event('input', { bubbles: true }));
                window.hrActiveRoleTarget.dispatchEvent(new Event('blur'));
            }
            document.querySelectorAll('.hr-inline-role-picker').forEach(function (p) {
                p.style.display = 'none';
            });
            return;
        }
    });

    function loadRoles(pickerId) {
        const url = rolesUrl();
        if (!url) return;
        const picker = document.getElementById(pickerId);
        const body   = picker.querySelector('.hr-inline-role-picker-body');

        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                rolesCache = res;
                renderPickerBody(body, res, pickerId);
                loadedFor[pickerId] = true;
            })
            .catch(function () {
                body.innerHTML = '<div class="alert alert-danger mb-0" style="font-size: 0.85em;">Failed to load roles from Discord provider(s).</div>';
            });
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    function renderPickerBody(body, res, pickerId) {
        if (!res.roles || res.roles.length === 0) {
            body.innerHTML =
                '<div class="alert alert-warning mb-0" style="font-size: 0.85em;">'
                + '<strong>No roles returned from ' + escapeHtml(res.label || 'provider') + '.</strong><br>'
                + 'Enter the role ID manually above.'
                + '</div>';
            return;
        }

        const perSource = {};
        res.roles.forEach(function (r) { perSource[r.source] = (perSource[r.source] || 0) + 1; });
        const filterId = pickerId + '-filter';
        const sourceFilterId = pickerId + '-source-filter';
        const listId = pickerId + '-list';

        let html = '';
        html += '<div style="font-size: 0.78rem; color: #8b95a5; margin-bottom: 0.5rem;">';
        html += res.roles.length + ' unique role(s) from ' + Object.keys(perSource).length + ' source(s): ';
        Object.keys(perSource).forEach(function (s) {
            html += '<span class="badge" style="background:' + (SOURCE_COLORS[s] || '#666')
                + '; color:#000; font-weight:700; font-size:0.7rem; padding:2px 6px; margin-left:3px;">'
                + escapeHtml(SOURCE_LABELS[s] || s) + ': ' + perSource[s]
                + '</span>';
        });
        html += '</div>';

        html += '<div style="display:flex; gap:0.4rem; margin-bottom:0.6rem;">';
        html += '<input type="text" id="' + filterId + '" class="form-control form-control-sm" placeholder="Search roles..." '
            + 'style="background:#1e222b; border:1px solid #454d55; color:#fff; flex:1;">';
        if (Object.keys(perSource).length > 1) {
            html += '<select id="' + sourceFilterId + '" class="form-control form-control-sm" '
                + 'style="background:#1e222b; border:1px solid #454d55; color:#fff; max-width:160px;">';
            html += '<option value="">All sources</option>';
            Object.keys(perSource).forEach(function (s) {
                html += '<option value="' + escapeHtml(s) + '">' + escapeHtml(SOURCE_LABELS[s] || s) + '</option>';
            });
            html += '</select>';
        }
        html += '</div>';

        html += '<div id="' + listId + '" style="display:flex; flex-direction:column; gap:4px;">';
        res.roles.forEach(function (r) {
            const hex = r.color && /^#[0-9a-f]{6}$/i.test(r.color) ? r.color : '';
            const dot = hex
                ? '<span style="display:inline-block; width:10px; height:10px; border-radius:50%; background:' + hex + '; margin-right:6px; vertical-align:middle;"></span>'
                : '';
            const primarySrc = r.source;
            const alsoIn = (r.sources || []).filter(function (s) { return s !== primarySrc; });
            const primaryBadge = '<span class="badge" style="background:' + (SOURCE_COLORS[primarySrc] || '#666')
                + '; color:#000; font-weight:700; font-size:0.65rem; padding:2px 6px; margin-left:4px; vertical-align:middle;">'
                + escapeHtml(SOURCE_LABELS[primarySrc] || primarySrc) + '</span>';
            const extraBadge = alsoIn.length > 0
                ? '<span class="badge badge-secondary" style="color:#fff; font-weight:600; font-size:0.65rem; padding:2px 6px; margin-left:2px;" '
                    + 'title="Also in: ' + escapeHtml(alsoIn.map(function (s) { return SOURCE_LABELS[s] || s; }).join(', ')) + '">+' + alsoIn.length + '</span>'
                : '';

            html += '<button type="button" class="btn btn-sm btn-outline-primary js-hr-role-pick-item" '
                + 'data-role-id="' + escapeHtml(r.id) + '" '
                + 'data-role-name="' + escapeHtml(r.name) + '" '
                + 'data-source="' + escapeHtml(primarySrc) + '" '
                + 'style="text-align:left;">'
                + dot + escapeHtml(r.name)
                + '<small style="opacity:0.55; margin-left:4px;">#' + escapeHtml(String(r.id).slice(-6)) + '</small>'
                + primaryBadge + extraBadge
                + '</button>';
        });
        html += '</div>';

        body.innerHTML = html;

        const applyFilter = function () {
            const textV = (document.getElementById(filterId).value || '').toLowerCase();
            const srcEl = document.getElementById(sourceFilterId);
            const srcV  = srcEl ? srcEl.value || '' : '';
            document.querySelectorAll('#' + listId + ' .js-hr-role-pick-item').forEach(function (btn) {
                const n = (btn.getAttribute('data-role-name') + ' ' + btn.getAttribute('data-role-id')).toLowerCase();
                const s = btn.getAttribute('data-source');
                btn.style.display = (n.indexOf(textV) !== -1 && (!srcV || s === srcV)) ? '' : 'none';
            });
        };
        document.getElementById(filterId).addEventListener('input', applyFilter);
        if (document.getElementById(sourceFilterId)) {
            document.getElementById(sourceFilterId).addEventListener('change', applyFilter);
        }
    }
})();
</script>
@endpush
@endonce
