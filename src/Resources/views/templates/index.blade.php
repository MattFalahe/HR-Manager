@extends('web::layouts.grids.12')

@section('title', trans('hr-manager::templates.templates'))
@section('page_header', trans('hr-manager::templates.templates'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/hr-manager/css/hr-manager.css') }}?v=1.0.0">
@endpush

@section('full')
<div class="hr-manager-wrapper">

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('success') }}
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    @endif

    <div class="card card-dark">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-clipboard-list"></i> {{ trans('hr-manager::templates.templates') }}
            </h3>
            <div class="card-tools">
                <a href="{{ route('hr-manager.templates.create') }}" class="btn btn-sm btn-hr-primary btn-icon">
                    <i class="fas fa-plus"></i> {{ trans('hr-manager::templates.create_template') }}
                </a>
            </div>
        </div>
        <div class="card-body p-0">
            @if($templates->isEmpty())
                <div class="p-4 text-center text-muted">
                    {{ trans('hr-manager::templates.no_templates') }}
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>{{ trans('hr-manager::templates.template_name') }}</th>
                                <th>{{ trans('hr-manager::templates.recruiting_corp_column') }}</th>
                                <th>{{ trans('hr-manager::templates.questions') }}</th>
                                <th>{{ trans('hr-manager::templates.is_default') }}</th>
                                <th>{{ trans('hr-manager::templates.is_active') }}</th>
                                <th>{{ trans('hr-manager::applications.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($templates as $template)
                                <tr>
                                    <td>
                                        <strong>{{ $template->name }}</strong>
                                        @if($template->description)
                                            <br><small style="color: var(--hr-text-muted);">{{ Str::limit($template->description, 60) }}</small>
                                        @endif
                                    </td>
                                    <td>
                                        @if($template->corporation_id)
                                            {{ ($corporations[$template->corporation_id] ?? '#' . $template->corporation_id) }}
                                        @else
                                            <em style="color: var(--hr-text-muted);">{{ trans('hr-manager::templates.recruiting_corp_none') }}</em>
                                        @endif
                                    </td>
                                    <td>{{ $template->questions_count }}</td>
                                    <td>
                                        @if($template->is_default)
                                            <span class="badge badge-hr badge-accepted">{{ trans('hr-manager::templates.is_default') }}</span>
                                        @else
                                            <form method="POST" action="{{ route('hr-manager.templates.set-default', $template->id) }}" class="d-inline">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-hr-secondary btn-icon">
                                                    {{ trans('hr-manager::templates.set_as_default') }}
                                                </button>
                                            </form>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="badge badge-hr {{ $template->is_active ? 'badge-accepted' : 'badge-withdrawn' }}">
                                            {{ $template->is_active ? trans('hr-manager::templates.is_active') : trans('hr-manager::settings.webhook_disabled') }}
                                        </span>
                                    </td>
                                    <td>
                                        @php $tplInUse = ($template->applications_count ?? 0) > 0; @endphp
                                        @if($tplInUse)
                                            <span class="badge badge-secondary mr-1" title="{{ trans('hr-manager::templates.in_use_title') }}">
                                                <i class="fas fa-lock"></i> {{ trans('hr-manager::templates.in_use_badge', ['n' => $template->applications_count]) }}
                                            </span>
                                        @endif
                                        <a href="{{ route('hr-manager.templates.edit', $template->id) }}"
                                           class="btn btn-sm btn-hr-secondary btn-icon"
                                           title="{{ $tplInUse ? trans('hr-manager::templates.edit_details_title') : trans('hr-manager::templates.edit_title') }}">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <form method="POST" action="{{ route('hr-manager.templates.duplicate', $template->id) }}" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-hr-secondary btn-icon" title="{{ trans('hr-manager::templates.duplicate_title') }}">
                                                <i class="fas fa-copy"></i>
                                            </button>
                                        </form>
                                        @can('hr-manager.admin')
                                            @if($tplInUse)
                                                <button type="button" class="btn btn-sm btn-outline-secondary btn-icon" disabled
                                                        title="{{ trans('hr-manager::templates.delete_blocked_title') }}">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            @else
                                                <form method="POST" action="{{ route('hr-manager.templates.destroy', $template->id) }}"
                                                      class="d-inline" onsubmit="return confirm(@js(trans('hr-manager::templates.confirm_delete')))">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-outline-danger btn-icon">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            @endif
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

</div>
@endsection
