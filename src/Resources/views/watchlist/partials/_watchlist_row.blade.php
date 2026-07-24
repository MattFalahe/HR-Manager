{{-- One watchlist entry row, shared by the flat (by-character) and grouped
     (by-account) list views. Expects: $entry, $corpNames, $listType --}}
<tr>
    <td>
        <img src="https://images.evetech.net/characters/{{ $entry->character_id }}/portrait?size=32"
             class="rounded-circle mr-2" width="32" height="32" alt=""
             onerror="this.style.display='none'">
        <a href="{{ route('hr-manager.watchlist.dossier', $entry->character_id) }}"
           style="color: var(--hr-text-white, #fff);" title="{{ trans('hr-manager::watchlist.dossier_view') }}">
            <strong>{{ $entry->display_name }}</strong>
        </a>
        <small class="ml-2" style="color: var(--hr-text-muted);">#{{ $entry->character_id }}</small>
    </td>
    <td>
        @if($entry->scope_corporation_id)
            {{ $corpNames[$entry->scope_corporation_id] ?? ('Corp #' . $entry->scope_corporation_id) }}
        @else
            <span class="badge" style="background: rgba(102,126,234,0.18); color: var(--hr-text-light); border: 1px solid rgba(102,126,234,0.4);">
                <i class="fas fa-globe"></i> {{ trans('hr-manager::watchlist.scope_global') }}
            </span>
        @endif
    </td>
    @if($listType === 'blacklist')
        <td>
            @php
                $sevClass = ['low' => 'badge-secondary', 'medium' => 'badge-warning', 'high' => 'badge-danger'][$entry->severity] ?? 'badge-secondary';
            @endphp
            <span class="badge {{ $sevClass }}">
                @if($entry->severity === 'high')<i class="fas fa-exclamation-triangle"></i>@endif
                {{ strtoupper($entry->severity) }}
            </span>
        </td>
    @endif
    <td>
        @if($entry->reason)
            <span title="{{ $entry->reason }}">{{ Str::limit($entry->reason, 80) }}</span>
        @else
            <span style="color: var(--hr-text-muted);">-</span>
        @endif
        <a href="{{ route('hr-manager.watchlist.dossier', $entry->character_id) }}" class="d-inline-block mt-1" style="white-space: nowrap; font-size: 0.8rem;" title="{{ trans('hr-manager::watchlist.dossier_view') }}">
            <i class="fas fa-external-link-alt"></i> {{ trans('hr-manager::watchlist.dossier_view') }}
        </a>
    </td>
    <td>
        @hrDate($entry->added_at)<br>
        <small style="color: var(--hr-text-muted);">
            @if($entry->is_system_added)<i class="fas fa-robot" title="{{ trans('hr-manager::watchlist.system_actor') }}"></i> @endif{{ $entry->added_by_label }}
        </small>
    </td>
    <td>
        @if($entry->expires_at)
            @hrDateShort($entry->expires_at)
        @else
            <span style="color: var(--hr-text-muted);">{{ trans('hr-manager::watchlist.never') }}</span>
        @endif
    </td>
    @can('hr-manager.director')
        <td>
            <form method="POST" action="{{ route('hr-manager.watchlist.destroy', $entry->id) }}"
                  data-account-count="{{ $accountActiveCounts[$entry->character_id] ?? 1 }}"
                  onsubmit="return hrWatchlistClear(this);">
                @csrf
                @method('DELETE')
                <input type="hidden" name="cleared_reason" value="">
                <input type="hidden" name="clear_scope" value="single">
                <button class="btn btn-sm btn-outline-danger btn-icon" title="{{ trans('hr-manager::watchlist.confirm_remove') }}">
                    <i class="fas fa-eraser"></i>
                </button>
            </form>
        </td>
    @endcan
</tr>
