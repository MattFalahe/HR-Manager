{{-- Status History Timeline --}}
<div class="card card-dark mb-3">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-history"></i> {{ trans('hr-manager::applications.status_history') }}
        </h3>
    </div>
    <div class="card-body">
        @php $userNames = $userNames ?? []; @endphp
        @if($history->isEmpty())
            <p class="text-muted text-center">{{ trans('hr-manager::applications.no_status_history') }}</p>
        @else
            <div class="status-timeline">
                @foreach($history as $entry)
                    <div class="timeline-item" @if($entry->isVoided()) style="opacity: 0.6;" @endif>
                        <div class="d-flex justify-content-between">
                            <div @if($entry->isVoided()) style="text-decoration: line-through;" @endif>
                                @if($entry->old_status)
                                    <span class="badge badge-hr badge-{{ str_replace('_', '-', $entry->old_status) }}">
                                        {{ ucfirst(str_replace('_', ' ', $entry->old_status)) }}
                                    </span>
                                    <i class="fas fa-arrow-right mx-1" style="color: var(--hr-text-muted);"></i>
                                @endif
                                <span class="badge badge-hr {{ $entry->new_status_badge_class }}">
                                    {{ $entry->new_status_label }}
                                </span>
                            </div>
                            <div class="timeline-date">@hrDate($entry->created_at)</div>
                        </div>
                        @if($entry->comment)
                            <div class="mt-1" style="color: var(--hr-text-light); font-style: italic;">
                                "{{ $entry->comment }}"
                            </div>
                        @endif
                        <div class="mt-1" style="color: var(--hr-text-muted); font-size: 0.8rem;">
                            {{ trans('hr-manager::applications.by_actor', ['name' => $userNames[$entry->changed_by] ?? ('User #' . $entry->changed_by)]) }}
                        </div>
                        @if($entry->isVoided())
                            {{-- Reverted as a mistake: kept here for the internal record,
                                 hidden from the applicant's tracking page. --}}
                            <div class="mt-1" style="color: var(--hr-warning, #ffc107); font-size: 0.8rem;">
                                <i class="fas fa-undo"></i>
                                {{ trans('hr-manager::applications.reverted_by', ['name' => $userNames[$entry->voided_by] ?? ('User #' . $entry->voided_by)]) }}
                                <span style="color: var(--hr-text-muted);">&middot; @hrDate($entry->voided_at)</span>
                                @if($entry->void_reason)
                                    <div style="color: var(--hr-text-light); font-style: italic;">"{{ $entry->void_reason }}"</div>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
