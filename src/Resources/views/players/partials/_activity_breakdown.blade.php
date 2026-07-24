{{-- Activity breakdown — the raw numbers behind the role badges.
     Expects $activity (from PlayerActivityService::forCharacters): summed across
     alts on the player view, single-character on the member view. Renders only
     the non-zero activities; self-contained styling so it works on either view. --}}
@php
    $act = $activity ?? ['has_data' => false, 'months' => 6];
    $isk = function ($v) {
        $a = abs((float) $v);
        if ($a >= 1e12) return number_format($v / 1e12, 2) . ' T';
        if ($a >= 1e9)  return number_format($v / 1e9, 2)  . ' B';
        if ($a >= 1e6)  return number_format($v / 1e6, 2)  . ' M';
        if ($a >= 1e3)  return number_format($v / 1e3, 1)  . ' k';
        return number_format((float) $v, 0);
    };
    // [icon, colour, value, label] — matching the role-badge iconography.
    $tiles = [];
    if (($act['bounty_isk'] ?? 0) > 0)       $tiles[] = ['fa-crosshairs',       '#f87171', $isk($act['bounty_isk']),       trans('hr-manager::players.act_bounties')];
    if (($act['mission_isk'] ?? 0) > 0)      $tiles[] = ['fa-scroll',           '#fbbf24', $isk($act['mission_isk']),      trans('hr-manager::players.act_missions')];
    if (($act['ore_value_isk'] ?? 0) > 0)    $tiles[] = ['fa-gem',              '#5eead4', $isk($act['ore_value_isk']),    trans('hr-manager::players.act_ore')];
    if (($act['trade_volume_isk'] ?? 0) > 0) $tiles[] = ['fa-balance-scale',    '#a5b4fc', $isk($act['trade_volume_isk']), trans('hr-manager::players.act_trade') . ' · ' . number_format((int) ($act['trade_txns'] ?? 0)) . ' ' . trans('hr-manager::players.act_txns')];
    if (($act['industry_jobs'] ?? 0) > 0)    $tiles[] = ['fa-industry',         '#c4b5fd', number_format((int) $act['industry_jobs']), trans('hr-manager::players.act_industry')];
    if (($act['pi_colonies'] ?? 0) > 0)      $tiles[] = ['fa-globe',            '#6ee7b7', number_format((int) $act['pi_colonies']),   trans('hr-manager::players.act_pi')];
    if (($act['pvp_kills'] ?? 0) > 0)        $tiles[] = ['fa-skull-crossbones', '#fca5a5', number_format((int) $act['pvp_kills']),     trans('hr-manager::players.act_pvp')];
    if (($act['fc_broadcasts'] ?? 0) > 0)    $tiles[] = ['fa-broadcast-tower',  '#fcd34d', number_format((int) $act['fc_broadcasts']), trans('hr-manager::players.act_fc')];
@endphp
<div class="card card-dark mb-3">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-chart-bar"></i> {{ trans('hr-manager::players.act_heading') }}</h3>
        <div class="card-tools"><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::players.act_window', ['n' => (int) ($act['months'] ?? 6)]) }}</small></div>
    </div>
    <div class="card-body">
        @if(empty($tiles))
            <p class="text-muted mb-0"><i class="fas fa-info-circle"></i> {{ trans('hr-manager::players.act_none') }}</p>
        @else
            <div style="display:flex; flex-wrap:wrap; gap:10px;">
                @foreach($tiles as $t)
                    <div style="flex:1 1 130px; min-width:110px; background:rgba(255,255,255,0.03); border:1px solid var(--hr-border, #2c3138); border-radius:6px; padding:10px 12px; text-align:center;">
                        <div style="font-size:1.25rem; font-weight:700; line-height:1.2; color:{{ $t[1] }};"><i class="fas {{ $t[0] }}" style="font-size:0.9rem; opacity:0.85;"></i> {{ $t[2] }}</div>
                        <div style="font-size:0.7rem; color:var(--hr-text-muted, #9ca3af); text-transform:uppercase; letter-spacing:0.4px; margin-top:3px;">{{ $t[3] }}</div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
