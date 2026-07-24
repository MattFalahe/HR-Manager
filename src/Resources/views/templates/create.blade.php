@extends('web::layouts.grids.12')

@section('title', trans('hr-manager::templates.create_template'))
@section('page_header', trans('hr-manager::templates.create_template'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/hr-manager/css/hr-manager.css') }}?v=1.0.0">
@endpush

@section('full')
<div class="hr-manager-wrapper">

    <form method="POST" action="{{ route('hr-manager.templates.store') }}" id="templateForm">
        @csrf

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
                            <input type="text" name="name" class="form-control" required value="{{ old('name') }}">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>{{ trans('hr-manager::templates.description') }}</label>
                            <input type="text" name="description" class="form-control" value="{{ old('description') }}">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>{{ trans('hr-manager::templates.recruiting_corp') }}</label>
                            <select name="corporation_id" class="form-control">
                                <option value="">{{ trans('hr-manager::templates.recruiting_corp_global') }}</option>
                                @foreach(($corporations ?? []) as $corp)
                                    <option value="{{ $corp->corporation_id }}" {{ (int) old('corporation_id') === (int) $corp->corporation_id ? 'selected' : '' }}>
                                        {{ $corp->name }}
                                    </option>
                                @endforeach
                            </select>
                            <small class="form-text" style="color: var(--hr-text-muted);">{{ trans('hr-manager::templates.recruiting_corp_help') }}</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Questions --}}
        <div class="card card-dark mb-3">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-list"></i> {{ trans('hr-manager::templates.questions') }}</h3>
                <div class="card-tools">
                    <button type="button" class="btn btn-sm btn-hr-primary btn-icon" id="addQuestion">
                        <i class="fas fa-plus"></i> {{ trans('hr-manager::templates.add_question') }}
                    </button>
                </div>
            </div>
            <div class="card-body" id="questionsContainer">
                {{-- Questions will be added dynamically --}}
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
{{-- Resolve translation labels in a separate PHP block, then hand the
     finished array to the JSON directive. Inline array literals with
     nested trans calls confuse the directive's naive paren matcher and
     emit a malformed compiled view; resolving via a variable avoids the
     parser-walk problem. Do NOT name the directives inside this comment
     string — Blade's storePhpBlocks step scans comment text for those
     tokens and miscompiles when it finds them. --}}
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
@endphp
<script>
// Translated labels resolved server-side and JSON-encoded — safe to drop into
// JS template literals without further escaping (json_encode handles quotes,
// backslashes, and unicode).
const HR_LABELS = @json($hrLabels);

// HTML-attribute-safe escape for dynamic values interpolated into HTML strings.
// Without this, manual `replace(/"/g, '&quot;')` left &, <, >, ' unhandled —
// any of which let user-controlled question text break out of an attribute.
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
    const required = data.is_required !== false;
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
                                   class="form-check-input" id="req-${idx}" ${required ? 'checked' : ''}>
                            <label class="form-check-label" for="req-${idx}">${escapeAttr(HR_LABELS.required)}</label>
                        </div>
                    </div>
                </div>
                <div class="form-group" id="options-group-${idx}" style="display: ${showOptions ? 'block' : 'none'};">
                    <label>${escapeAttr(HR_LABELS.options)}</label>
                    <textarea name="questions[${idx}][options]" class="form-control" rows="3"
                              placeholder="Option 1&#10;Option 2&#10;Option 3">${escapeAttr(data.options)}</textarea>
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

// Start with one question
addQuestion({question_type: 'textarea'});
</script>
@endpush
