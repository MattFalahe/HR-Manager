@extends('web::layouts.grids.12')

@section('title', $landing ? trans('hr-manager::landings.edit_landing') : trans('hr-manager::landings.create_landing'))
@section('page_header', $landing ? trans('hr-manager::landings.edit_landing') : trans('hr-manager::landings.create_landing'))

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/hr-manager/css/hr-manager.css') }}?v=1.0.0">
@endpush

@section('full')
<div class="hr-manager-wrapper">

    {{-- Note: session('success'), session('error') AND the $errors bag
         are already rendered by SeAT's web::includes.notifications
         partial which app.blade.php @includes for us. Don't reproduce
         them here — that's what was duplicating the "Error / Please
         fix" pair on the hero upload failure screenshot. --}}

    @if($landing && $landing->is_published)
        <div class="alert alert-info">
            <i class="fas fa-globe"></i> {{ trans('hr-manager::landings.public_url') }}:
            <a href="{{ $landing->public_url }}" target="_blank">{{ url($landing->public_url) }}</a>
        </div>
    @endif

    <form method="POST"
          action="{{ $landing ? route('hr-manager.landings.update', $landing->id) : route('hr-manager.landings.store') }}"
          enctype="multipart/form-data">
        @csrf
        @if($landing) @method('PUT') @endif

        {{-- Details --}}
        <div class="card card-dark mb-3">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-info-circle"></i> Details</h3></div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>{{ trans('hr-manager::landings.corporation') }} <span class="text-danger">*</span></label>
                            <select name="corporation_id" class="form-control" required>
                                <option value="">--</option>
                                @foreach($corporations as $corp)
                                    <option value="{{ $corp->corporation_id }}"
                                        {{ (int) old('corporation_id', $landing->corporation_id ?? 0) === (int) $corp->corporation_id ? 'selected' : '' }}>
                                        [{{ $corp->ticker ?? '?' }}] {{ $corp->name }}
                                    </option>
                                @endforeach
                            </select>
                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::landings.corporation_help') }}</small>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="form-group">
                            <label>{{ trans('hr-manager::landings.title') }} <span class="text-danger">*</span></label>
                            <input type="text" name="title" class="form-control" required maxlength="191"
                                   value="{{ old('title', $landing->title ?? '') }}">
                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::landings.title_help') }}</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>{{ trans('hr-manager::landings.slug') }}</label>
                            <input type="text" name="slug" class="form-control" maxlength="96"
                                   value="{{ old('slug', $landing->slug ?? '') }}" pattern="[a-z0-9\-]+">
                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::landings.slug_help') }}</small>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label>{{ trans('hr-manager::landings.headline') }}</label>
                    @include('hr-manager::landings.partials.markdown-toolbar', ['target' => 'headline'])
                    <textarea id="headline" name="headline" class="form-control hr-md-editor" rows="4"
                              maxlength="8000">{{ old('headline', $landing->headline ?? '') }}</textarea>
                    <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::landings.headline_help') }}</small>
                </div>
                <div class="form-group">
                    <label>{{ trans('hr-manager::landings.body_markdown') }}</label>
                    @include('hr-manager::landings.partials.markdown-toolbar', ['target' => 'body_markdown'])
                    <textarea id="body_markdown" name="body_markdown" class="form-control hr-md-editor" rows="10">{{ old('body_markdown', $landing->body_markdown ?? '') }}</textarea>
                    <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::landings.body_help') }}</small>
                </div>
                <div class="form-check">
                    <input type="checkbox" name="is_published" value="1" class="form-check-input" id="isPublished"
                           {{ old('is_published', $landing->is_published ?? false) ? 'checked' : '' }}>
                    <label class="form-check-label" for="isPublished">{{ trans('hr-manager::landings.is_published') }}</label>
                </div>
            </div>
        </div>

        {{-- Form template + visual template + theme --}}
        <div class="card card-dark mb-3">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-palette"></i> Form + Visual</h3></div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>{{ trans('hr-manager::landings.template_form') }}</label>
                            <select name="default_template_id" class="form-control">
                                <option value="">--</option>
                                @foreach($templates as $tpl)
                                    <option value="{{ $tpl->id }}"
                                        {{ (int) old('default_template_id', $landing->default_template_id ?? 0) === (int) $tpl->id ? 'selected' : '' }}>
                                        {{ $tpl->name }}
                                    </option>
                                @endforeach
                            </select>
                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::landings.template_form_help') }}</small>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>{{ trans('hr-manager::landings.page_template') }}</label>
                            <select name="template_key" class="form-control">
                                @foreach($allTemplates as $tk)
                                    <option value="{{ $tk }}"
                                        {{ old('template_key', $landing->template_key ?? 'classic') === $tk ? 'selected' : '' }}>
                                        {{ trans('hr-manager::landings.template_' . $tk) }}
                                    </option>
                                @endforeach
                            </select>
                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::landings.page_template_help') }}</small>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>{{ trans('hr-manager::landings.theme_primary_color') }}</label>
                            <input type="color" name="theme_primary_color" class="form-control"
                                   value="{{ old('theme_primary_color', $landing->theme_json['primary'] ?? '#667eea') }}">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>{{ trans('hr-manager::landings.theme_accent_color') }}</label>
                            <input type="color" name="theme_accent_color" class="form-control"
                                   value="{{ old('theme_accent_color', $landing->theme_json['accent'] ?? '#764ba2') }}">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>{{ trans('hr-manager::landings.hero_image') }}</label>
                            <input type="file" name="hero_image" class="form-control-file" accept="image/jpeg,image/png,image/webp">
                            <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::landings.hero_image_help') }}</small>
                            @if($landing && $landing->hero_image_path)
                                <div class="mt-2">
                                    {{-- Use the public hero stream route instead of Storage::url so
                                         the preview works even when `storage:link` hasn't been
                                         run on the SeAT container. Falls back gracefully — the
                                         server returns 404 and the broken-image icon flags it. --}}
                                    <img src="{{ route('hr-manager.recruit.hero', ['ticker' => $landing->corp_ticker, 'slug' => $landing->slug]) }}?v={{ \Illuminate\Support\Carbon::parse($landing->updated_at ?? now())->timestamp }}"
                                         style="max-height: 80px; border-radius: 4px;" alt="Hero">
                                    <div class="form-check">
                                        <input type="checkbox" name="remove_hero_image" value="1" class="form-check-input" id="removeHero">
                                        <label class="form-check-label" for="removeHero">{{ trans('hr-manager::landings.remove_hero_image') }}</label>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Post-submission action --}}
        <div class="card card-dark mb-3">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-flag-checkered"></i> {{ trans('hr-manager::landings.post_submission') }}</h3></div>
            <div class="card-body">
                <div class="form-group">
                    <label>{{ trans('hr-manager::landings.post_submission_mode_label') }}</label>
                    <select name="post_submission_mode" class="form-control">
                        <option value="seat_connector" {{ old('post_submission_mode', $landing->post_submission_mode ?? 'seat_connector') === 'seat_connector' ? 'selected' : '' }}>{{ trans('hr-manager::landings.mode_seat_connector') }}</option>
                        <option value="discord_invite" {{ old('post_submission_mode', $landing->post_submission_mode ?? '') === 'discord_invite' ? 'selected' : '' }}>{{ trans('hr-manager::landings.mode_discord_invite') }}</option>
                        <option value="custom" {{ old('post_submission_mode', $landing->post_submission_mode ?? '') === 'custom' ? 'selected' : '' }}>{{ trans('hr-manager::landings.mode_custom') }}</option>
                        <option value="none" {{ old('post_submission_mode', $landing->post_submission_mode ?? '') === 'none' ? 'selected' : '' }}>{{ trans('hr-manager::landings.mode_none') }}</option>
                    </select>
                </div>

                {{-- Connector setup reminder. The Connector identity page is
                     permission-gated, so a brand-new applicant can only reach
                     the "link Discord" button if your SeAT Connector is set up
                     to grant them the Connector view permission. Easy to
                     miss, so we call it out right next to the mode picker. --}}
                <div class="alert" style="background: rgba(102,126,234,0.10); border: 1px solid rgba(102,126,234,0.30); color: var(--hr-text-light);">
                    <i class="fas fa-info-circle" style="color: var(--hr-primary-start, #667eea);"></i>
                    {{ trans('hr-manager::landings.connector_permission_reminder') }}
                </div>
                <div class="form-group">
                    <label>{{ trans('hr-manager::landings.discord_invite_url') }}</label>
                    <input type="url" name="discord_invite_url" class="form-control" maxlength="2048"
                           value="{{ old('discord_invite_url', $landing->discord_invite_url ?? '') }}"
                           placeholder="https://discord.gg/...">
                </div>
                <div class="form-group">
                    <label>{{ trans('hr-manager::landings.custom_confirmation') }}</label>
                    <textarea name="custom_confirmation_markdown" class="form-control" rows="4">{{ old('custom_confirmation_markdown', $landing->custom_confirmation_markdown ?? '') }}</textarea>
                </div>

                <hr style="border-color: rgba(255,255,255,0.08);">

                {{-- Always-visible Next steps notes. Renders regardless
                     of which post_submission_mode is picked above, so
                     directors can pair the Discord button (or Connector
                     link) with their own "what happens now" message. --}}
                <div class="form-group">
                    <label>{{ trans('hr-manager::landings.next_steps_markdown') }}</label>
                    <textarea name="next_steps_markdown" class="form-control" rows="5"
                              placeholder="{{ trans('hr-manager::landings.next_steps_placeholder') }}">{{ old('next_steps_markdown', $landing->next_steps_markdown ?? '') }}</textarea>
                    <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::landings.next_steps_help') }}</small>
                </div>
            </div>
        </div>

        {{-- Eligibility rules --}}
        <div class="card card-dark mb-3">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-filter"></i> {{ trans('hr-manager::landings.eligibility_heading') }}</h3></div>
            <div class="card-body">
                <p style="color: var(--hr-text-muted);">{{ trans('hr-manager::landings.eligibility_help') }}</p>
                <div class="row">
                    @foreach($availableRules as $key => $meta)
                        @php
                            $current = $landing->eligibility_rules_json[$key] ?? '';
                            if (is_array($current)) $current = implode(', ', $current);
                            if (is_bool($current)) $current = $current ? '1' : '';
                        @endphp
                        <div class="col-md-6 mb-3">
                            <label>{{ $meta['label'] }}</label>
                            @if($meta['type'] === 'bool')
                                <select name="eligibility_{{ $key }}" class="form-control">
                                    <option value="">(off)</option>
                                    <option value="1" {{ $current === '1' ? 'selected' : '' }}>Required</option>
                                </select>
                            @else
                                <input type="text" name="eligibility_{{ $key }}" class="form-control"
                                       value="{{ old('eligibility_' . $key, $current) }}">
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-hr-primary btn-lg btn-icon">
            <i class="fas fa-save"></i> Save
        </button>
        <a href="{{ route('hr-manager.landings.index') }}" class="btn btn-hr-secondary btn-lg">Cancel</a>
    </form>

</div>
@endsection
