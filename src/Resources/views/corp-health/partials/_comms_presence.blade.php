{{-- Game and comms presence over the last 30 days, registered members only.
     Expects $presence (ActivityTrackingService::corpPresenceForCorporation) and
     $corporationId. The voice buckets only mean something once voice tracking
     is on, so without it the card shows the EVE half alone. --}}
@php
    $cp = $presence ?? ['available' => false];
@endphp
@if(!empty($cp['available']))
    @php
        $cpVoice = !empty($cp['voice_available']);
        $cpTotal = max(1, (int) ($cp['players'] ?? 0));
        $cpCount = fn ($key) => count($cp['buckets'][$key] ?? []);
        $cpPct = fn ($n) => (int) round($n * 100 / $cpTotal);
        $cpSegments = $cpVoice
            ? [
                ['key' => 'both',       'color' => '#3fb950'],
                ['key' => 'game_only',  'color' => '#667eea'],
                ['key' => 'voice_only', 'color' => '#5865f2'],
                ['key' => 'neither',    'color' => '#495057'],
            ]
            : [
                ['key' => 'game_any',   'color' => '#667eea'],
                ['key' => 'neither',    'color' => '#495057'],
            ];
        $cpCounts = [
            'both'       => $cpCount('both'),
            'game_only'  => $cpCount('game_only'),
            'voice_only' => $cpCount('voice_only'),
            'neither'    => $cpCount('neither'),
        ];
        $cpCounts['game_any'] = $cpCounts['both'] + $cpCounts['game_only'];
        if (!$cpVoice) {
            $cpCounts['neither'] += $cpCounts['voice_only'];
        }
        $cpListCap = 50;
    @endphp
    <div class="card card-dark mb-3" style="border-left: 4px solid #5865f2;">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-headset" style="color: #8b93f8;"></i> {{ trans('hr-manager::corp-health.cp_heading') }}</h3>
            <div class="card-tools">
                <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.cp_window', ['n' => (int) ($cp['days'] ?? 30), 'players' => (int) ($cp['players'] ?? 0)]) }}</small>
            </div>
        </div>
        <div class="card-body">
            <div style="display: flex; width: 100%; height: 22px; border-radius: 4px; overflow: hidden; background: rgba(255,255,255,0.05);">
                @foreach($cpSegments as $seg)
                    @continue($cpCounts[$seg['key']] <= 0)
                    <div style="width: {{ $cpPct($cpCounts[$seg['key']]) }}%; background: {{ $seg['color'] }}; height: 100%;"
                         title="{{ trans('hr-manager::corp-health.cp_seg_' . $seg['key']) }}: {{ $cpCounts[$seg['key']] }}"></div>
                @endforeach
            </div>
            <div class="d-flex flex-wrap mt-2" style="gap: 14px;">
                @foreach($cpSegments as $seg)
                    <span style="color: var(--hr-text-muted); font-size: 0.82rem;">
                        <span style="display:inline-block;width:9px;height:9px;border-radius:2px;background:{{ $seg['color'] }};margin-right:5px;"></span>
                        {{ trans('hr-manager::corp-health.cp_seg_' . $seg['key']) }}
                        <strong style="color: var(--hr-text-white);">{{ $cpCounts[$seg['key']] }}</strong>
                        ({{ $cpPct($cpCounts[$seg['key']]) }}%)
                    </span>
                @endforeach
            </div>

            @if($cpVoice)
                @foreach(['voice_only', 'game_only'] as $cpList)
                    @if(!empty($cp['buckets'][$cpList]))
                        <details class="mt-3">
                            <summary style="color: var(--hr-text-light); cursor: pointer;">
                                {{ trans('hr-manager::corp-health.cp_list_' . $cpList, ['n' => count($cp['buckets'][$cpList])]) }}
                            </summary>
                            <div class="table-responsive mt-2">
                                <table class="table table-sm mb-0" style="color: var(--hr-text-light);">
                                    <thead>
                                        <tr>
                                            <th>{{ trans('hr-manager::corp-health.col_player') }}</th>
                                            <th>{{ trans('hr-manager::corp-health.cp_col_last_game') }}</th>
                                            <th>{{ trans('hr-manager::corp-health.cp_col_last_voice') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach(array_slice($cp['buckets'][$cpList], 0, $cpListCap) as $row)
                                            <tr>
                                                <td><a href="{{ route('hr-manager.players.show', ['id' => $row['user_id'], 'corporation_id' => $corporationId]) }}" style="color: var(--hr-text-white, #fff); text-decoration: none;">{{ $row['name'] }}</a></td>
                                                <td>{{ $row['last_in_game'] ? $row['last_in_game']->diffForHumans() : '-' }}</td>
                                                <td>
                                                    @if($row['last_voice'])
                                                        {{ $row['last_voice']->diffForHumans() }}
                                                    @elseif(empty($row['linked']))
                                                        <span style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.cp_not_linked') }}</span>
                                                    @else
                                                        -
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            @if(count($cp['buckets'][$cpList]) > $cpListCap)
                                <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.cp_list_capped', ['n' => $cpListCap]) }}</small>
                            @endif
                        </details>
                    @endif
                @endforeach
                <small class="d-block mt-2" style="color: var(--hr-text-muted); font-size: 0.78rem;">
                    <i class="fas fa-info-circle"></i> {{ trans('hr-manager::corp-health.cp_footnote_voice', ['linked' => (int) ($cp['linked'] ?? 0), 'players' => (int) ($cp['players'] ?? 0)]) }}
                </small>
            @else
                <small class="d-block mt-2" style="color: var(--hr-text-muted); font-size: 0.78rem;">
                    <i class="fas fa-info-circle"></i> {{ trans('hr-manager::corp-health.cp_footnote_no_voice') }}
                </small>
            @endif
        </div>
    </div>
@endif
