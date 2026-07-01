{{-- Notes Panel - Reusable for applications and members --}}
<div class="card card-dark mb-3">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-sticky-note"></i> {{ trans('hr-manager::notes.notes') }}
        </h3>
        <div class="card-tools">
            <button class="btn btn-sm btn-hr-primary btn-icon" data-toggle="modal" data-target="#addNoteModal">
                <i class="fas fa-plus"></i> {{ trans('hr-manager::notes.add_note') }}
            </button>
        </div>
    </div>
    <div class="card-body">
        @forelse($notes as $note)
            <div class="note-item {{ $note->is_private ? 'note-private' : 'note-public' }}">
                <div class="note-meta">
                    <strong>{{ ($userNames ?? [])[$note->author_id] ?? ('User #' . $note->author_id) }}</strong>
                    @if(in_array((int) $note->author_id, $noteAuthorAdmins ?? [], true))
                        <span class="badge ml-1" style="background: var(--hr-warning, #ffc107); color: #1a1a1a; font-weight: 600;" title="{{ trans('hr-manager::notes.admin_title') }}">{{ trans('hr-manager::notes.admin_badge') }}</span>
                    @endif
                    <span class="badge badge-hr {{ $note->is_private ? 'badge-private' : 'badge-public' }} ml-1">
                        {{ $note->is_private ? trans('hr-manager::notes.private') : trans('hr-manager::notes.public') }}
                    </span>
                    <span class="float-right">@hrDate($note->created_at)</span>
                </div>
                <div class="note-content">{{ $note->content }}</div>
                @if($note->author_id === auth()->user()->id)
                    <div class="mt-2">
                        <form method="POST" action="{{ route('hr-manager.notes.destroy', $note->id) }}" class="d-inline"
                              onsubmit="return confirm(@js(trans('hr-manager::notes.confirm_delete')))">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger btn-icon">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </div>
                @endif
            </div>
        @empty
            <p class="text-muted text-center">{{ trans('hr-manager::notes.no_notes') }}</p>
        @endforelse
    </div>
</div>

{{-- Add Note Modal --}}
{{--
  Hoisted via @push('hr-modals') so it renders OUTSIDE the .hr-manager-wrapper
  in the show pages. Modals inside the wrapper inherit its dark-on-dark cascade
  (white-text-on-dark-card styles applied to modal labels), which makes the
  form unreadable. Each show page emits @stack('hr-modals') after the wrapper
  closes — see plugin_visual_design_system memory guardrail.
--}}
@push('hr-modals')
@include('hr-manager::partials._hr_modal_button_styles')
<div class="modal fade" id="addNoteModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" action="{{ route('hr-manager.notes.store') }}">
                @csrf
                <input type="hidden" name="noteable_type" value="{{ $noteableType }}">
                <input type="hidden" name="noteable_id" value="{{ $noteableId }}">
                <div class="modal-header">
                    <h5 class="modal-title">{{ trans('hr-manager::notes.add_note') }}</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>{{ trans('hr-manager::notes.note_content') }}</label>
                        <textarea name="content" class="form-control" rows="4" required maxlength="5000"></textarea>
                    </div>
                    @php $privateNotesEnabled = (bool) \HrManager\Models\Setting::getValue('enable_private_notes', config('hr-manager.features.enable_private_notes', true)); @endphp
                    @if($privateNotesEnabled)
                    <div class="form-check">
                        <input type="checkbox" name="is_private" value="1" class="form-check-input" id="notePrivate">
                        <label class="form-check-label" for="notePrivate">
                            {{ trans('hr-manager::notes.make_private') }}
                        </label>
                    </div>
                    @endif
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-hr-secondary" data-dismiss="modal">{{ trans('hr-manager::settings.cancel') }}</button>
                    <button type="submit" class="btn btn-hr-primary btn-icon">
                        <i class="fas fa-save"></i> {{ trans('hr-manager::notes.save_note') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endpush
