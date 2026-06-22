@extends('web::layouts.grids.12')

@section('title', trans('hr-manager::templates.edit_template'))
@section('page_header', trans('hr-manager::templates.edit_template') . ': ' . $template->name)

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/hr-manager/css/hr-manager.css') }}?v=1.0.0">
@endpush

@section('full')
<div class="hr-manager-wrapper">

    @if($inUse)
        {{-- This template has applications, so its questions are locked: past
             applications snapshot their own Q&A, and changing the questions
             here would only muddy the record. The details below stay editable
             (rename / activate / deactivate / reassign). To change questions,
             duplicate it into a new editable copy. This Duplicate form sits
             OUTSIDE the main edit form on purpose (no nested forms). --}}
        <div class="alert alert-warning">
            <strong><i class="fas fa-lock"></i> {{ trans('hr-manager::templates.locked_heading', ['n' => $template->applications_count ?? $template->applications()->withTrashed()->count()]) }}</strong>
            <p class="mb-2 mt-2">{{ trans('hr-manager::templates.locked_body') }}</p>
            <form method="POST" action="{{ route('hr-manager.templates.duplicate', $template->id) }}" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-sm btn-hr-primary btn-icon">
                    <i class="fas fa-copy"></i> {{ trans('hr-manager::templates.duplicate_to_edit') }}
                </button>
            </form>
        </div>
    @endif

    <form method="POST" action="{{ route('hr-manager.templates.update', $template->id) }}" id="templateForm">
        @csrf
        @method('PUT')

        {{-- Template Details --}}
        <div class="card card-dark mb-3">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-info-circle"></i> {{ trans('hr-manager::templates.edit_template') }}</h3>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>{{ trans('hr-manager::templates.template_name') }} <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" required value="{{ old('name', $template->name) }}">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>{{ trans('hr-manager::templates.description') }}</label>
                            <input type="text" name="description" class="form-control" value="{{ old('description', $template->description) }}">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>{{ trans('hr-manager::templates.recruiting_corp') }}</label>
                            <select name="corporation_id" class="form-control">
                                <option value="">{{ trans('hr-manager::templates.recruiting_corp_global') }}</option>
                                @foreach(($corporations ?? []) as $corp)
                                    <option value="{{ $corp->corporation_id }}" {{ (int) old('corporation_id', $template->corporation_id) === (int) $corp->corporation_id ? 'selected' : '' }}>
                                        {{ $corp->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-check mt-4">
                            <input type="checkbox" name="is_active" value="1"
                                   class="form-check-input" id="isActive" {{ $template->is_active ? 'checked' : '' }}>
                            <label class="form-check-label" for="isActive">{{ trans('hr-manager::templates.is_active') }}</label>
                        </div>
                    </div>
                </div>
                <small class="form-text" style="color: var(--hr-text-muted);">{{ trans('hr-manager::templates.recruiting_corp_help') }}</small>
            </div>
        </div>

        {{-- Questions --}}
        <div class="card card-dark mb-3">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-list"></i> {{ trans('hr-manager::templates.questions') }}
                    @if($inUse)<span class="badge badge-secondary ml-2"><i class="fas fa-lock"></i> {{ trans('hr-manager::templates.locked_badge') }}</span>@endif
                </h3>
                @unless($inUse)
                    <div class="card-tools">
                        <button type="button" class="btn btn-sm btn-hr-primary btn-icon" id="addQuestion">
                            <i class="fas fa-plus"></i> {{ trans('hr-manager::templates.add_question') }}
                        </button>
                    </div>
                @endunless
            </div>
            <div class="card-body">
                @if($inUse)
                    {{-- Read-only: the questions exactly as applicants saw them. --}}
                    <ol style="color: var(--hr-text-light); padding-left: 1.2rem; margin-bottom: 0;">
                        @foreach($template->questions as $q)
                            <li style="margin-bottom: 0.6rem;">
                                <strong style="color: var(--hr-text-white);">{{ $q->question_text }}</strong>@if($q->is_required) <span class="text-danger">*</span>@endif
                                <span class="badge badge-secondary ml-1">{{ $q->question_type }}</span>
                                @if($q->help_text)<br><small style="color: var(--hr-text-muted);">{{ $q->help_text }}</small>@endif
                                @if(is_array($q->options) && count($q->options))<br><small style="color: var(--hr-text-muted);">{{ trans('hr-manager::templates.options') }}: {{ implode(', ', $q->options) }}</small>@endif
                            </li>
                        @endforeach
                    </ol>
                @else
                    <div id="questionsContainer">
                        {{-- Populated by JS --}}
                    </div>
                @endif
            </div>
        </div>

        <button type="submit" class="btn btn-hr-primary btn-lg btn-icon">
            <i class="fas fa-save"></i> {{ trans('hr-manager::templates.save_template') }}
        </button>
        <a href="{{ route('hr-manager.templates.index') }}" class="btn btn-hr-secondary btn-lg">{{ trans('hr-manager::templates.cancel') }}</a>
    </form>

</div>
@endsection

@push('javascript')
@if(!$inUse)
{{-- Resolve translation labels in a separate PHP block before passing
     the finished array to the JSON directive. Same fix as
     create.blade.php — inline array literals with nested trans calls
     confuse the directive's naive paren matcher and miscompile. Do NOT
     name the directives in this comment string; Blade's storePhpBlocks
     step scans comment text for those tokens and miscompiles when it
     finds them. --}}
@php
$hrLabels = [
    'remove'        => trans('hr-manager::templates.remove_question'),
    'type_text'     => trans('hr-manager::templates.type_text'),
    'type_textarea' => trans('hr-manager::templates.type_textarea'),
    'type_select'   => trans('hr-manager::templates.type_select'),
    'type_checkbox' => trans('hr-manager::templates.type_checkbox'),
    'type_radio'    => trans('hr-manager::templates.type_radio'),
    'type_number'   => trans('hr-manager::templates.type_number'),
    'type_url'      => trans('hr-manager::templates.type_url'),
    'question_text' => trans('hr-manager::templates.question_text'),
    'question_type' => trans('hr-manager::templates.question_type'),
    'required'      => trans('hr-manager::templates.required'),
    'help_text'     => trans('hr-manager::templates.help_text'),
    'placeholder'   => trans('hr-manager::templates.placeholder'),
    'options'       => trans('hr-manager::templates.options'),
];

// Build the existing-questions array as a plain variable so the @json
// below receives a SINGLE expression with no top-level commas. Passing
// `$template->questions->map(fn)` straight to @json breaks: the directive
// splits its argument on commas (to support @json($data, FLAGS, DEPTH)),
// so the commas inside the mapped array literal truncate the value and
// the compiled view becomes a PHP syntax error — crashing the edit page
// for any template that has questions. Same class of bug the hydrating
// screen hit; resolve to a variable first.
$hrExistingQuestions = $template->questions->map(function ($q) {
    return [
        'question_text' => $q->question_text,
        'question_type' => $q->question_type,
        'is_required'   => (bool) $q->is_required,
        'help_text'     => $q->help_text,
        'placeholder'   => $q->placeholder,
        'options'       => is_array($q->options) ? implode("\n", $q->options) : '',
    ];
})->values();
@endphp
<script>
// Translated labels via the JSON directive: safe JS string literals out of the
// lang values, avoiding the broken triple-brace json-encode pattern that
// double-escaped through Blade. (Directive names are kept out of this comment
// on purpose: Blade scans raw comment text and a bare directive token here
// miscompiles into invalid PHP.)
const HR_LABELS = @json($hrLabels);

// HTML-attribute-safe escape. The earlier `.replace(/"/g, '&quot;')` was
// insufficient — &, <, >, and ' all passed through unescaped, so a question
// text like '" onfocus="alert(1)" x="' could break out of the value attribute.
function escapeAttr(s) {
    return String(s || '')
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

let questionIndex = 0;

function addQuestion(data) {
    data = data || {};
    const idx = questionIndex++;
    const showOptions = ['select', 'checkbox', 'radio'].includes(data.question_type);
    const html = `
        <div class="card mb-3" style="background: var(--hr-dark-card); border: 1px solid var(--hr-border);" id="question-${idx}">
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <strong style="color: var(--hr-text-white);">Question #${idx + 1}</strong>
                    <button type="button" class="btn btn-sm btn-outline-danger btn-icon" onclick="removeQuestion(${idx})">
                        <i class="fas fa-trash"></i> ${escapeAttr(HR_LABELS.remove)}
                    </button>
                </div>
                <div class="row">
                    <div class="col-md-8">
                        <div class="form-group">
                            <label>${escapeAttr(HR_LABELS.question_text)} <span class="text-danger">*</span></label>
                            <input type="text" name="questions[${idx}][question_text]" class="form-control" required value="${escapeAttr(data.question_text)}">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>${escapeAttr(HR_LABELS.question_type)}</label>
                            <select name="questions[${idx}][question_type]" class="form-control" onchange="toggleOptions(${idx}, this.value)">
                                <option value="text" ${data.question_type === 'text' ? 'selected' : ''}>${escapeAttr(HR_LABELS.type_text)}</option>
                                <option value="textarea" ${data.question_type === 'textarea' ? 'selected' : ''}>${escapeAttr(HR_LABELS.type_textarea)}</option>
                                <option value="select" ${data.question_type === 'select' ? 'selected' : ''}>${escapeAttr(HR_LABELS.type_select)}</option>
                                <option value="checkbox" ${data.question_type === 'checkbox' ? 'selected' : ''}>${escapeAttr(HR_LABELS.type_checkbox)}</option>
                                <option value="radio" ${data.question_type === 'radio' ? 'selected' : ''}>${escapeAttr(HR_LABELS.type_radio)}</option>
                                <option value="number" ${data.question_type === 'number' ? 'selected' : ''}>${escapeAttr(HR_LABELS.type_number)}</option>
                                <option value="url" ${data.question_type === 'url' ? 'selected' : ''}>${escapeAttr(HR_LABELS.type_url)}</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>${escapeAttr(HR_LABELS.help_text)}</label>
                            <input type="text" name="questions[${idx}][help_text]" class="form-control" value="${escapeAttr(data.help_text)}">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>${escapeAttr(HR_LABELS.placeholder)}</label>
                            <input type="text" name="questions[${idx}][placeholder]" class="form-control" value="${escapeAttr(data.placeholder)}">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check mt-4">
                            <input type="checkbox" name="questions[${idx}][is_required]" value="1"
                                   class="form-check-input" id="req-${idx}" ${data.is_required ? 'checked' : ''}>
                            <label class="form-check-label" for="req-${idx}">${escapeAttr(HR_LABELS.required)}</label>
                        </div>
                    </div>
                </div>
                <div class="form-group" id="options-group-${idx}" style="display: ${showOptions ? 'block' : 'none'};">
                    <label>${escapeAttr(HR_LABELS.options)}</label>
                    <textarea name="questions[${idx}][options]" class="form-control" rows="3">${escapeAttr(data.options)}</textarea>
                </div>
            </div>
        </div>
    `;
    document.getElementById('questionsContainer').insertAdjacentHTML('beforeend', html);
}

function removeQuestion(idx) {
    const el = document.getElementById('question-' + idx);
    if (el) el.remove();
}

function toggleOptions(idx, type) {
    const el = document.getElementById('options-group-' + idx);
    if (el) {
        el.style.display = ['select', 'checkbox', 'radio'].includes(type) ? 'block' : 'none';
    }
}

document.getElementById('addQuestion').addEventListener('click', function() {
    addQuestion({});
});

// Load existing questions (pre-built in the PHP block above so the JSON
// directive receives a single comma-free expression).
const existingQuestions = @json($hrExistingQuestions);

existingQuestions.forEach(function(q) {
    addQuestion(q);
});
</script>
@endif
@endpush
