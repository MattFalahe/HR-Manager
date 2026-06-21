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
        'notify_inactive_director'          => true,
        'notify_dead_weight'                => false,
        'notify_purge_reminder'             => true,
        'notify_player_status'              => true,
        'notify_wallet_stalled'             => false,
        'notify_wallet_compliance_dropped'  => false,
        'notify_wallet_milestone'           => false,
    ];
    $whChecked = function ($key) use ($webhook, $whDefaults) {
        return $webhook ? (bool) $webhook->{$key} : ($whDefaults[$key] ?? false);
    };

    // Grouped exactly as the original form: application / director / wallet.
    $whGroups = [
        ['notify_application_submitted', 'notify_application_accepted', 'notify_application_rejected', 'notify_status_change'],
        ['notify_inactive_director', 'notify_dead_weight', 'notify_purge_reminder', 'notify_player_status'],
        ['notify_wallet_stalled', 'notify_wallet_compliance_dropped', 'notify_wallet_milestone'],
    ];
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

@foreach($whGroups as $group)
    <div class="row mb-2">
        @foreach($group as $key)
            <div class="col-md-3">
                <div class="form-check">
                    <input type="checkbox" name="{{ $key }}" value="1" class="form-check-input" id="{{ $key }}_{{ $fid }}" {{ $whChecked($key) ? 'checked' : '' }}>
                    <label class="form-check-label" for="{{ $key }}_{{ $fid }}">{{ trans('hr-manager::settings.' . $key) }}</label>
                </div>
            </div>
        @endforeach
    </div>
@endforeach

@if($webhook)
    {{-- is_enabled is only meaningful on edit; new webhooks are created enabled. --}}
    <div class="form-check mb-2 mt-1">
        <input type="checkbox" name="is_enabled" value="1" class="form-check-input" id="webhookEnabled_{{ $fid }}" {{ $webhook->is_enabled ? 'checked' : '' }}>
        <label class="form-check-label" for="webhookEnabled_{{ $fid }}"><strong>{{ trans('hr-manager::settings.webhook_enabled_toggle') }}</strong></label>
    </div>
@endif
