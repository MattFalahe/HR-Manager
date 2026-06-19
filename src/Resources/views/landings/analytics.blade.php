@extends('web::layouts.grids.12')

@section('title', trans('hr-manager::landings.analytics_heading') . ': ' . $landing->title)
@section('page_header', trans('hr-manager::landings.analytics_heading') . ': ' . $landing->title)

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/hr-manager/css/hr-manager.css') }}?v=1.0.0">
@endpush

@section('full')
<div class="hr-manager-wrapper">

    <div class="card card-dark mb-3">
        <div class="card-body py-2 d-flex flex-wrap align-items-center" style="gap: 8px;">
            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::landings.analytics_window', ['days' => $days]) }}</small>
            <span class="ml-2">
                @foreach([7, 30, 90, 180] as $opt)
                    <a href="{{ route('hr-manager.landings.analytics', ['id' => $landing->id, 'days' => $opt]) }}"
                       class="btn btn-sm {{ $opt === $days ? 'btn-hr-primary' : 'btn-hr-secondary' }}">{{ $opt }}d</a>
                @endforeach
            </span>
        </div>
    </div>

    {{-- Top-level metrics --}}
    <div class="card card-dark mb-3">
        <div class="card-body">
            <div class="row text-center">
                <div class="col"><div style="font-size: 2.2rem; color: var(--hr-text-white);"><strong>{{ number_format($stats['total_views']) }}</strong></div><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::landings.metric_total_views') }}</small></div>
                <div class="col"><div style="font-size: 2.2rem; color: var(--hr-text-white);"><strong>{{ number_format($stats['unique_viewers']) }}</strong></div><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::landings.metric_unique_viewers') }}</small></div>
                <div class="col"><div style="font-size: 2.2rem; color: var(--hr-text-white);"><strong>{{ number_format($stats['apply_clicks']) }}</strong></div><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::landings.metric_apply_clicks') }}</small></div>
                <div class="col"><div style="font-size: 2.2rem; color: var(--hr-success);"><strong>{{ number_format($stats['applications']) }}</strong></div><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::landings.metric_applications') }}</small></div>
                <div class="col"><div style="font-size: 2.2rem; color: var(--hr-text-white);"><strong>{{ $stats['conversion_clicks'] }}%</strong></div><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::landings.metric_conversion_clicks') }}</small></div>
                <div class="col"><div style="font-size: 2.2rem; color: var(--hr-success);"><strong>{{ $stats['conversion_apply'] }}%</strong></div><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::landings.metric_conversion_apply') }}</small></div>
            </div>
        </div>
    </div>

    {{-- Views by day (simple sparkline-style bar list) --}}
    <div class="card card-dark mb-3">
        <div class="card-header"><h3 class="card-title">{{ trans('hr-manager::landings.views_by_day') }}</h3></div>
        <div class="card-body">
            @php $maxViews = max(1, max(array_column($stats['by_day'], 'views'))); @endphp
            @foreach($stats['by_day'] as $day)
                <div style="display: flex; align-items: center; gap: 8px; padding: 2px 0;">
                    <small style="width: 90px; color: var(--hr-text-muted); font-family: monospace;">{{ $day['date'] }}</small>
                    <div style="flex: 1; background: rgba(255,255,255,0.05); height: 12px; border-radius: 3px; overflow: hidden;">
                        <div style="width: {{ ($day['views'] / $maxViews) * 100 }}%; background: var(--hr-success, #28a745); height: 100%;"></div>
                    </div>
                    <small style="width: 80px; text-align: right; color: var(--hr-text-light);">{{ $day['views'] }} views</small>
                    @if($day['apply_clicks'])
                        <small style="color: var(--hr-text-muted);">({{ $day['apply_clicks'] }} clicks)</small>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    {{-- Top referrers --}}
    <div class="card card-dark mb-3">
        <div class="card-header"><h3 class="card-title">{{ trans('hr-manager::landings.top_referrers') }}</h3></div>
        <div class="card-body p-0">
            @if(empty($stats['top_referrers']))
                <p class="text-muted text-center p-3 mb-0">{{ trans('hr-manager::landings.no_referrers') }}</p>
            @else
                <table class="table table-hover mb-0">
                    <tbody>
                        @foreach($stats['top_referrers'] as $r)
                            <tr>
                                <td>{{ $r['domain'] }}</td>
                                <td class="text-right">{{ $r['count'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    <a href="{{ route('hr-manager.landings.edit', $landing->id) }}" class="btn btn-hr-secondary"><i class="fas fa-arrow-left"></i> Edit page</a>
    <a href="{{ $landing->public_url }}" target="_blank" class="btn btn-hr-secondary"><i class="fas fa-external-link-alt"></i> Open public page</a>

</div>
@endsection
