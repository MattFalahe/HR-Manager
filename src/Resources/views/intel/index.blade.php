@extends('web::layouts.grids.12')

@section('title', trans('hr-manager::intel.title'))
@section('page_header', trans('hr-manager::intel.title'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/hr-manager/css/hr-manager.css') }}?v=1.0.8">
@endpush

@section('full')
<div class="hr-manager-wrapper">

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">{{ session('success') }}<button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show">{{ session('error') }}<button type="button" class="close" data-dismiss="alert"><span>&times;</span></button></div>
    @endif

    {{-- Page intro banner --}}
    <div class="page-intro-banner">
        <div class="page-intro-header">
            <div class="page-intro-body">
                <h4><i class="fas fa-info-circle"></i> {{ trans('hr-manager::intel.intro_what_label') }}</h4>
                <p>{!! trans('hr-manager::intel.intro_what_body') !!}</p>
            </div>
            <span class="page-intro-visibility">
                <i class="fas fa-user-shield"></i>
                @if($viewerTier === 'admin' || $viewerTier === 'director')
                    {{ trans('hr-manager::intel.intro_visibility_director') }}
                @else
                    {{ trans('hr-manager::intel.intro_visibility_recruiter') }}
                @endif
            </span>
        </div>
        <div class="page-intro-when">
            <h4><i class="fas fa-list-ul"></i> {{ trans('hr-manager::intel.intro_when_label') }}</h4>
            <ul>
                <li>{{ trans('hr-manager::intel.intro_when_1') }}</li>
                <li>{{ trans('hr-manager::intel.intro_when_2') }}</li>
                <li>{{ trans('hr-manager::intel.intro_when_3') }}</li>
            </ul>
        </div>
    </div>

    {{-- Add note form (director-only) --}}
    @can('hr-manager.director')
        <div class="card card-dark mb-3">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-plus-circle"></i>
                    {{ trans('hr-manager::intel.add_note') }}
                </h3>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('hr-manager.intel.store') }}">
                    @csrf
                    <div class="row">
                        <div class="col-md-5">
                            <div class="form-group">
                                <label>{{ trans('hr-manager::intel.input_label') }} <span class="text-danger">*</span></label>
                                <input type="text" name="input" class="form-control"
                                       placeholder="{{ trans('hr-manager::intel.input_placeholder') }}"
                                       value="{{ old('input') }}" required maxlength="64">
                                <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::intel.input_help') }}</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>{{ trans('hr-manager::intel.scope_label') }}</label>
                                <select name="scope_corporation_id" class="form-control">
                                    <option value="">{{ trans('hr-manager::intel.scope_global') }}</option>
                                    @foreach($corporations as $corp)
                                        <option value="{{ $corp->corporation_id }}" {{ (int) old('scope_corporation_id') === (int) $corp->corporation_id ? 'selected' : '' }}>
                                            @if(!empty($corp->ticker))[{{ $corp->ticker }}] @endif{{ $corp->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>{{ trans('hr-manager::intel.expires_label') }}</label>
                                <input type="date" name="expires_at" class="form-control" value="{{ old('expires_at') }}">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>{{ trans('hr-manager::intel.body_label') }} <span class="text-danger">*</span></label>
                        <textarea name="body" class="form-control" rows="3" maxlength="8000" required
                                  placeholder="{{ trans('hr-manager::intel.body_placeholder') }}">{{ old('body') }}</textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-8">
                            <div class="form-group mb-2">
                                <label>{{ trans('hr-manager::intel.tags_label') }}</label>
                                <input type="text" name="tags" class="form-control"
                                       placeholder="{{ trans('hr-manager::intel.tags_placeholder') }}"
                                       value="{{ old('tags') }}">
                                <small style="color: var(--hr-text-muted);">
                                    {{ trans('hr-manager::intel.tags_help') }}:
                                    @foreach($suggestedTags as $tag)
                                        <span class="badge mr-1" style="background: rgba(255,255,255,0.05); color: var(--hr-text-light); border: 1px solid var(--hr-border); cursor: pointer;"
                                              onclick="document.querySelector('[name=tags]').value = document.querySelector('[name=tags]').value ? document.querySelector('[name=tags]').value + ', {{ $tag }}' : '{{ $tag }}';">
                                            {{ $tag }}
                                        </span>
                                    @endforeach
                                </small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check mt-4">
                                <input type="checkbox" name="recruiter_visible" value="1" class="form-check-input" id="intelRecruiterVisible" {{ old('recruiter_visible') ? 'checked' : '' }}>
                                <label class="form-check-label" for="intelRecruiterVisible">
                                    <strong>{{ trans('hr-manager::intel.recruiter_visible_label') }}</strong>
                                </label>
                                <small class="d-block" style="color: var(--hr-text-muted);">
                                    {{ trans('hr-manager::intel.recruiter_visible_help') }}
                                </small>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-hr-primary btn-icon">
                        <i class="fas fa-save"></i> {{ trans('hr-manager::intel.save_note') }}
                    </button>
                </form>
            </div>
        </div>
    @endcan

    {{-- Filter + index --}}
    <div class="card card-dark">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-database"></i> {{ trans('hr-manager::intel.recent_notes') }}
            </h3>
            <div class="card-tools">
                <form method="GET" action="{{ route('hr-manager.intel.index') }}" class="form-inline">
                    <select name="tag" class="form-control form-control-sm mr-2" onchange="this.form.submit()">
                        <option value="">{{ trans('hr-manager::intel.all_tags') }}</option>
                        @foreach($suggestedTags as $tag)
                            <option value="{{ $tag }}" {{ request('tag') === $tag ? 'selected' : '' }}>{{ $tag }}</option>
                        @endforeach
                    </select>
                    <div class="input-group input-group-sm">
                        <input type="text" name="search" class="form-control" placeholder="{{ trans('hr-manager::intel.search_placeholder') }}" value="{{ request('search') }}">
                        <div class="input-group-append">
                            <button class="btn btn-hr-primary" type="submit"><i class="fas fa-search"></i></button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <div class="card-body p-0">
            @if($notes->isEmpty())
                <div class="p-4 text-center text-muted">
                    {{ trans('hr-manager::intel.empty') }}
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>{{ trans('hr-manager::intel.character_col') }}</th>
                                <th>{{ trans('hr-manager::intel.scope_col') }}</th>
                                <th>{{ trans('hr-manager::intel.body_col') }}</th>
                                <th>{{ trans('hr-manager::intel.tags_col') }}</th>
                                <th>{{ trans('hr-manager::intel.added_col') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($notes as $note)
                                <tr>
                                    <td>
                                        <a href="{{ route('hr-manager.intel.show', $note->character_id) }}" style="color: inherit; text-decoration: none;">
                                            <img src="https://images.evetech.net/characters/{{ $note->character_id }}/portrait?size=32" class="rounded-circle mr-2" width="32" height="32" alt="" onerror="this.style.display='none'">
                                            <strong>{{ $note->character_name ?? ('Character #' . $note->character_id) }}</strong>
                                            <small class="ml-2" style="color: var(--hr-text-muted);">#{{ $note->character_id }}</small>
                                        </a>
                                    </td>
                                    <td>
                                        @if($note->scope_corporation_id)
                                            <small>{{ $corpNames[$note->scope_corporation_id] ?? '#' . $note->scope_corporation_id }}</small>
                                        @else
                                            <span class="badge" style="background: rgba(102,126,234,0.18); color: var(--hr-text-light); border: 1px solid rgba(102,126,234,0.4);"><i class="fas fa-globe"></i> {{ trans('hr-manager::intel.scope_global') }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        <small style="color: var(--hr-text-light);">{{ Str::limit($note->body, 140) }}</small>
                                        @if($note->recruiter_visible)
                                            <span class="badge ml-1" title="{{ trans('hr-manager::intel.shared_with_recruiters') }}" style="background: rgba(40,167,69,0.2); color: var(--hr-text-light); border: 1px solid rgba(40,167,69,0.5);"><i class="fas fa-eye"></i></span>
                                        @endif
                                    </td>
                                    <td>
                                        @foreach(($note->tags ?? []) as $tag)
                                            <span class="badge mr-1" style="background: rgba(255,255,255,0.05); color: var(--hr-text-light); border: 1px solid var(--hr-border);">{{ $tag }}</span>
                                        @endforeach
                                    </td>
                                    <td>
                                        @hrDate($note->created_at)<br>
                                        <small style="color: var(--hr-text-muted);">{{ $note->author->name ?? 'User #' . $note->author_id }}</small>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
        @if($notes->hasPages())
            <div class="card-footer">{{ $notes->appends(request()->query())->links() }}</div>
        @endif
    </div>
</div>
@endsection
