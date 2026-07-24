{{-- Mining engagement — a fuller mining picture than event-attendance alone.
     Expects $engagement (CrossPluginDataService::getMiningEngagement): summed
     across alts on the player view, single character on the member view. Shows
     op attendance ONLY when the corp runs ops and moon stats ONLY when it runs
     extractions, so a corp that mines without scheduled ops never reads as a
     red 0%. Self-hides when Mining Manager is absent / there's no mining. --}}
@php
    $me = $engagement ?? ['available' => false, 'has_data' => false];
@endphp
@if(!empty($me['available']) && !empty($me['has_data']))
    @php
        $isk = function ($v) {
            $a = abs((float) $v);
            if ($a >= 1e12) return number_format($v / 1e12, 2) . ' T';
            if ($a >= 1e9)  return number_format($v / 1e9, 2)  . ' B';
            if ($a >= 1e6)  return number_format($v / 1e6, 2)  . ' M';
            if ($a >= 1e3)  return number_format($v / 1e3, 1)  . ' k';
            return number_format((float) $v, 0);
        };
        $opsTotal = (int) ($me['ops_total'] ?? 0);
        $opsRate  = $me['ops_rate'];
        // Only colour the attendance by rate when the corp runs a MEANINGFUL
        // number of ops — a corp with 1-3 tracked ops shouldn't red-flag a miner.
        $opsColor = ($opsTotal < 4 || $opsRate === null) ? 'var(--hr-text-light)'
            : ($opsRate >= 60 ? '#3fb950' : ($opsRate >= 25 ? '#e0a800' : '#f87171'));
    @endphp
    <div class="card card-dark mb-3" style="border-left: 4px solid #14b8a6;">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-gem" style="color: #5eead4;"></i> {{ trans('hr-manager::players.mine_heading') }}</h3>
            <div class="card-tools"><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::players.act_window', ['n' => (int) ($me['months'] ?? 6)]) }}</small></div>
        </div>
        <div class="card-body">
            <div style="display:flex; flex-wrap:wrap; gap:10px;">
                @if(($me['ore_value'] ?? 0) > 0)
                    <div style="flex:1 1 130px; min-width:110px; background:rgba(255,255,255,0.03); border:1px solid var(--hr-border, #2c3138); border-radius:6px; padding:10px 12px; text-align:center;">
                        <div style="font-size:1.25rem; font-weight:700; color:#5eead4;">{{ $isk($me['ore_value']) }}</div>
                        <div style="font-size:0.7rem; color:var(--hr-text-muted); text-transform:uppercase; letter-spacing:0.4px; margin-top:3px;">{{ trans('hr-manager::players.mine_ore_value') }}</div>
                    </div>
                @endif
                @if(($me['active_months'] ?? 0) > 0)
                    <div style="flex:1 1 130px; min-width:110px; background:rgba(255,255,255,0.03); border:1px solid var(--hr-border, #2c3138); border-radius:6px; padding:10px 12px; text-align:center;">
                        <div style="font-size:1.25rem; font-weight:700; color:var(--hr-text-white);">{{ (int) $me['active_months'] }}</div>
                        <div style="font-size:0.7rem; color:var(--hr-text-muted); text-transform:uppercase; letter-spacing:0.4px; margin-top:3px;">{{ trans('hr-manager::players.mine_active_months') }}</div>
                    </div>
                @endif
                @if(!empty($me['has_moons']) && ($me['moon_ore_value'] ?? 0) > 0)
                    <div style="flex:1 1 130px; min-width:110px; background:rgba(255,255,255,0.03); border:1px solid var(--hr-border, #2c3138); border-radius:6px; padding:10px 12px; text-align:center;">
                        <div style="font-size:1.25rem; font-weight:700; color:#c4b5fd;"><i class="fas fa-moon" style="font-size:0.85rem; opacity:0.85;"></i> {{ $isk($me['moon_ore_value']) }}</div>
                        <div style="font-size:0.7rem; color:var(--hr-text-muted); text-transform:uppercase; letter-spacing:0.4px; margin-top:3px;">{{ trans('hr-manager::players.mine_moon_ore') }}</div>
                    </div>
                @endif
                @if(!empty($me['has_ops']))
                    <div style="flex:1 1 130px; min-width:110px; background:rgba(255,255,255,0.03); border:1px solid var(--hr-border, #2c3138); border-radius:6px; padding:10px 12px; text-align:center;">
                        <div style="font-size:1.25rem; font-weight:700; color:{{ $opsColor }};">{{ (int) $me['ops_attended'] }}<span style="opacity:0.6;">/{{ $opsTotal }}</span></div>
                        <div style="font-size:0.7rem; color:var(--hr-text-muted); text-transform:uppercase; letter-spacing:0.4px; margin-top:3px;">{{ trans('hr-manager::players.mine_ops') }}</div>
                    </div>
                @endif
            </div>

            {{-- Context lines --}}
            @if(!empty($me['has_moons']))
                <small class="d-block mt-2" style="color: var(--hr-text-muted);"><i class="fas fa-moon"></i> {{ trans('hr-manager::players.mine_moon_note', ['pulls' => (int) $me['moon_pulls_total']]) }}</small>
            @endif
            @if(!empty($me['has_ops']))
                <small class="d-block mt-1" style="color: var(--hr-text-muted);"><i class="fas fa-users"></i> {{ trans('hr-manager::players.mine_ops_note', ['attended' => (int) $me['ops_attended'], 'total' => $opsTotal]) }}</small>
            @endif
            @if(empty($me['has_ops']) && empty($me['has_moons']))
                <small class="d-block mt-2" style="color: var(--hr-text-muted);"><i class="fas fa-info-circle"></i> {{ trans('hr-manager::players.mine_no_ops_note') }}</small>
            @endif
        </div>
    </div>
@endif
