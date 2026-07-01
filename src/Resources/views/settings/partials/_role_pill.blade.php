{{--
    Display-only role pill — renders a stored role mention (snowflake / <@&ID>)
    as a colored badge with the role name when DiscordRoleResolver can resolve
    it, or a raw-ID warning when the value is set but doesn't map to a known
    role, or a muted "no role" placeholder when empty.

    Mirrors Structure Manager / Mining Manager's _role_pill convention so the
    future Routing Map page and any per-binding summary can render stored
    role values consistently.

    REQUIRED params:
      $roleId   - the stored value (raw snowflake, '<@&ID>', or null/empty)

    OPTIONAL params:
      $roleMap  - prebuilt map from DiscordRoleResolver::roleLookupMap()
                  Pass it from the parent view so the lookup happens once
                  per page rather than per-pill. Defaults to building on
                  demand if omitted.
--}}
@php
    $rawValue   = $roleId ?? '';
    $resolverFq = \HrManager\Services\DiscordRoleResolver::class;
    $map        = $roleMap ?? null;
    $description = $rawValue === '' ? null : $resolverFq::describeRoleMention($rawValue, $map);
@endphp

@if(empty($rawValue))
    <span class="badge"
          title="{{ trans('hr-manager::settings.webhook_discord_role') }}: none"
          style="background: rgba(255,255,255,0.05); color: var(--hr-text-muted, #9ca3af);">
        <i class="fas fa-minus"></i>
    </span>
@elseif($description && $description['known'])
    <span class="badge"
          style="background-color: {{ $description['color'] ?: '#444' }}; color: #000;"
          title="{{ $resolverFq::providerShortLabel($description['source']) }}">
        <i class="fab fa-discord"></i> {{ $description['name'] }}
    </span>
@else
    {{-- Has an ID but it doesn't resolve in any installed provider. Could be
         a stale role, a provider that's been uninstalled, or a typo. Render
         the raw ID so the operator can fix it. --}}
    <span class="badge badge-warning" title="Role not resolved by any installed Discord provider">
        <i class="fas fa-exclamation-triangle"></i> {{ $rawValue }}
    </span>
@endif
