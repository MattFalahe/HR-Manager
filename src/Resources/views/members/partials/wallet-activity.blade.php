{{--
    Wallet Activity panel — renders CWM contribution signals routed through
    MC's PluginBridge. The controller pre-fetches everything into
    $walletActivity so this partial is pure rendering: no service calls,
    no DB queries, no class_exists checks.

    Shape (built by MemberController::buildWalletActivity):
        $walletActivity['available']    : bool
        $walletActivity['lifetime']     : ['available' => bool, 'data' => ?obj]
        $walletActivity['trend']        : ['available' => bool, 'data' => ?obj]
        $walletActivity['gaps']         : ['available' => bool, 'data' => ?obj]
        $walletActivity['net_position'] : ['available' => bool, 'data' => ?obj]
        $walletActivity['tax']          : ['available' => bool, 'data' => ?obj]

    The underlying payload "data" can itself be null even when available=true
    (e.g. MM tax compliance when MM isn't installed); each sub-block branches
    on its own data presence.
--}}
<div class="card card-dark mb-3">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-wallet"></i> Wallet Activity (last 6 months)
        </h3>
        <div class="card-tools">
            <small style="color: var(--hr-text-muted);">data via Corp Wallet Manager</small>
        </div>
    </div>
    <div class="card-body">

        @if(empty($walletActivity['available']))
            <p class="text-muted text-center mb-0" style="color: var(--hr-text-muted) !important;">
                <i class="fas fa-info-circle"></i>
                Wallet signals unavailable (Corp Wallet Manager / Manager Core not installed)
            </p>
        @else

            {{-- ============================================================
                 (a) Lifetime summary
                 ============================================================ --}}
            @php
                $lifetimeData = $walletActivity['lifetime']['data'] ?? null;
                $netData      = $walletActivity['net_position']['data'] ?? null;

                $lifetimeContributed = $lifetimeData->lifetime_total_contributed ?? ($lifetimeData['lifetime_total_contributed'] ?? null);
                $lifetimeWithdrawn   = $lifetimeData->lifetime_total_withdrawn   ?? ($lifetimeData['lifetime_total_withdrawn']   ?? null);
                $monthsActive        = $lifetimeData->months_active              ?? ($lifetimeData['months_active']              ?? null);
                $monthsInCorp        = $lifetimeData->months_in_corp             ?? ($lifetimeData['months_in_corp']             ?? null);
                $firstPeriod         = $lifetimeData->first_contribution_period  ?? ($lifetimeData['first_contribution_period']  ?? null);
                $lastPeriod          = $lifetimeData->last_contribution_period   ?? ($lifetimeData['last_contribution_period']   ?? null);

                $netValue          = $netData->net_amount         ?? ($netData['net_amount']         ?? null);
                $netIsPositive     = $netData->is_net_positive    ?? ($netData['is_net_positive']    ?? null);

                // Compact ISK helper — chooses k/M/B/T suffix
                $iskFormat = function ($v) {
                    if ($v === null) return '-';
                    $abs = abs((float) $v);
                    if ($abs >= 1.0e12) return number_format($v / 1.0e12, 2) . ' T';
                    if ($abs >= 1.0e9)  return number_format($v / 1.0e9, 2)  . ' B';
                    if ($abs >= 1.0e6)  return number_format($v / 1.0e6, 2)  . ' M';
                    if ($abs >= 1.0e3)  return number_format($v / 1.0e3, 2)  . ' k';
                    return number_format($v, 0);
                };
            @endphp

            @if($lifetimeData !== null)
                <div class="mb-4" style="background-color: var(--hr-dark-card); border: 1px solid var(--hr-border); border-radius: var(--hr-radius-md); padding: var(--hr-spacing-md);">
                    <div style="display: flex; align-items: baseline; gap: var(--hr-spacing-sm); flex-wrap: wrap;">
                        <div>
                            <div style="font-size: 2rem; font-weight: 700; color: var(--hr-text-white); line-height: 1;">
                                {{ $iskFormat($lifetimeContributed) }} <small style="font-size: 0.9rem; color: var(--hr-text-muted); font-weight: 400;">ISK contributed</small>
                            </div>
                        </div>
                    </div>
                    <div class="mt-2" style="color: var(--hr-text-light); font-size: 0.9rem;">
                        @if($monthsActive !== null && $monthsInCorp !== null)
                            <div>
                                <i class="fas fa-calendar-check" style="color: var(--hr-text-muted); width: 16px;"></i>
                                {{ $monthsActive }} months active out of {{ $monthsInCorp }} in corp
                            </div>
                        @endif
                        @if($firstPeriod || $lastPeriod)
                            <div>
                                <i class="fas fa-clock" style="color: var(--hr-text-muted); width: 16px;"></i>
                                First: <span style="color: var(--hr-text-white);">{{ $firstPeriod ?? '-' }}</span>
                                <span style="color: var(--hr-text-muted); margin: 0 6px;">|</span>
                                Last: <span style="color: var(--hr-text-white);">{{ $lastPeriod ?? '-' }}</span>
                            </div>
                        @endif
                        @if($lifetimeWithdrawn !== null || $netValue !== null)
                            <div class="mt-1" style="border-top: 1px solid var(--hr-border); padding-top: 6px;">
                                <i class="fas fa-arrow-down" style="color: var(--hr-text-muted); width: 16px;"></i>
                                Withdrawn: <span style="color: var(--hr-text-white);">{{ $iskFormat($lifetimeWithdrawn) }}</span>
                                @if($netValue !== null)
                                    <span style="color: var(--hr-text-muted); margin: 0 6px;">|</span>
                                    Net:
                                    <strong style="color: {{ $netIsPositive ? 'var(--hr-success)' : 'var(--hr-danger)' }};">
                                        {{ ($netValue >= 0 ? '+' : '') . $iskFormat($netValue) }}
                                    </strong>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            {{-- ============================================================
                 (b) Trend sparkline
                 ============================================================ --}}
            @php
                $trendData = $walletActivity['trend']['data'] ?? null;
                $series    = $trendData->series ?? ($trendData['series'] ?? []);
                if (is_object($series)) {
                    $series = (array) $series;
                }
                // Series may be a list of period objects {period, amount} or
                // a plain array of numbers. Normalise to [['label', value], ...].
                $sparkPoints = [];
                foreach ((array) $series as $row) {
                    if (is_array($row) || is_object($row)) {
                        $r = (array) $row;
                        $label = $r['period'] ?? ($r['month'] ?? null);
                        $value = (float) ($r['amount'] ?? ($r['value'] ?? 0));
                        $sparkPoints[] = ['label' => $label, 'value' => $value];
                    } else {
                        $sparkPoints[] = ['label' => null, 'value' => (float) $row];
                    }
                }
                // CWM series is newest-first; reverse to render oldest -> newest left-to-right
                $sparkPoints = array_reverse($sparkPoints);

                $recentVsPriorPct = $trendData->recent_vs_prior_pct ?? ($trendData['recent_vs_prior_pct'] ?? null);

                // SVG dims
                $svgW = 280; $svgH = 60; $pad = 4;
                $maxVal = 0;
                foreach ($sparkPoints as $p) { if ($p['value'] > $maxVal) $maxVal = $p['value']; }
                $pathD = '';
                $pointsForFill = [];
                $count = count($sparkPoints);
                if ($count > 0) {
                    $stepX = $count > 1 ? ($svgW - 2 * $pad) / ($count - 1) : 0;
                    $usableH = $svgH - 2 * $pad;
                    foreach ($sparkPoints as $i => $p) {
                        $x = $pad + $i * $stepX;
                        $y = $maxVal > 0
                            ? ($svgH - $pad - ($p['value'] / $maxVal) * $usableH)
                            : ($svgH - $pad);
                        $cmd = $i === 0 ? 'M' : 'L';
                        $pathD .= sprintf('%s%.2f,%.2f ', $cmd, $x, $y);
                        $pointsForFill[] = sprintf('%.2f,%.2f', $x, $y);
                    }
                }
            @endphp

            @if(!empty($sparkPoints))
                <div class="mb-4">
                    <div style="font-size: 0.85rem; color: var(--hr-text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">
                        Contribution trend
                    </div>
                    <div style="background-color: var(--hr-dark-card); border: 1px solid var(--hr-border); border-radius: var(--hr-radius-md); padding: var(--hr-spacing-sm);">
                        <svg viewBox="0 0 {{ $svgW }} {{ $svgH }}" preserveAspectRatio="none"
                             style="width: 100%; height: 60px; display: block;"
                             role="img" aria-label="Monthly contribution trend">
                            @if(count($pointsForFill) >= 2)
                                <polygon
                                    points="{{ implode(' ', $pointsForFill) }} {{ sprintf('%.2f,%.2f', $svgW - $pad, $svgH - $pad) }} {{ sprintf('%.2f,%.2f', $pad, $svgH - $pad) }}"
                                    fill="rgba(102, 126, 234, 0.15)" />
                            @endif
                            <path d="{{ trim($pathD) }}"
                                  fill="none"
                                  stroke="var(--hr-primary-start, #667eea)"
                                  stroke-width="2"
                                  stroke-linecap="round"
                                  stroke-linejoin="round" />
                            @foreach($pointsForFill as $i => $pt)
                                @php [$cx, $cy] = explode(',', $pt); @endphp
                                <circle cx="{{ $cx }}" cy="{{ $cy }}" r="2"
                                        fill="var(--hr-primary-start, #667eea)" />
                            @endforeach
                        </svg>
                        <div class="mt-1" style="font-size: 0.85rem;">
                            Trend:
                            @if($recentVsPriorPct === null)
                                <span style="color: var(--hr-text-muted);">-</span>
                            @else
                                @php $delta = (float) $recentVsPriorPct; @endphp
                                <strong style="color: {{ $delta >= 0 ? 'var(--hr-success)' : 'var(--hr-danger)' }};">
                                    {{ ($delta >= 0 ? '+' : '') . number_format($delta, 1) }}%
                                </strong>
                                <span style="color: var(--hr-text-muted);"> vs prior 3 months</span>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            {{-- ============================================================
                 (c) Activity strip — 12 monthly squares
                 ============================================================ --}}
            @php
                $gapsData = $walletActivity['gaps']['data'] ?? null;
                $activityMonths = $gapsData->months ?? ($gapsData['months'] ?? []);
                if (is_object($activityMonths)) $activityMonths = (array) $activityMonths;

                // Each entry expected to be {period, active|amount}.
                // active = (active === true) OR (amount > 0).
                $stripCells = [];
                foreach ((array) $activityMonths as $row) {
                    $r = is_array($row) || is_object($row) ? (array) $row : ['amount' => (float) $row];
                    $period = $r['period'] ?? ($r['month'] ?? null);
                    $active = isset($r['active'])
                        ? (bool) $r['active']
                        : ((float) ($r['amount'] ?? 0) > 0);
                    $stripCells[] = ['period' => $period, 'active' => $active];
                }
                // CWM responses are newest-first; reverse to render oldest -> newest left-to-right
                $stripCells = array_reverse($stripCells);

                $longestGap = $gapsData->longest_gap_months ?? ($gapsData['longest_gap_months'] ?? null);
                $lastActive = $gapsData->last_active_period ?? ($gapsData['last_active_period'] ?? null);
            @endphp

            @if(!empty($stripCells))
                <div class="mb-4">
                    <div style="font-size: 0.85rem; color: var(--hr-text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">
                        Activity (last 12 months)
                    </div>
                    <div style="display: flex; gap: 4px; flex-wrap: nowrap;">
                        @foreach($stripCells as $cell)
                            <div title="{{ $cell['period'] ?? '?' }}: {{ $cell['active'] ? 'active' : 'no contributions' }}"
                                 style="flex: 1; min-width: 14px; height: 22px; border-radius: 3px; border: 1px solid var(--hr-border);
                                        background-color: {{ $cell['active'] ? 'rgba(40, 167, 69, 0.7)' : 'rgba(108, 117, 125, 0.25)' }};"></div>
                        @endforeach
                    </div>
                    <div class="mt-2" style="font-size: 0.85rem; color: var(--hr-text-muted);">
                        Longest gap: <span style="color: var(--hr-text-light);">{{ $longestGap !== null ? $longestGap . ' month' . ($longestGap === 1 ? '' : 's') : '-' }}</span>
                        <span style="margin: 0 6px;">|</span>
                        Last active: <span style="color: var(--hr-text-light);">{{ $lastActive ?? '-' }}</span>
                    </div>
                </div>
            @endif

            {{-- ============================================================
                 (d.5) Contribution percentile (CWM Round-3 surfacing)
                 ============================================================ --}}
            @php
                $pctData = $walletActivity['percentile']['data'] ?? null;
                $pctValue = $pctData->percentile ?? ($pctData['percentile'] ?? null);
                $charAmount = $pctData->character_amount ?? ($pctData['character_amount'] ?? null);
                $corpMedian = $pctData->corp_median ?? ($pctData['corp_median'] ?? null);
            @endphp
            @if($pctValue !== null)
                <div class="mb-4">
                    <div style="font-size: 0.85rem; color: var(--hr-text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">
                        Contribution rank (last 3 months)
                    </div>
                    @php
                        $pctNum = (int) $pctValue;
                        if ($pctNum >= 90)      { $rankColor = 'var(--hr-success)'; $rankLabel = 'Top 10%'; }
                        elseif ($pctNum >= 75)  { $rankColor = 'var(--hr-success)'; $rankLabel = 'Top 25%'; }
                        elseif ($pctNum >= 50)  { $rankColor = 'var(--hr-info, #17a2b8)'; $rankLabel = 'Above median'; }
                        elseif ($pctNum >= 25)  { $rankColor = 'var(--hr-warning)'; $rankLabel = 'Bottom 50%'; }
                        else                    { $rankColor = 'var(--hr-danger)'; $rankLabel = 'Bottom 25%'; }
                    @endphp
                    <div style="background-color: var(--hr-dark-card); border: 1px solid var(--hr-border); border-radius: var(--hr-radius-md); padding: var(--hr-spacing-md);">
                        <div style="display: flex; align-items: center; gap: var(--hr-spacing-md); flex-wrap: wrap;">
                            <div>
                                <div style="font-size: 1.8rem; font-weight: 700; color: {{ $rankColor }}; line-height: 1;">
                                    {{ $pctNum }}<small style="font-size: 0.7em; color: var(--hr-text-muted); font-weight: 400;">th percentile</small>
                                </div>
                                <div style="font-size: 0.9rem; color: var(--hr-text-light); margin-top: 4px;">{{ $rankLabel }}</div>
                            </div>
                            <div style="flex: 1; min-width: 160px; font-size: 0.85rem; color: var(--hr-text-muted);">
                                @if($charAmount !== null && $corpMedian !== null)
                                    <div>This char: <span style="color: var(--hr-text-white);">{{ $iskFormat($charAmount) }}</span></div>
                                    <div>Corp median: <span style="color: var(--hr-text-white);">{{ $iskFormat($corpMedian) }}</span></div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            {{-- ============================================================
                 (d.6) Top contribution categories (CWM Round-3 surfacing)
                 ============================================================ --}}
            @php
                $breakdownData = $walletActivity['breakdown']['data'] ?? null;
                $categories = $breakdownData->categories ?? ($breakdownData['categories'] ?? null);
                if (is_object($categories)) $categories = (array) $categories;

                // Normalise into [['name' => ..., 'amount' => ..., 'pct' => ?], ...]
                $catRows = [];
                if (is_array($categories)) {
                    foreach ($categories as $key => $row) {
                        $r = is_array($row) || is_object($row) ? (array) $row : ['amount' => (float) $row];
                        $name   = $r['category'] ?? $r['name'] ?? (is_string($key) ? $key : '?');
                        $amount = (float) ($r['amount'] ?? $r['total'] ?? 0);
                        $pct    = isset($r['pct']) ? (float) $r['pct'] : null;
                        if ($amount > 0) {
                            $catRows[] = ['name' => (string) $name, 'amount' => $amount, 'pct' => $pct];
                        }
                    }
                    // Sort descending by amount + cap to top 5
                    usort($catRows, fn($a, $b) => $b['amount'] <=> $a['amount']);
                    $catRows = array_slice($catRows, 0, 5);

                    // Compute share-of-top-5 percentages when CWM didn't ship one
                    $totalShown = array_sum(array_column($catRows, 'amount'));
                    foreach ($catRows as &$cr) {
                        if ($cr['pct'] === null && $totalShown > 0) {
                            $cr['pct'] = ($cr['amount'] / $totalShown) * 100;
                        }
                    }
                    unset($cr);
                }
            @endphp
            @if(!empty($catRows))
                <div class="mb-4">
                    <div style="font-size: 0.85rem; color: var(--hr-text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">
                        Top categories
                    </div>
                    <div style="background-color: var(--hr-dark-card); border: 1px solid var(--hr-border); border-radius: var(--hr-radius-md); padding: var(--hr-spacing-md);">
                        @foreach($catRows as $row)
                            <div class="mb-2">
                                <div style="display: flex; justify-content: space-between; font-size: 0.9rem;">
                                    <span style="color: var(--hr-text-white);">{{ $row['name'] }}</span>
                                    <span style="color: var(--hr-text-light);">{{ $iskFormat($row['amount']) }}</span>
                                </div>
                                <div style="background-color: rgba(255,255,255,0.05); height: 6px; border-radius: 3px; overflow: hidden; border: 1px solid var(--hr-border); margin-top: 3px;">
                                    <div style="width: {{ max(2, min(100, (float) ($row['pct'] ?? 0))) }}%; background: var(--hr-primary-start, #667eea); height: 100%;"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- ============================================================
                 (d.7) Latest 5 entries (CWM Round-3 surfacing)
                 ============================================================ --}}
            @php
                $entriesData = $walletActivity['entries']['data'] ?? null;
                $entryRows = $entriesData->entries ?? ($entriesData['entries'] ?? null);
                if (is_object($entryRows)) $entryRows = (array) $entryRows;

                $entryList = [];
                if (is_array($entryRows)) {
                    foreach ($entryRows as $row) {
                        $r = is_array($row) || is_object($row) ? (array) $row : [];
                        $entryList[] = [
                            'period'   => $r['period'] ?? $r['date'] ?? null,
                            'amount'   => isset($r['amount']) ? (float) $r['amount'] : null,
                            'category' => $r['category'] ?? $r['type'] ?? null,
                            'note'     => $r['note'] ?? $r['memo'] ?? null,
                        ];
                    }
                    $entryList = array_slice($entryList, 0, 5);
                }
            @endphp
            @if(!empty($entryList))
                <div class="mb-4">
                    <div style="font-size: 0.85rem; color: var(--hr-text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">
                        Latest entries
                    </div>
                    <div style="background-color: var(--hr-dark-card); border: 1px solid var(--hr-border); border-radius: var(--hr-radius-md); overflow: hidden;">
                        @foreach($entryList as $i => $row)
                            <div style="padding: 8px 12px; @if(!$loop->last) border-bottom: 1px solid var(--hr-border); @endif font-size: 0.9rem;">
                                <div style="display: flex; justify-content: space-between; gap: 12px;">
                                    <span style="color: var(--hr-text-light);">{{ $row['period'] ?? '-' }}</span>
                                    <strong style="color: var(--hr-text-white);">{{ $row['amount'] !== null ? $iskFormat($row['amount']) : '-' }}</strong>
                                </div>
                                @if($row['category'])
                                    <small style="color: var(--hr-text-muted);">{{ $row['category'] }}@if($row['note']) — {{ $row['note'] }}@endif</small>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- ============================================================
                 (d) Tax compliance bar
                 ============================================================ --}}
            @php
                $taxAvail = $walletActivity['tax']['available'] ?? false;
                $taxData  = $walletActivity['tax']['data'] ?? null;
                // MC's wallet bridge returns available=true with data=null
                // when MM is the underlying source and MM isn't installed.
                // Render the muted "MM not installed" label in that case.
            @endphp

            <div class="mb-2">
                <div style="font-size: 0.85rem; color: var(--hr-text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">
                    Mining tax compliance
                </div>
                @if($taxAvail && $taxData === null)
                    <p class="text-muted mb-0" style="color: var(--hr-text-muted) !important; font-size: 0.9rem;">
                        <i class="fas fa-info-circle"></i> Mining Manager not installed
                    </p>
                @elseif($taxData !== null)
                    @php
                        $totalPaid = $taxData->total_paid ?? ($taxData['total_paid'] ?? 0);
                        $totalOwed = $taxData->total_owed ?? ($taxData['total_owed'] ?? 0);
                        $pct       = $taxData->compliance_pct ?? ($taxData['compliance_pct'] ?? null);
                        if ($pct === null) {
                            $pct = $totalOwed > 0 ? round(($totalPaid / $totalOwed) * 100, 1) : 100;
                        }
                        $pct = max(0, min(100, (float) $pct));
                        $periodsOverdue = $taxData->periods_overdue ?? ($taxData['periods_overdue'] ?? null);

                        if ($pct >= 80)      { $barColour = 'var(--hr-success)'; }
                        elseif ($pct >= 50)  { $barColour = 'var(--hr-warning)'; }
                        else                 { $barColour = 'var(--hr-danger)'; }
                    @endphp
                    <div style="background-color: rgba(255,255,255,0.05); height: 16px; border-radius: 3px; overflow: hidden; border: 1px solid var(--hr-border);">
                        <div style="width: {{ $pct }}%; background: {{ $barColour }}; height: 100%; transition: width 0.4s ease;"></div>
                    </div>
                    <div class="mt-1" style="font-size: 0.85rem; color: var(--hr-text-light);">
                        <span style="color: var(--hr-text-white);">{{ $iskFormat($totalPaid) }}</span>
                        <span style="color: var(--hr-text-muted);"> / </span>
                        <span style="color: var(--hr-text-white);">{{ $iskFormat($totalOwed) }} ISK</span>
                        <span style="color: var(--hr-text-muted); margin: 0 6px;">|</span>
                        <strong style="color: {{ $barColour }};">{{ number_format($pct, 1) }}% compliance</strong>
                        @if($periodsOverdue !== null)
                            <span style="color: var(--hr-text-muted); margin: 0 6px;">|</span>
                            <span>{{ $periodsOverdue }} period{{ $periodsOverdue === 1 ? '' : 's' }} overdue</span>
                        @endif
                    </div>
                @else
                    <p class="text-muted mb-0" style="color: var(--hr-text-muted) !important; font-size: 0.9rem;">
                        <i class="fas fa-info-circle"></i> No tax data available
                    </p>
                @endif
            </div>

        @endif
    </div>
</div>
