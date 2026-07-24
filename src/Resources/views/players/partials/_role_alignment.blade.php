{{-- One player's in-game title/role alignment: each character vs the account
     main, with missing (main has, this lacks) and extra (this has, main lacks)
     titles + roles called out. Shared by the player profile and the Corp Health
     Alignment tab. Expects: $alignment (RoleAlignmentService::forPlayer result). --}}
@if(empty($alignment['available']))
    <p class="text-muted mb-0"><i class="fas fa-info-circle"></i> {{ trans('hr-manager::corp-health.align_no_data') }}</p>
@else
    <div class="mb-2">
        @if($alignment['aligned'])
            <span class="badge badge-success"><i class="fas fa-check-circle"></i> {{ trans('hr-manager::corp-health.align_verdict_aligned') }}</span>
            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.align_all_match') }}</small>
        @else
            <span class="badge" style="background: rgba(255,193,7,0.25); color:#ffe08a;"><i class="fas fa-exclamation-triangle"></i> {{ trans('hr-manager::corp-health.align_verdict_misaligned') }}</span>
        @endif
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0" style="color: var(--hr-text-light);">
            <tbody>
                @foreach($alignment['characters'] as $c)
                    <tr>
                        <td style="white-space: nowrap; width: 1%;">
                            <img src="https://images.evetech.net/characters/{{ $c['character_id'] }}/portrait?size=32" style="width:22px;height:22px;border-radius:50%;vertical-align:middle;margin-right:6px;" alt="">
                            <strong>{{ $c['name'] }}</strong>
                            @if($c['is_reference'])
                                <span class="badge badge-primary ml-1" style="font-size:0.6rem;">{{ trans('hr-manager::corp-health.align_main') }}</span>
                            @endif
                        </td>
                        <td>
                            @if($c['is_reference'])
                                <span style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.align_reference') }}</span>
                            @elseif($c['aligned'])
                                <span style="color: var(--hr-success, #28a745);"><i class="fas fa-check"></i> {{ trans('hr-manager::corp-health.align_matches') }}</span>
                            @else
                                @if(!empty($c['missing_titles']) || !empty($c['missing_roles']))
                                    <div>
                                        <span class="badge badge-danger">{{ trans('hr-manager::corp-health.align_missing') }}</span>
                                        @foreach(array_merge($c['missing_titles'], $c['missing_roles']) as $m)
                                            <span class="badge" style="background: rgba(220,53,69,0.18); color:#ff9aa5; border:1px solid rgba(220,53,69,0.5);">{{ $m }}</span>
                                        @endforeach
                                    </div>
                                @endif
                                @if(!empty($c['extra_titles']) || !empty($c['extra_roles']))
                                    <div class="mt-1">
                                        <span class="badge badge-warning">{{ trans('hr-manager::corp-health.align_extra') }}</span>
                                        @foreach(array_merge($c['extra_titles'], $c['extra_roles']) as $x)
                                            <span class="badge" style="background: rgba(255,193,7,0.16); color:#ffd968; border:1px solid rgba(255,193,7,0.45);">{{ $x }}</span>
                                        @endforeach
                                    </div>
                                @endif
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
