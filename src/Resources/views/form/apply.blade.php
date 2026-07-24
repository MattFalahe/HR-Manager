@extends('web::layouts.grids.12')

@section('title', trans('hr-manager::applications.apply_title'))
@section('page_header', trans('hr-manager::applications.apply_title'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/hr-manager/css/hr-manager.css') }}?v=1.0.0">
@endpush

@section('full')
<div class="hr-manager-wrapper">

    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show">
            {{ session('error') }}
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    @endif

    @if($hasPending)
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i> {{ trans('hr-manager::applications.apply_already_pending') }}
        </div>
    @else
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card card-dark">
                    <div class="card-header card-gradient-header">
                        <h3 class="card-title">
                            <i class="fas fa-file-alt"></i> {{ $template->name }}
                        </h3>
                    </div>
                    <div class="card-body">
                        @if($template->description)
                            <p class="mb-4" style="color: var(--hr-text-muted);">{{ $template->description }}</p>
                        @endif

                        <form method="POST" action="{{ route('hr-manager.apply.submit') }}">
                            @csrf
                            <input type="hidden" name="template_id" value="{{ $template->id }}">

                            @foreach($template->questions as $question)
                                <div class="form-group">
                                    <label>
                                        {{ $question->question_text }}
                                        @if($question->is_required)
                                            <span class="text-danger">*</span>
                                        @endif
                                    </label>

                                    @if($question->help_text)
                                        <small class="form-text" style="color: var(--hr-text-muted);">{{ $question->help_text }}</small>
                                    @endif

                                    @switch($question->question_type)
                                        @case('text')
                                            <input type="text" name="answers[{{ $question->id }}]"
                                                   class="form-control"
                                                   placeholder="{{ $question->placeholder }}"
                                                   {{ $question->is_required ? 'required' : '' }}>
                                            @break

                                        @case('textarea')
                                            <textarea name="answers[{{ $question->id }}]"
                                                      class="form-control" rows="4"
                                                      placeholder="{{ $question->placeholder }}"
                                                      {{ $question->is_required ? 'required' : '' }}></textarea>
                                            @break

                                        @case('number')
                                            <input type="number" name="answers[{{ $question->id }}]"
                                                   class="form-control"
                                                   placeholder="{{ $question->placeholder }}"
                                                   {{ $question->is_required ? 'required' : '' }}>
                                            @break

                                        @case('url')
                                            <input type="url" name="answers[{{ $question->id }}]"
                                                   class="form-control"
                                                   placeholder="{{ $question->placeholder ?? 'https://' }}"
                                                   {{ $question->is_required ? 'required' : '' }}>
                                            @break

                                        @case('select')
                                            <select name="answers[{{ $question->id }}]"
                                                    class="form-control"
                                                    {{ $question->is_required ? 'required' : '' }}>
                                                <option value="">-- Select --</option>
                                                @foreach($question->options ?? [] as $option)
                                                    <option value="{{ $option }}">{{ $option }}</option>
                                                @endforeach
                                            </select>
                                            @break

                                        @case('radio')
                                            @foreach($question->options ?? [] as $option)
                                                <div class="form-check">
                                                    <input type="radio" name="answers[{{ $question->id }}]"
                                                           value="{{ $option }}" class="form-check-input"
                                                           id="q{{ $question->id }}_{{ $loop->index }}"
                                                           {{ $question->is_required && $loop->first ? 'required' : '' }}>
                                                    <label class="form-check-label" for="q{{ $question->id }}_{{ $loop->index }}">
                                                        {{ $option }}
                                                    </label>
                                                </div>
                                            @endforeach
                                            @break

                                        @case('checkbox')
                                            @foreach($question->options ?? [] as $option)
                                                <div class="form-check">
                                                    <input type="checkbox" name="answers[{{ $question->id }}][]"
                                                           value="{{ $option }}" class="form-check-input"
                                                           id="q{{ $question->id }}_{{ $loop->index }}">
                                                    <label class="form-check-label" for="q{{ $question->id }}_{{ $loop->index }}">
                                                        {{ $option }}
                                                    </label>
                                                </div>
                                            @endforeach
                                            @break
                                    @endswitch
                                </div>
                            @endforeach

                            <button type="submit" class="btn btn-hr-primary btn-lg btn-block btn-icon mt-4">
                                <i class="fas fa-paper-plane"></i> {{ trans('hr-manager::applications.apply_submit') }}
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endif

</div>
@endsection
