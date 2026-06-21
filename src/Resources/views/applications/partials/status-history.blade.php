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
                    <div class="timeline-item">
                        <div class="d-flex justify-content-between">
                            <div>
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
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
