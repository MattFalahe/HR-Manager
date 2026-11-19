{{-- Game and comms time. Expects $comms (ActivityTrackingService::playerSummary):
     EVE figures merged across every alt, so two characters played side by side
     count once; voice is the account's one Discord identity. Self-hides when
     there is no EVE data source and voice tracking is off. --}}
@php
    $ca = $comms ?? null;
    $caShow = is_array($ca) && (!empty($ca['eve_available']) || !empty($ca['voice_enabled']));
@endphp
@if($caShow)
    @php
        $caHours = function ($seconds) {
            $seconds = (int) $seconds;
            if ($seconds <= 0) {
                return '0 h';
            }
            if ($seconds < 3600) {
                return max(1, (int) round($seconds / 60)) . ' min';
            }
            return number_format($seconds / 3600, $seconds < 36000 ? 1 : 0) . ' h';
        };
        $caEve30 = (int) ($ca['eve_seconds_30d'] ?? 0);
        $caVoice30 = (int) (($ca['voice']['seconds_30d'] ?? 0));
        $caTotal = $caEve30 + $caVoice30;
        $caEvePct = $caTotal > 0 ? (int) round($caEve30 * 100 / $caTotal) : null;
        $caSince = null;
        foreach ([$ca['eve_since'] ?? null, $ca['voice_since'] ?? null] as $caStamp) {
            if ($caStamp !== null && $caStamp->gt(now()->subDays(30)) && ($caSince === null || $caStamp->gt($caSince))) {
                $caSince = $caStamp;
            }
        }
        $caTile = 'flex:1 1 130px; min-width:110px; background:rgba(255,255,255,0.03); border:1px solid var(--hr-border, #2c3138); border-radius:6px; padding:10px 12px; text-align:center;';
        $caVal = 'font-size:1.15rem; font-weight:700;';
        $caLbl = 'font-size:0.7rem; color:var(--hr-text-muted); text-transform:uppercase; letter-spacing:0.4px; margin-top:3px;';
    @endphp
    <div class="card card-dark mb-3" style="border-left: 4px solid #5865f2;">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-headset" style="color: #8b93f8;"></i> {{ trans('hr-manager::players.ca_heading') }}</h3>
            <div class="card-tools"><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::players.ca_window') }}</small></div>
        </div>
        <div class="card-body">
            <div style="display:flex; flex-wrap:wrap; gap:10px;">
                @if(!empty($ca['eve_available']))
                    <div style="{{ $caTile }}">
                        @if(!empty($ca['online_now']))
                            <div style="{{ $caVal }} color:#3fb950;"><i class="fas fa-circle" style="font-size:0.6rem; vertical-align:middle;"></i> {{ trans('hr-manager::players.ca_now') }}</div>
                        @elseif($ca['last_in_game'])
                            <div style="{{ $caVal }} color:var(--hr-text-white);" title="{{ $ca['last_in_game']->toDateTimeString() }} EVE">{{ $ca['last_in_game']->diffForHumans() }}</div>
                        @else
                            <div style="{{ $caVal }} color:var(--hr-text-muted);">-</div>
                        @endif
                        <div style="{{ $caLbl }}">{{ trans('hr-manager::players.ca_last_game') }}</div>
                    </div>
                    <div style="{{ $caTile }}">
                        <div style="{{ $caVal }} color:var(--hr-text-white);">{{ $caHours($ca['eve_seconds_7d'] ?? 0) }} <span style="opacity:0.55; font-weight:400;">/ {{ $caHours($caEve30) }}</span></div>
                        <div style="{{ $caLbl }}">{{ trans('hr-manager::players.ca_game_hours') }}</div>
                    </div>
                @endif

                @if(!empty($ca['voice_available']) && !empty($ca['linked']))
                    <div style="{{ $caTile }}">
                        @if(!empty($ca['in_voice_now']))
                            <div style="{{ $caVal }} color:#3fb950;"><i class="fas fa-microphone" style="font-size:0.8rem;"></i> {{ trans('hr-manager::players.ca_now') }}</div>
                            <div style="font-size:0.75rem; color:var(--hr-text-light);">{{ !empty($ca['voice_hidden']) ? trans('hr-manager::players.ca_hidden_channel') : ($ca['voice_channel'] ?: '') }}</div>
                        @elseif($ca['last_in_voice'])
                            <div style="{{ $caVal }} color:var(--hr-text-white);" title="{{ $ca['last_in_voice']->toDateTimeString() }} EVE">{{ $ca['last_in_voice']->diffForHumans() }}</div>
                        @else
                            <div style="{{ $caVal }} color:var(--hr-text-muted);">-</div>
                        @endif
                        <div style="{{ $caLbl }}">{{ trans('hr-manager::players.ca_last_voice') }}</div>
                    </div>
                    <div style="{{ $caTile }}">
                        <div style="{{ $caVal }} color:#8b93f8;">{{ $caHours($ca['voice']['seconds_7d'] ?? 0) }} <span style="opacity:0.55; font-weight:400;">/ {{ $caHours($caVoice30) }}</span></div>
                        <div style="{{ $caLbl }}">{{ trans('hr-manager::players.ca_voice_hours') }}</div>
                    </div>
                @endif
            </div>

            @if(!empty($ca['voice_available']) && !empty($ca['linked']) && !empty($ca['eve_available']) && $caEvePct !== null)
                <div class="mt-3">
                    <div style="display:flex; height:10px; border-radius:5px; overflow:hidden; background:rgba(255,255,255,0.06);">
                        <div style="width: {{ $caEvePct }}%; background:#667eea;" title="{{ trans('hr-manager::players.ca_split_game') }}"></div>
                        <div style="width: {{ 100 - $caEvePct }}%; background:#5865f2; opacity:0.7;" title="{{ trans('hr-manager::players.ca_split_voice') }}"></div>
                    </div>
                    <small style="color: var(--hr-text-muted); font-size: 0.75rem;">{{ trans('hr-manager::players.ca_split', ['game' => $caEvePct, 'voice' => 100 - $caEvePct]) }}</small>
                </div>
            @endif

            @if(!empty($ca['voice_available']) && !empty($ca['linked']) && !empty($ca['voice']['top_channels']))
                <small class="d-block mt-2" style="color: var(--hr-text-muted); font-size: 0.78rem;">
                    {{ trans('hr-manager::players.ca_top_channels') }}:
                    @foreach($ca['voice']['top_channels'] as $caCh)
                        <span class="badge" style="background: rgba(88,101,242,0.18); color: #c3c8fb; font-weight: normal;">{{ $caCh['name'] }} {{ $caHours($caCh['seconds']) }}</span>
                    @endforeach
                </small>
            @endif

            @if(empty($ca['voice_enabled']))
                <small class="d-block mt-2" style="color: var(--hr-text-muted); font-size: 0.75rem;"><i class="fas fa-info-circle"></i> {{ trans('hr-manager::players.ca_voice_off') }}</small>
            @elseif(empty($ca['voice_available']))
                <small class="d-block mt-2" style="color: var(--hr-text-muted); font-size: 0.75rem;"><i class="fas fa-info-circle"></i> {{ trans('hr-manager::players.ca_voice_unavailable') }}</small>
            @elseif(empty($ca['linked']))
                <small class="d-block mt-2" style="color: var(--hr-text-muted); font-size: 0.75rem;"><i class="fas fa-info-circle"></i> {{ trans('hr-manager::players.ca_not_linked') }}</small>
            @else
                <small class="d-block mt-2" style="color: var(--hr-text-muted); font-size: 0.75rem;"><i class="fas fa-info-circle"></i> {{ trans('hr-manager::players.ca_voice_accuracy', ['n' => (int) ($ca['interval'] ?? 5)]) }}</small>
            @endif

            @if($caSince)
                <small class="d-block mt-1" style="color: var(--hr-text-muted); font-size: 0.75rem;"><i class="fas fa-hourglass-start"></i> {{ trans('hr-manager::players.ca_since', ['when' => $caSince->diffForHumans()]) }}</small>
            @endif
        </div>
    </div>
@endif
