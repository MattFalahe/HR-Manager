<?php

namespace HrManager\Http\Controllers;

use HrManager\Http\Controllers\Traits\ScopesCorporationAccess;
use HrManager\Models\FormTemplate;
use HrManager\Models\RecruitmentLanding;
use HrManager\Services\EligibilityService;
use HrManager\Services\RecruitmentService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Seat\Eveapi\Models\Corporation\CorporationInfo;

/**
 * Admin CRUD for recruitment landing pages (director+).
 * Publish toggle, theme tweaks, eligibility rules editor, analytics dashboard.
 */
class RecruitmentLandingController extends Controller
{
    use ScopesCorporationAccess;

    public function index()
    {
        $query = RecruitmentLanding::orderBy('is_published', 'desc')
            ->orderBy('corporation_id')
            ->orderBy('slug')
            ->with('defaultTemplate');
        $this->scopeToAllowedCorps($query);

        $landings = $query->get();

        // Decorate each row with a "needs_template_binding" flag so the
        // view can render an inline warning + the page-level banner can
        // count them in one pass. Condition: published AND (no template
        // bound OR bound template is inactive). Applicants on these
        // landings hit the no-template error page after authing.
        $needsTemplateCount = 0;
        foreach ($landings as $landing) {
            $unbound = !$landing->default_template_id;
            $inactive = $landing->defaultTemplate && !$landing->defaultTemplate->is_active;
            $needsTemplate = $landing->is_published && ($unbound || $inactive);
            $landing->setAttribute('needs_template_binding', $needsTemplate);
            if ($needsTemplate) {
                $needsTemplateCount++;
            }
        }

        $corpIds = $landings->pluck('corporation_id')->unique()->values();
        $corpNames = $corpIds->isNotEmpty()
            ? CorporationInfo::whereIn('corporation_id', $corpIds)->pluck('name', 'corporation_id')
            : collect();

        return view('hr-manager::landings.index', compact('landings', 'corpNames', 'needsTemplateCount'));
    }

    public function create()
    {
        $corporations = $this->corporationsForPicker();
        $templates = FormTemplate::active()->orderBy('name')->get();
        $availableRules = EligibilityService::availableRules();

        return view('hr-manager::landings.form', [
            'landing'        => null,
            'corporations'   => $corporations,
            'templates'      => $templates,
            'availableRules' => $availableRules,
            'allTemplates'   => RecruitmentLanding::ALL_TEMPLATES,
            'allModes'       => [
                RecruitmentLanding::MODE_DISCORD_INVITE,
                RecruitmentLanding::MODE_SEAT_CONNECTOR,
                RecruitmentLanding::MODE_CUSTOM,
            ],
        ]);
    }

    public function store(Request $request, RecruitmentService $service)
    {
        if ($redirect = $this->preflightHeroUpload($request)) {
            return $redirect;
        }
        $data = $this->validateAndExtract($request);
        $this->assertCanAccessCorp($data['corporation_id']);

        $data['slug'] = $service->generateUniqueSlug($data['corporation_id'], $data['slug'] ?: $data['title']);
        $data['created_by'] = auth()->user()->id;

        if ($request->hasFile('hero_image')) {
            try {
                $data['hero_image_path'] = $service->storeHeroImage($request->file('hero_image'));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[HR Manager] hero upload failed (create): ' . $e->getMessage());
                return back()->withInput()
                    ->withErrors(['hero_image' => trans('hr-manager::landings.hero_upload_failed') . ' ' . $e->getMessage()]);
            }
        }

        $landing = RecruitmentLanding::create($data);

        return redirect()->route('hr-manager.landings.edit', $landing->id)
            ->with('success', trans('hr-manager::landings.created'));
    }

    public function edit(int $id)
    {
        $landing = RecruitmentLanding::findOrFail($id);
        $this->assertCanAccessCorp($landing->corporation_id);

        $corporations = $this->corporationsForPicker();
        $templates = FormTemplate::active()->where('corporation_id', $landing->corporation_id)->orderBy('name')->get();
        $availableRules = EligibilityService::availableRules();

        return view('hr-manager::landings.form', [
            'landing'        => $landing,
            'corporations'   => $corporations,
            'templates'      => $templates,
            'availableRules' => $availableRules,
            'allTemplates'   => RecruitmentLanding::ALL_TEMPLATES,
            'allModes'       => [
                RecruitmentLanding::MODE_DISCORD_INVITE,
                RecruitmentLanding::MODE_SEAT_CONNECTOR,
                RecruitmentLanding::MODE_CUSTOM,
            ],
        ]);
    }

    public function update(Request $request, int $id, RecruitmentService $service)
    {
        if ($redirect = $this->preflightHeroUpload($request)) {
            return $redirect;
        }
        $landing = RecruitmentLanding::findOrFail($id);
        $this->assertCanAccessCorp($landing->corporation_id);

        $data = $this->validateAndExtract($request, $landing);
        $this->assertCanAccessCorp($data['corporation_id']);

        if ($request->hasFile('hero_image')) {
            // Replace previous image. Store NEW first, then delete the
            // old — if the new upload fails we don't end up with a
            // landing pointing at a deleted file.
            try {
                $newPath = $service->storeHeroImage($request->file('hero_image'));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[HR Manager] hero upload failed (update): ' . $e->getMessage());
                return back()->withInput()
                    ->withErrors(['hero_image' => trans('hr-manager::landings.hero_upload_failed') . ' ' . $e->getMessage()]);
            }
            $service->deleteHeroImage($landing->hero_image_path);
            $data['hero_image_path'] = $newPath;
        }

        if ($request->boolean('remove_hero_image')) {
            $service->deleteHeroImage($landing->hero_image_path);
            $data['hero_image_path'] = null;
        }

        $landing->update($data);

        return redirect()->route('hr-manager.landings.edit', $landing->id)
            ->with('success', trans('hr-manager::landings.updated'));
    }

    public function destroy(int $id, RecruitmentService $service)
    {
        $landing = RecruitmentLanding::findOrFail($id);
        $this->assertCanAccessCorp($landing->corporation_id);

        $service->deleteHeroImage($landing->hero_image_path);
        $landing->delete();

        return redirect()->route('hr-manager.landings.index')
            ->with('success', trans('hr-manager::landings.deleted'));
    }

    public function togglePublish(int $id)
    {
        $landing = RecruitmentLanding::findOrFail($id);
        $this->assertCanAccessCorp($landing->corporation_id);

        $turningOn = !$landing->is_published;

        // Block publish when there's no usable form template bound.
        // Without this, an admin can publish, share the URL, and the
        // first applicant who clicks Apply hits the no-template error.
        // Unpublishing is always allowed (no precondition).
        if ($turningOn) {
            $template = $landing->defaultTemplate;
            if (!$template || !$template->is_active) {
                return redirect()->back()->with(
                    'error',
                    trans('hr-manager::landings.cannot_publish_no_template')
                );
            }
        }

        $landing->update(['is_published' => $turningOn]);

        return redirect()->back()->with('success', $landing->is_published
            ? trans('hr-manager::landings.published')
            : trans('hr-manager::landings.unpublished'));
    }

    public function analytics(Request $request, int $id, RecruitmentService $service)
    {
        $landing = RecruitmentLanding::findOrFail($id);
        $this->assertCanAccessCorp($landing->corporation_id);

        $days = (int) $request->input('days', 30);
        $days = max(7, min(180, $days));

        $stats = $service->analytics($landing, $days);

        return view('hr-manager::landings.analytics', compact('landing', 'stats', 'days'));
    }

    // -----------------------------------------------------------------

    /**
     * Diagnose hero-image upload failures BEFORE Laravel's validator
     * short-circuits with the generic "failed to upload" message.
     *
     * Laravel's image|mimes:|max: rules call UploadedFile::isValid()
     * internally and emit `validation.uploaded` ("The hero image failed
     * to upload.") for any PHP-level failure (post_max_size,
     * upload_max_filesize, missing tmp dir, etc) — without ever
     * surfacing the actual UPLOAD_ERR_* code. That leaves operators
     * staring at a generic message with no log entry to dig into.
     *
     * This runs first, inspects the raw `_FILES` error code on the
     * incoming request, logs the concrete cause + the relevant
     * php.ini ceilings, and returns a redirect with a specific
     * operator-actionable message. Returns null if there's nothing
     * wrong (no file, or the file is valid) so the normal flow
     * continues.
     */
    private function preflightHeroUpload(Request $request)
    {
        // Sentinel: when PHP's post_max_size is exceeded for the WHOLE
        // request, $_FILES, $_POST and the form input are all empty
        // even though the operator submitted a file. The form's
        // multipart/form-data submission arrives empty + Laravel's
        // Request::all() returns []. Detect this via the
        // CONTENT_LENGTH header and the empty body.
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        $postMaxBytes  = $this->iniBytes(ini_get('post_max_size'));
        if ($contentLength > 0
            && $postMaxBytes > 0
            && $contentLength > $postMaxBytes
            && empty($_POST)
            && empty($_FILES)) {
            $cause = 'Request exceeded PHP post_max_size ('
                . ini_get('post_max_size') . '). Browser sent '
                . $this->humanBytes($contentLength) . ' but PHP rejected it before '
                . 'the form fields could be parsed.';
            Log::warning('[HR Manager] hero upload preflight rejected: ' . $cause, [
                'content_length'      => $contentLength,
                'post_max_size'       => ini_get('post_max_size'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
            ]);
            return back()->withErrors([
                'hero_image' => trans('hr-manager::landings.hero_upload_failed') . ' ' . $cause,
            ]);
        }

        if (!$request->hasFile('hero_image') && !isset($_FILES['hero_image'])) {
            return null;
        }

        // Read the raw $_FILES entry — Laravel's UploadedFile may
        // already be marked invalid, but the underlying error code
        // lives on the raw array.
        $raw = $_FILES['hero_image'] ?? null;
        $errorCode = is_array($raw) ? (int) ($raw['error'] ?? UPLOAD_ERR_OK) : UPLOAD_ERR_OK;
        $sizeBytes = is_array($raw) ? (int) ($raw['size'] ?? 0) : 0;
        $clientName = is_array($raw) ? (string) ($raw['name'] ?? '') : '';

        // Validator handles UPLOAD_ERR_OK + UPLOAD_ERR_NO_FILE cleanly.
        if ($errorCode === UPLOAD_ERR_OK || $errorCode === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $iniUploadMax = ini_get('upload_max_filesize');
        $iniPostMax   = ini_get('post_max_size');
        $iniTmpDir    = ini_get('upload_tmp_dir') ?: sys_get_temp_dir();

        $cause = match ($errorCode) {
            UPLOAD_ERR_INI_SIZE   => "Exceeds PHP upload_max_filesize ({$iniUploadMax})",
            UPLOAD_ERR_FORM_SIZE  => 'Exceeds the form MAX_FILE_SIZE attribute',
            UPLOAD_ERR_PARTIAL    => 'Only partially uploaded — network or proxy interrupted the request',
            UPLOAD_ERR_NO_TMP_DIR => "PHP upload tmp dir missing or unwritable ({$iniTmpDir})",
            UPLOAD_ERR_CANT_WRITE => 'PHP could not write the upload to disk (filesystem permissions)',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the upload',
            default               => "Unknown PHP upload error (code {$errorCode})",
        };

        Log::warning('[HR Manager] hero upload preflight rejected: ' . $cause, [
            'error_code'           => $errorCode,
            'size_bytes'           => $sizeBytes,
            'client_filename'      => $clientName,
            'upload_max_filesize'  => $iniUploadMax,
            'post_max_size'        => $iniPostMax,
            'upload_tmp_dir'       => $iniTmpDir,
            'plugin_limit_kb'      => 5120,
        ]);

        $hint = "{$cause}. File: " . ($clientName ?: 'unknown')
            . ', size: ' . $this->humanBytes($sizeBytes)
            . " (PHP upload_max_filesize={$iniUploadMax}, post_max_size={$iniPostMax}, "
            . 'plugin limit=5 MB).';

        return back()->withInput()
            ->withErrors(['hero_image' => trans('hr-manager::landings.hero_upload_failed') . ' ' . $hint]);
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = (int) floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);
        return round($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
    }

    /**
     * Parse a php.ini size string (e.g. "8M", "256K", "1G") to bytes.
     * Returns 0 when input is empty or unparseable.
     */
    private function iniBytes(?string $value): int
    {
        if ($value === null || $value === '') return 0;
        $value = trim($value);
        $last = strtolower($value[strlen($value) - 1]);
        $num = (int) $value;
        return match ($last) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => $num,
        };
    }

    private function validateAndExtract(Request $request, ?RecruitmentLanding $existing = null): array
    {
        $rules = [
            'corporation_id'                => 'required|integer',
            'slug'                          => 'nullable|alpha_dash|max:96',
            'title'                         => 'required|string|max:191',
            // Widened from 191 (VARCHAR ceiling) to 8000 in v1.0.1 — the
            // headline is now a Markdown editor that mirrors the body
            // field. Backed by a TEXT column (migration 2026_06_01_000011).
            'headline'                      => 'nullable|string|max:8000',
            // Note: hero_image rules are below. The 'uploaded' validation
            // message is overridden via $messages so operators see why
            // the upload failed at the PHP layer instead of the generic
            // "The hero image failed to upload."
            'body_markdown'                 => 'nullable|string',
            'template_key'                  => 'required|in:' . implode(',', RecruitmentLanding::ALL_TEMPLATES),
            'default_template_id'           => 'nullable|integer|exists:hr_manager_form_templates,id',
            'post_submission_mode'          => 'required|in:discord_invite,seat_connector,custom,none',
            'discord_invite_url'            => 'nullable|url|max:2048',
            'custom_confirmation_markdown'  => 'nullable|string',
            'next_steps_markdown'           => 'nullable|string|max:8000',
            'is_published'                  => 'nullable|boolean',
            // 5 MB plugin ceiling — gives operators who bumped PHP's
            // upload_max_filesize headroom without affecting installs
            // still on default 2M PHP (those fail at PHP layer with
            // the diagnostic from preflightHeroUpload).
            'hero_image'                    => 'nullable|image|mimes:jpeg,png,webp|max:5120',
            'remove_hero_image'             => 'nullable|boolean',
            'theme_primary_color'           => 'nullable|regex:/^#[0-9a-fA-F]{6}$/',
            'theme_accent_color'            => 'nullable|regex:/^#[0-9a-fA-F]{6}$/',
        ];

        // Eligibility rule inputs (one per available rule key)
        foreach (EligibilityService::availableRules() as $key => $meta) {
            $rules['eligibility_' . $key] = 'nullable|string|max:255';
        }

        // Replace the generic "The hero image failed to upload" with a
        // pointer at the diagnostic logs. preflightHeroUpload() catches
        // most cases before we get here; this is the safety net for
        // any path it didn't cover (e.g. an UploadedFile that ::isValid()
        // marks false without setting $_FILES[*]['error']).
        $messages = [
            'hero_image.uploaded' => trans('hr-manager::landings.hero_upload_failed')
                . ' Check the SeAT log (search "[HR Manager] hero upload") for the underlying PHP cause.',
        ];

        try {
            $request->validate($rules, $messages);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Emit a log entry whenever hero_image fails any rule so the
            // operator never gets a UI-only failure with no audit trail.
            $errors = $e->errors();
            if (isset($errors['hero_image'])) {
                Log::warning('[HR Manager] hero upload validation rejected', [
                    'reasons'       => $errors['hero_image'],
                    'has_file'      => $request->hasFile('hero_image'),
                    'file_size'     => $request->file('hero_image')?->getSize(),
                    'mime'          => $request->file('hero_image')?->getMimeType(),
                    'is_valid'      => $request->file('hero_image')?->isValid(),
                    'error_code'    => $request->file('hero_image')?->getError(),
                    'php_upload_max' => ini_get('upload_max_filesize'),
                    'php_post_max'   => ini_get('post_max_size'),
                ]);
            }
            throw $e;
        }

        // Block "is_published = true + no usable template" — the same
        // condition the no-template error page guards against, caught
        // earlier so the admin sees a field-level validation error on
        // the form they just submitted instead of hearing about it from
        // a confused applicant. "Usable" means: template exists, is
        // active, and is scoped to the same corp as the landing.
        if ($request->boolean('is_published')) {
            $tplId = $request->input('default_template_id');
            if (!$tplId) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'default_template_id' => trans('hr-manager::landings.cannot_publish_no_template'),
                ]);
            }
            $tpl = FormTemplate::find($tplId);
            if (!$tpl || !$tpl->is_active || (int) $tpl->corporation_id !== (int) $request->corporation_id) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'default_template_id' => trans('hr-manager::landings.template_not_usable'),
                ]);
            }
        }

        // Build eligibility rules JSON from posted hr_eligibility_* fields
        $eligibility = [];
        foreach (EligibilityService::availableRules() as $key => $meta) {
            $raw = $request->input('eligibility_' . $key);
            if ($raw === null || $raw === '') {
                continue;
            }
            $eligibility[$key] = match ($meta['type']) {
                'float'    => (float) $raw,
                'int'      => (int) $raw,
                'bool'     => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
                'int_list' => array_values(array_filter(array_map('intval', array_map('trim', explode(',', $raw))))),
                default    => $raw,
            };
        }

        $theme = [
            'primary' => $request->input('theme_primary_color', '#667eea'),
            'accent'  => $request->input('theme_accent_color', '#764ba2'),
        ];

        $data = [
            'corporation_id'                => (int) $request->corporation_id,
            'slug'                          => $request->slug ?: ($existing->slug ?? \Illuminate\Support\Str::slug($request->title)),
            'title'                         => $request->title,
            'headline'                      => $request->headline,
            'body_markdown'                 => $request->body_markdown,
            'template_key'                  => $request->template_key,
            'theme_json'                    => $theme,
            'default_template_id'           => $request->default_template_id,
            'post_submission_mode'          => $request->post_submission_mode,
            'discord_invite_url'            => $request->discord_invite_url,
            'custom_confirmation_markdown'  => $request->custom_confirmation_markdown,
            'next_steps_markdown'           => $request->next_steps_markdown,
            'eligibility_rules_json'        => $eligibility,
            'is_published'                  => $request->boolean('is_published'),
        ];

        return $data;
    }

    private function corporationsForPicker()
    {
        $allowed = $this->getAllowedCorpIds();
        $query = CorporationInfo::orderBy('name')->select(['corporation_id', 'name', 'ticker']);
        if ($allowed !== null) {
            if (empty($allowed)) return collect();
            $query->whereIn('corporation_id', $allowed);
        }
        return $query->get();
    }
}
