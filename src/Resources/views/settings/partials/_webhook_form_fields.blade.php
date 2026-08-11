{{--
    Shared webhook form fields, used by both the "Add webhook" form and the
    per-webhook inline edit forms. Pass $webhook (a WebhookConfiguration) to
    pre-fill for edit, or null/omit for the add form's defaults.

    The caller owns the <form> wrapper, @csrf (+ @method('PUT') for edit) and
    the submit button. $fid keeps every input id unique across the add form
    and every edit form on the same page.
--}}
@php
    $webhook = $webhook ?? null;
    $fid = $webhook ? ('wh' . $webhook->id) : 'add';

    // Default toggle states for a NEW webhook (mirrors the original add form).
    $whDefaults = [
        'notify_application_submitted'      => true,
        'notify_application_accepted'       => true,
        'notify_application_rejected'       => false,
        'notify_status_change'              => true,
        'notify_handler_note'               => false,
        'notify_inactive_director'          => true,
        'notify_silent_wallet_director'     => false,
        'notify_dead_weight'                => false,
        'notify_purge_reminder'             => true,
        'notify_loa_marked'                 => true,
        'notify_marked_for_purge'           => true,
        'notify_purge_personal'             => false,
        'notify_status_cleared'             => true,
        'notify_token_revoked'              => true,
        'notify_token_coverage'             => false,
        'notify_wallet_stalled'             => false,
        'notify_wallet_compliance_dropped'  => false,
        'notify_wallet_milestone'           => false,
        'notify_member_joined'              => false,
        'notify_member_left'                => false,
        'notify_join_no_application'        => true,
        'notify_member_unregistered'        => true,
        'notify_onboarding_welcome'         => false,
        'notify_flagged_applicant'          => true,
    ];
    $whChecked = function ($key) use ($webhook, $whDefaults) {
        return $webhook ? (bool) $webhook->{$key} : ($whDefaults[$key] ?? false);
    };

    // Toggles grouped into labelled boxes by category, so the (long) list
    // reads by concern instead of one dense wall of checkboxes.
    $whBoxes = [
        [
            'title' => trans('hr-manager::settings.webhook_box_applications'),
            'icon'  => 'fa-file-signature',
            'keys'  => ['notify_application_submitted', 'notify_application_accepted', 'notify_application_rejected', 'notify_status_change', 'notify_handler_note'],
        ],
        [
            'title' => trans('hr-manager::settings.webhook_box_security'),
            'icon'  => 'fa-shield-alt',
            'keys'  => ['notify_flagged_applicant', 'notify_token_revoked', 'notify_join_no_application', 'notify_member_unregistered'],
        ],
        [
            'title' => trans('hr-manager::settings.webhook_box_retention'),
            'icon'  => 'fa-user-clock',
            'keys'  => ['notify_inactive_director', 'notify_silent_wallet_director', 'notify_dead_weight', 'notify_loa_marked', 'notify_marked_for_purge', 'notify_purge_personal', 'notify_status_cleared', 'notify_purge_reminder', 'notify_token_coverage'],
        ],
        [
            'title' => trans('hr-manager::settings.webhook_box_membership'),
            'icon'  => 'fa-users',
            'keys'  => ['notify_member_joined', 'notify_member_left', 'notify_onboarding_welcome'],
        ],
        [
            'title' => trans('hr-manager::settings.webhook_box_wallet'),
            'icon'  => 'fa-coins',
            'keys'  => ['notify_wallet_stalled', 'notify_wallet_compliance_dropped', 'notify_wallet_milestone'],
        ],
    ];

    // Keys that render an extra help line under the checkbox.
    $whHints = [
        'notify_purge_personal'         => trans('hr-manager::settings.notify_purge_personal_help'),
        'notify_handler_note'           => trans('hr-manager::settings.notify_handler_note_help'),
        'notify_silent_wallet_director' => trans('hr-manager::settings.notify_silent_wallet_director_help'),
    ];

    // Categories the MC fast-poll accelerates, and whether it's live. Marked with
    // a small bolt so operators see which alerts arrive in ~2 min (fast-poll) vs
    // the 30-min roster scan. Both paths always fire — the badge is informational.
    $fastPollKeys   = \HrManager\Services\MembershipNotificationHandler::fastPollCategories();
    $fastPollActive = \HrManager\Services\MembershipNotificationHandler::fastPollAvailable();
@endphp

<div class="row">
    <div class="col-md-3">
        <div class="form-group">
            <label>{{ trans('hr-manager::settings.webhook_name') }}</label>
            <input type="text" name="name" class="form-control" required maxlength="255" value="{{ old('name', $webhook->name ?? '') }}">
        </div>
    </div>
    <div class="col-md-2">
        <div class="form-group">
            <label>{{ trans('hr-manager::settings.webhook_type') }}</label>
            <select name="type" class="form-control">
                <option value="discord" {{ ($webhook->type ?? 'discord') === 'discord' ? 'selected' : '' }}>Discord</option>
                <option value="slack" {{ ($webhook->type ?? '') === 'slack' ? 'selected' : '' }}>Slack</option>
            </select>
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-group">
            <label>{{ trans('hr-manager::settings.webhook_corporation') }}</label>
            <select name="corporation_id" class="form-control">
                <option value="">{{ trans('hr-manager::settings.webhook_corporation_global') }}</option>
                @foreach(($corporations ?? []) as $corp)
                    <option value="{{ $corp->corporation_id }}" {{ (int) ($webhook->corporation_id ?? 0) === (int) $corp->corporation_id ? 'selected' : '' }}>{{ $corp->name }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group">
            <label>{{ trans('hr-manager::settings.webhook_url') }}</label>
            <input type="url" name="webhook_url" class="form-control" required maxlength="2048" placeholder="https://discord.com/api/webhooks/..." value="{{ old('webhook_url', $webhook->webhook_url ?? '') }}">
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="form-group">
            <label>{{ trans('hr-manager::settings.webhook_discord_role') }}</label>
            @include('hr-manager::settings.partials._role_picker_field', [
                'name'       => 'discord_role_id',
                'id'         => 'webhookRoleIdInput_' . $fid,
                'pickerId'   => 'webhookRolePicker_' . $fid,
                'value'      => $webhook->discord_role_id ?? '',
                'roles'      => $discordRoles,
                'provider'   => $discordRolesProvider,
                'helpText'   => trans('hr-manager::settings.webhook_discord_role_help'),
            ])
        </div>
    </div>
</div>

{{-- Notification categories, grouped into labelled boxes. Each box is a
     bordered panel (styles inlined so this partial never depends on a CSS
     republish). The purge "notify the player" toggle lives in the Retention
     box and carries an extra hint + connector-availability note. --}}
@foreach($whBoxes as $box)
    <div style="border: 1px solid var(--hr-border, #2a3f5f); border-radius: 8px; padding: 12px 14px; margin-bottom: 14px; background: var(--hr-dark-card, rgba(255,255,255,0.02));">
        <div style="font-weight: 600; color: var(--hr-text-light, #c5cdd8); margin-bottom: 10px; padding-bottom: 6px; border-bottom: 1px solid var(--hr-border, #2a3f5f);">
            <i class="fas {{ $box['icon'] }}" style="margin-right: 6px; opacity: 0.8;"></i> {{ $box['title'] }}
        </div>
        <div class="row">
            @foreach($box['keys'] as $key)
                <div class="col-lg-4 col-md-6">
                    <div class="form-check mb-2">
                        <input type="checkbox" name="{{ $key }}" value="1" class="form-check-input" id="{{ $key }}_{{ $fid }}" {{ $whChecked($key) ? 'checked' : '' }}>
                        <label class="form-check-label" for="{{ $key }}_{{ $fid }}">
                            {{ trans('hr-manager::settings.' . $key) }}
                            @if(in_array($key, $fastPollKeys, true))
                                <span class="badge" style="background: {{ $fastPollActive ? 'rgba(40,167,69,0.18)' : 'rgba(108,117,125,0.18)' }}; color: {{ $fastPollActive ? '#3fb950' : '#adb5bd' }}; font-size: 0.6rem; padding: 2px 5px; vertical-align: middle;"
                                      title="{{ $fastPollActive ? trans('hr-manager::settings.fast_poll_badge_active') : trans('hr-manager::settings.fast_poll_badge_inactive') }}">
                                    <i class="fas fa-bolt"></i> {{ trans('hr-manager::settings.fast_poll_badge') }}
                                </span>
                            @endif
                        </label>
                        @isset($whHints[$key])
                            <small class="d-block" style="color: var(--hr-text-muted, #8b95a5); margin-left: 1.25rem;">{{ $whHints[$key] }}</small>
                        @endisset
                        @if($key === 'notify_purge_personal')
                            @unless($connectorAvailable ?? true)
                                <small class="d-block" style="color: var(--hr-text-muted, #8b95a5); margin-left: 1.25rem;">
                                    <i class="fas fa-exclamation-triangle"></i> {{ trans('hr-manager::settings.notify_purge_personal_unavailable') }}
                                </small>
                            @endunless
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endforeach

@if($webhook)
    {{-- is_enabled is only meaningful on edit; new webhooks are created enabled. --}}
    <div class="form-check mb-2 mt-1">
        <input type="checkbox" name="is_enabled" value="1" class="form-check-input" id="webhookEnabled_{{ $fid }}" {{ $webhook->is_enabled ? 'checked' : '' }}>
        <label class="form-check-label" for="webhookEnabled_{{ $fid }}"><strong>{{ trans('hr-manager::settings.webhook_enabled_toggle') }}</strong></label>
    </div>
@endif
