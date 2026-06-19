{{--
    Wallet Audit panel — director-only fraud-detection view that
    aggregates CWM + MM signals into income / expense / audit
    sections. Renders nothing when no source data is available
    (CWM + MM both absent).

    Shape expected:
      $walletAudit['available']         bool
      $walletAudit['income_profile']    lifetime_contributed, ratting_income_6mo,
                                        mining_value_6mo, combined_income,
                                        categories[], ratting_available,
                                        mining_available
      $walletAudit['expense_profile']   lifetime_withdrawn, net_position_6mo,
                                        is_net_positive, recent_withdrawals[]
      $walletAudit['audit_signals']     compliance_pct + severity, tax_owed,
                                        tax_paid, income_to_tax_ratio,
                                        is_net_negative, wallet_flags[]
--}}
@if(!empty($walletAudit['available']))
    @php
        $iskFormatAudit = function ($v) {
            if ($v === null) return '-';
            $abs = abs((float) $v);
            if ($abs >= 1.0e12) return number_format($v / 1.0e12, 2) . ' T';
            if ($abs >= 1.0e9)  return number_format($v / 1.0e9, 2)  . ' B';
            if ($abs >= 1.0e6)  return number_format($v / 1.0e6, 2)  . ' M';
            if ($abs >= 1.0e3)  return number_format($v / 1.0e3, 2)  . ' k';
            return number_format($v, 0);
        };
        $income  = $walletAudit['income_profile'] ?? [];
        $expense = $walletAudit['expense_profile'] ?? [];
        $signals = $walletAudit['audit_signals'] ?? [];

        // Severity color mapping for the compliance pill
        $sevColor = [
            'good'     => 'var(--hr-success)',
            'warning'  => 'var(--hr-warning)',
            'critical' => 'var(--hr-danger)',
            'unknown'  => 'var(--hr-text-muted)',
        ][$signals['compliance_severity'] ?? 'unknown'] ?? 'var(--hr-text-muted)';

        $ratio = $signals['income_to_tax_ratio'] ?? null;
        // Income-to-tax ratio thresholds (CCP corp tax is 10-30% typically;
        // ratios under 5% are suspicious territory for an active member).
        if ($ratio === null) {
            $ratioSeverity = 'unknown';
        } elseif ($ratio >= 10) {
            $ratioSeverity = 'good';
        } elseif ($ratio >= 5) {
            $ratioSeverity = 'warning';
        } else {
            $ratioSeverity = 'critical';
        }
        $ratioColor = [
            'good'     => 'var(--hr-success)',
            'warning'  => 'var(--hr-warning)',
            'critical' => 'var(--hr-danger)',
            'unknown'  => 'var(--hr-text-muted)',
        ][$ratioSeverity] ?? 'var(--hr-text-muted)';

        // Suspicious flags subset for visual emphasis at the top
        $criticalFlagSet = ['negative_contribution', 'compliance_very_low', 'silent_wallet_director'];
        $warningFlagSet  = ['stalled', 'compliance_low', 'loyalty_hold'];
        $criticalFlags = array_values(array_intersect($criticalFlagSet, $signals['wallet_flags'] ?? []));
        $warningFlags  = array_values(array_intersect($warningFlagSet, $signals['wallet_flags'] ?? []));
    @endphp

    <div class="card card-dark mb-3" style="border-left: 4px solid var(--hr-primary-start);">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-balance-scale"></i> {{ trans('hr-manager::members.audit_heading') }}
                <span class="badge badge-hr badge-rejected ml-2" title="{{ trans('hr-manager::members.audit_director_only_help') }}">
                    <i class="fas fa-user-shield"></i> {{ trans('hr-manager::members.director_only_label') }}
                </span>
            </h3>
            <div class="card-tools">
                <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::members.audit_subtitle') }}</small>
            </div>
        </div>
        <div class="card-body">

            {{-- ============================================================
                 (1) Audit signals — the headline fraud-detection chips
                 ============================================================ --}}
            <div class="row mb-3">
                {{-- Tax compliance --}}
                <div class="col-md-3 text-center">
                    <div style="font-size: 0.85rem; color: var(--hr-text-muted); text-transform: uppercase; letter-spacing: 0.5px;">{{ trans('hr-manager::members.audit_compliance') }}</div>
                    <div style="font-size: 1.8rem; font-weight: 700; color: {{ $sevColor }};">
                        {{ $signals['compliance_pct'] !== null ? number_format($signals['compliance_pct'], 1) . '%' : '-' }}
                    </div>
                    <small style="color: var(--hr-text-muted);">
                        {{ $iskFormatAudit($signals['tax_paid'] ?? null) }} / {{ $iskFormatAudit($signals['tax_owed'] ?? null) }} ISK
                    </small>
                </div>

                {{-- Income-to-tax ratio (key fraud metric) --}}
                <div class="col-md-3 text-center">
                    <div style="font-size: 0.85rem; color: var(--hr-text-muted); text-transform: uppercase; letter-spacing: 0.5px;">{{ trans('hr-manager::members.audit_income_tax_ratio') }}</div>
                    <div style="font-size: 1.8rem; font-weight: 700; color: {{ $ratioColor }};">
                        {{ $ratio !== null ? number_format($ratio, 2) . '%' : '-' }}
                    </div>
                    <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::members.audit_income_tax_help') }}</small>
                </div>

                {{-- Net position --}}
                <div class="col-md-3 text-center">
                    <div style="font-size: 0.85rem; color: var(--hr-text-muted); text-transform: uppercase; letter-spacing: 0.5px;">{{ trans('hr-manager::members.audit_net_position') }}</div>
                    <div style="font-size: 1.8rem; font-weight: 700; color: {{ ($expense['is_net_positive'] ?? true) ? 'var(--hr-success)' : 'var(--hr-danger)' }};">
                        {{ $iskFormatAudit($expense['net_position_6mo'] ?? null) }}
                    </div>
                    <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::members.audit_net_position_help') }}</small>
                </div>

                {{-- Flags count --}}
                <div class="col-md-3 text-center">
                    <div style="font-size: 0.85rem; color: var(--hr-text-muted); text-transform: uppercase; letter-spacing: 0.5px;">{{ trans('hr-manager::members.audit_flags') }}</div>
                    <div style="font-size: 1.8rem; font-weight: 700; color: {{ count($criticalFlags) > 0 ? 'var(--hr-danger)' : (count($warningFlags) > 0 ? 'var(--hr-warning)' : 'var(--hr-success)') }};">
                        {{ count($criticalFlags) + count($warningFlags) }}
                    </div>
                    @if(!empty($criticalFlags) || !empty($warningFlags))
                        <small style="color: var(--hr-text-muted);">
                            @foreach($criticalFlags as $f)<span class="badge badge-danger mr-1">{{ strtoupper(substr($f, 0, 3)) }}</span>@endforeach
                            @foreach($warningFlags as $f)<span class="badge badge-warning mr-1">{{ strtoupper(substr($f, 0, 3)) }}</span>@endforeach
                        </small>
                    @else
                        <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::members.audit_no_flags') }}</small>
                    @endif
                </div>
            </div>

            <hr style="border-color: rgba(255,255,255,0.06);">

            {{-- ============================================================
                 (2) Income profile
                 ============================================================ --}}
            <div class="row">
                <div class="col-md-6">
                    <h4 style="color: var(--hr-text-white); font-size: 1rem;">
                        <i class="fas fa-arrow-down" style="color: var(--hr-success);"></i> {{ trans('hr-manager::members.audit_income_profile') }}
                    </h4>
                    <table class="table table-sm table-dark mb-2">
                        <tbody>
                            <tr>
                                <td>{{ trans('hr-manager::members.audit_contributed_lifetime') }}</td>
                                <td class="text-right"><strong>{{ $iskFormatAudit($income['lifetime_contributed'] ?? null) }}</strong></td>
                            </tr>
                            @if(!empty($income['ratting_available']))
                                <tr>
                                    <td>{{ trans('hr-manager::members.audit_ratting_6mo') }}</td>
                                    <td class="text-right"><strong>{{ $iskFormatAudit($income['ratting_income_6mo'] ?? null) }}</strong></td>
                                </tr>
                            @endif
                            @if(!empty($income['mining_available']))
                                <tr>
                                    <td>{{ trans('hr-manager::members.audit_mining_6mo') }}</td>
                                    <td class="text-right"><strong>{{ $iskFormatAudit($income['mining_value_6mo'] ?? null) }}</strong></td>
                                </tr>
                            @endif
                            <tr style="border-top: 2px solid var(--hr-border);">
                                <td><strong>{{ trans('hr-manager::members.audit_total_income') }}</strong></td>
                                <td class="text-right"><strong style="color: var(--hr-success);">{{ $iskFormatAudit($income['combined_income'] ?? null) }}</strong></td>
                            </tr>
                        </tbody>
                    </table>

                    @if(!empty($income['categories']))
                        <small style="color: var(--hr-text-muted); text-transform: uppercase; letter-spacing: 0.5px;">{{ trans('hr-manager::members.audit_top_sources') }}</small>
                        @foreach(array_slice($income['categories'], 0, 5) as $cat)
                            <div class="mb-1" style="font-size: 0.9rem;">
                                <div style="display: flex; justify-content: space-between;">
                                    <span style="color: var(--hr-text-light);">{{ $cat['name'] }}</span>
                                    <span style="color: var(--hr-text-white);">{{ $iskFormatAudit($cat['amount']) }}</span>
                                </div>
                                <div style="background-color: rgba(255,255,255,0.05); height: 4px; border-radius: 2px; overflow: hidden; margin-top: 2px;">
                                    <div style="width: {{ max(2, min(100, (float) ($cat['pct'] ?? 0))) }}%; background: var(--hr-success); height: 100%;"></div>
                                </div>
                            </div>
                        @endforeach
                    @endif
                </div>

                {{-- ============================================================
                     (3) Expense profile
                     ============================================================ --}}
                <div class="col-md-6">
                    <h4 style="color: var(--hr-text-white); font-size: 1rem;">
                        <i class="fas fa-arrow-up" style="color: var(--hr-danger);"></i> {{ trans('hr-manager::members.audit_expense_profile') }}
                    </h4>
                    <table class="table table-sm table-dark mb-2">
                        <tbody>
                            <tr>
                                <td>{{ trans('hr-manager::members.audit_withdrawn_lifetime') }}</td>
                                <td class="text-right"><strong>{{ $iskFormatAudit($expense['lifetime_withdrawn'] ?? null) }}</strong></td>
                            </tr>
                            <tr>
                                <td>{{ trans('hr-manager::members.audit_net_position_6mo') }}</td>
                                <td class="text-right"><strong style="color: {{ ($expense['is_net_positive'] ?? true) ? 'var(--hr-success)' : 'var(--hr-danger)' }};">{{ $iskFormatAudit($expense['net_position_6mo'] ?? null) }}</strong></td>
                            </tr>
                        </tbody>
                    </table>

                    @if(!empty($expense['recent_withdrawals']))
                        <small style="color: var(--hr-text-muted); text-transform: uppercase; letter-spacing: 0.5px;">{{ trans('hr-manager::members.audit_recent_withdrawals') }}</small>
                        @foreach($expense['recent_withdrawals'] as $w)
                            <div class="mb-1" style="font-size: 0.85rem; padding: 6px 10px; background: rgba(220, 53, 69, 0.08); border-left: 2px solid var(--hr-danger); border-radius: 3px;">
                                <div style="display: flex; justify-content: space-between;">
                                    <span style="color: var(--hr-text-light);">{{ $w['period'] ?? '-' }}</span>
                                    <strong style="color: var(--hr-danger);">{{ $iskFormatAudit($w['amount']) }}</strong>
                                </div>
                                @if(!empty($w['category']) || !empty($w['note']))
                                    <small style="color: var(--hr-text-muted);">{{ $w['category'] ?? '' }}@if(!empty($w['note'])) — {{ $w['note'] }}@endif</small>
                                @endif
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>

            {{-- ============================================================
                 (3.5) Ratting detail — income source + monthly trend.
                 Honest framing: "source" = bounties vs missions (the
                 wallet ref_type split). NOT site type — CCP doesn't
                 expose Sanctum-vs-Haven anywhere in the journal.
                 ============================================================ --}}
            @php $rd = $walletAudit['ratting_detail'] ?? null; @endphp
            @if(!empty($rd['available']) && (!empty($rd['sources']) || !empty($rd['series'])))
                @php
                    $iskRd = function ($v) {
                        $abs = abs((float) $v);
                        if ($abs >= 1e12) return number_format($v/1e12, 2).'T';
                        if ($abs >= 1e9)  return number_format($v/1e9, 2).'B';
                        if ($abs >= 1e6)  return number_format($v/1e6, 2).'M';
                        if ($abs >= 1e3)  return number_format($v/1e3, 1).'K';
                        return number_format($v, 0);
                    };
                @endphp
                <div class="card-dark mt-3" style="background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.08); border-radius: 6px; padding: 14px;">
                    <small style="color: var(--hr-text-muted); text-transform: uppercase; letter-spacing: 0.5px;">
                        {{ trans('hr-manager::members.audit_ratting_detail_heading') }}
                    </small>
                    <div class="row mt-2">
                        {{-- Income source split --}}
                        @if(!empty($rd['sources']))
                            <div class="col-md-6">
                                <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::members.audit_ratting_source') }}</small>
                                @foreach($rd['sources'] as $src)
                                    <div class="mt-1">
                                        <div class="d-flex justify-content-between" style="font-size: 0.85rem;">
                                            <span style="color: var(--hr-text-white);">
                                                @if($src['key'] === 'bounties')<i class="fas fa-crosshairs text-danger"></i>@else<i class="fas fa-scroll text-info"></i>@endif
                                                {{ $src['label'] }}
                                            </span>
                                            <span style="color: var(--hr-text-light);">{{ $iskRd($src['amount']) }} · {{ number_format($src['pct'], 0) }}%</span>
                                        </div>
                                        <div style="height: 5px; background: rgba(255,255,255,0.06); border-radius: 3px; overflow: hidden; margin-top: 2px;">
                                            <div style="height: 100%; width: {{ min(100, $src['pct']) }}%; background: {{ $src['key'] === 'bounties' ? 'var(--hr-danger, #dc3545)' : 'var(--hr-info, #17a2b8)' }};"></div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        {{-- Monthly trend sparkline --}}
                        @if(!empty($rd['series']) && count($rd['series']) > 1)
                            <div class="col-md-6">
                                <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::members.audit_ratting_trend') }}</small>
                                @php
                                    $vals = array_map(fn($s) => $s['amount'], $rd['series']);
                                    $maxV = max($vals) ?: 1;
                                    $w = 100; $h = 32; $n = count($vals);
                                    $step = $n > 1 ? $w / ($n - 1) : 0;
                                    $pts = [];
                                    foreach ($vals as $i => $v) {
                                        $x = round($i * $step, 1);
                                        $y = round($h - ($v / $maxV) * ($h - 4) - 2, 1);
                                        $pts[] = "$x,$y";
                                    }
                                    $poly = implode(' ', $pts);
                                @endphp
                                <svg viewBox="0 0 {{ $w }} {{ $h }}" preserveAspectRatio="none" style="width: 100%; height: 40px; margin-top: 4px;">
                                    <polyline points="{{ $poly }}" fill="none" stroke="var(--hr-primary, #667eea)" stroke-width="1.5" vector-effect="non-scaling-stroke"/>
                                </svg>
                                <div class="d-flex justify-content-between" style="font-size: 0.7rem; color: var(--hr-text-muted);">
                                    <span>{{ $rd['series'][0]['month'] }}</span>
                                    <span>{{ $rd['series'][count($rd['series'])-1]['month'] }}</span>
                                </div>
                            </div>
                        @endif
                    </div>
                    <small class="d-block mt-2" style="color: var(--hr-text-muted); font-size: 0.78rem;">
                        <i class="fas fa-info-circle"></i> {{ trans('hr-manager::members.audit_ratting_caveat') }}
                    </small>
                </div>
            @endif

            {{-- ============================================================
                 (4) Operator interpretation cheat sheet
                 ============================================================ --}}
            <div class="info-box mt-3">
                <i class="fas fa-lightbulb"></i>
                <div>
                    <strong>{{ trans('hr-manager::members.audit_interpretation_label') }}:</strong>
                    <ul class="mb-0" style="font-size: 0.9rem;">
                        <li>{!! trans('hr-manager::members.audit_tip_compliance') !!}</li>
                        <li>{!! trans('hr-manager::members.audit_tip_ratio') !!}</li>
                        <li>{!! trans('hr-manager::members.audit_tip_net') !!}</li>
                        <li>{!! trans('hr-manager::members.audit_tip_flags') !!}</li>
                    </ul>
                </div>
            </div>

        </div>
    </div>
@endif
