{{--
    zKillboard PvP breakdown for the applying account — rolling 1/3/6/12-month
    kill windows per character, most-active character flagged. Rendered by
    ApplicationController@pvpBreakdown and injected via AJAX (lazy-loaded), so it
    only fetches zKill when a recruiter asks.

    Expects: $breakdown (['characters', 'most_active_id', 'any_data']),
             $applyingCharacterId (int)
--}}
@php
    $iskFmt = function ($v) {
        $v = (float) $v;
        if ($v >= 1.0e12) return number_format($v / 1.0e12, 1) . 'T';
        if ($v >= 1.0e9)  return number_format($v / 1.0e9, 1)  . 'B';
        if ($v >= 1.0e6)  return number_format($v / 1.0e6, 1)  . 'M';
        if ($v >= 1.0e3)  return number_format($v / 1.0e3, 1)  . 'k';
        return number_format($v, 0);
    };
@endphp

@if(empty($breakdown['any_data']))
    <p class="text-muted mb-0"><i class="fas fa-info-circle"></i> {{ trans('hr-manager::applications.pvp_none') }}</p>
@else
    <div class="table-responsive">
        <table class="table table-sm mb-0" style="color: var(--hr-text-light);">
            <thead>
                <tr>
                    <th>{{ trans('hr-manager::applications.pvp_col_character') }}</th>
                    <th class="text-center" title="{{ trans('hr-manager::applications.pvp_win_hint', ['n' => '1']) }}">1M</th>
                    <th class="text-center" title="{{ trans('hr-manager::applications.pvp_win_hint', ['n' => '3']) }}">3M</th>
                    <th class="text-center" title="{{ trans('hr-manager::applications.pvp_win_hint', ['n' => '6']) }}">6M</th>
                    <th class="text-center" title="{{ trans('hr-manager::applications.pvp_win_hint', ['n' => '12']) }}">1Y</th>
                    <th class="text-center">{{ trans('hr-manager::applications.pvp_col_alltime') }}</th>
                    <th class="text-center">{{ trans('hr-manager::applications.pvp_col_danger') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($breakdown['characters'] as $c)
                    @php $isMost = ($breakdown['most_active_id'] ?? null) === $c['character_id']; @endphp
                    <tr @if($isMost) style="background: rgba(102,126,234,0.10);" @endif>
                        <td style="white-space: nowrap;">
                            <img src="https://images.evetech.net/characters/{{ $c['character_id'] }}/portrait?size=32" style="width:22px;height:22px;border-radius:50%;vertical-align:middle;margin-right:6px;" alt="">
                            <a href="https://zkillboard.com/character/{{ $c['character_id'] }}/" target="_blank" rel="noopener" style="color: var(--hr-text-white, #fff);">{{ $c['name'] }}</a>
                            @if($c['character_id'] === (int) $applyingCharacterId)
                                <span class="badge badge-primary ml-1" style="font-size:0.6rem;">{{ trans('hr-manager::applications.pvp_applying') }}</span>
                            @endif
                            @if($isMost)
                                <span class="badge ml-1" style="background: rgba(102,126,234,0.28); color:#c3ccf8; font-size:0.6rem;"><i class="fas fa-crosshairs"></i> {{ trans('hr-manager::applications.pvp_most_active') }}</span>
                            @endif
                        </td>
                        @foreach(['1m', '3m', '6m', '12m'] as $wk)
                            @php $w = $c['windows'][$wk] ?? ['kills' => 0, 'isk_destroyed' => 0]; @endphp
                            <td class="text-center" title="{{ trans('hr-manager::applications.pvp_isk_destroyed', ['isk' => $iskFmt($w['isk_destroyed'] ?? 0)]) }}">
                                <strong style="{{ ($w['kills'] ?? 0) > 0 ? 'color: var(--hr-text-white, #fff);' : 'color: var(--hr-text-muted);' }}">{{ number_format($w['kills'] ?? 0) }}</strong>
                            </td>
                        @endforeach
                        <td class="text-center">
                            <span style="color: var(--hr-success, #28a745);">{{ number_format($c['all_time_kills']) }}</span>
                            <small class="text-muted"> / {{ number_format($c['all_time_losses']) }}</small>
                        </td>
                        <td class="text-center">{{ $c['danger_ratio'] !== null ? $c['danger_ratio'] . '%' : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <small class="d-block mt-2" style="color: var(--hr-text-muted);">
        <i class="fas fa-info-circle"></i> {{ trans('hr-manager::applications.pvp_footnote') }}
    </small>
@endif
