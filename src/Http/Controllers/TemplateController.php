<?php

namespace HrManager\Http\Controllers;

use HrManager\Http\Controllers\Traits\ScopesCorporationAccess;
use HrManager\Models\FormTemplate;
use HrManager\Models\FormTemplateQuestion;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Form template admin. v1.0.0: corporation_id is REQUIRED on every template
 * (the global / NULL-corp path is dropped), so the includeGlobal flag from
 * the trait isn't used here anymore.
 */
class TemplateController extends Controller
{
    use ScopesCorporationAccess;

    public function index()
    {
        $query = FormTemplate::withCount(['questions', 'applications' => function ($q) {
                $q->withTrashed();
            }])
            ->orderBy('is_default', 'desc')
            ->orderBy('sort_order');
        $this->scopeToAllowedCorps($query);
        $templates = $query->get();

        $corpIds = $templates->pluck('corporation_id')->filter()->unique()->values();
        $corporations = $corpIds->isNotEmpty()
            ? \Seat\Eveapi\Models\Corporation\CorporationInfo::whereIn('corporation_id', $corpIds)
                ->pluck('name', 'corporation_id')
            : collect();

        return view('hr-manager::templates.index', compact('templates', 'corporations'));
    }

    public function create()
    {
        $corporations = $this->corporationsForPicker();
        return view('hr-manager::templates.create', compact('corporations'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'           => 'required|string|max:255',
            'description'    => 'nullable|string',
            'corporation_id' => 'required|integer',
            'questions'      => 'required|array|min:1',
            'questions.*.question_text' => 'required|string',
            'questions.*.question_type' => 'required|in:text,textarea,select,checkbox,radio,number,url',
            'questions.*.is_required'   => 'nullable|boolean',
            'questions.*.options'       => 'nullable|string',
            'questions.*.help_text'     => 'nullable|string',
            'questions.*.placeholder'   => 'nullable|string|max:255',
        ]);

        $corporationId = (int) $request->corporation_id;
        $this->assertCanAccessCorp($corporationId);

        $template = FormTemplate::create([
            'name'           => $request->name,
            'slug'           => Str::slug($request->name),
            'description'    => $request->description,
            'is_default'     => false,
            'is_active'      => true,
            'corporation_id' => $corporationId,
            'created_by'     => auth()->user()->id,
        ]);

        $this->replaceQuestions($template, $request->questions);

        return redirect()->route('hr-manager.templates.index')
            ->with('success', trans('hr-manager::templates.template_created'));
    }

    public function edit(int $id)
    {
        $template = FormTemplate::with('questions')->findOrFail($id);
        $this->assertCanAccessCorp($template->corporation_id);
        $corporations = $this->corporationsForPicker();
        // Once a template has applications, its questions are locked (past
        // applications snapshot their own Q&A, so changing the questions
        // here would only confuse the record). Metadata stays editable;
        // the view offers Duplicate to make an editable copy instead.
        $inUse = $template->isInUse();
        return view('hr-manager::templates.edit', compact('template', 'corporations', 'inUse'));
    }

    public function update(Request $request, int $id)
    {
        $template = FormTemplate::findOrFail($id);
        $this->assertCanAccessCorp($template->corporation_id);

        $inUse = $template->isInUse();

        // Metadata is always editable. Question rules only apply when the
        // template hasn't been used yet — a used template keeps its existing
        // questions (the form doesn't even post them).
        $rules = [
            'name'           => 'required|string|max:255',
            'description'    => 'nullable|string',
            'is_active'      => 'nullable|boolean',
            'corporation_id' => 'required|integer',
        ];
        if (!$inUse) {
            $rules += [
                'questions'      => 'required|array|min:1',
                'questions.*.question_text' => 'required|string',
                'questions.*.question_type' => 'required|in:text,textarea,select,checkbox,radio,number,url',
                'questions.*.is_required'   => 'nullable|boolean',
                'questions.*.options'       => 'nullable|string',
                'questions.*.help_text'     => 'nullable|string',
                'questions.*.placeholder'   => 'nullable|string|max:255',
            ];
        }
        $request->validate($rules);

        $corporationId = (int) $request->corporation_id;
        $this->assertCanAccessCorp($corporationId);

        $template->update([
            'name'           => $request->name,
            'slug'           => Str::slug($request->name),
            'description'    => $request->description,
            'is_active'      => !empty($request->is_active),
            'corporation_id' => $corporationId,
        ]);

        // Only rewrite questions for an unused template. Skipping this for a
        // used template is the lock — its questions stay exactly as the
        // applicants saw them.
        if (!$inUse) {
            $this->replaceQuestions($template, $request->questions);
        }

        $message = $inUse
            ? trans('hr-manager::templates.template_updated_locked')
            : trans('hr-manager::templates.template_updated');

        return redirect()->route('hr-manager.templates.index')->with('success', $message);
    }

    /**
     * Clone a template (and its questions) into a new, editable template.
     * This is the "create a new template instead" path for locked, in-use
     * templates: copy then edit the copy, leaving the original (and its
     * applications) untouched. The copy is inactive + non-default so it
     * can't accidentally go live before it's reviewed.
     */
    public function duplicate(int $id)
    {
        $source = FormTemplate::with('questions')->findOrFail($id);
        $this->assertCanAccessCorp($source->corporation_id);

        $copy = DB::transaction(function () use ($source) {
            $newName = trans('hr-manager::templates.copy_name', ['name' => $source->name]);
            $copy = FormTemplate::create([
                'name'           => mb_substr($newName, 0, 255),
                'slug'           => Str::slug($newName) . '-' . substr(uniqid(), -5),
                'description'    => $source->description,
                'is_active'      => false,
                'is_default'     => false,
                'corporation_id' => $source->corporation_id,
            ]);

            foreach ($source->questions as $q) {
                $copy->questions()->create([
                    'question_text' => $q->question_text,
                    'question_type' => $q->question_type,
                    'is_required'   => $q->is_required,
                    'options'       => $q->options,
                    'help_text'     => $q->help_text,
                    'placeholder'   => $q->placeholder,
                    'sort_order'    => $q->sort_order,
                ]);
            }

            return $copy;
        });

        return redirect()->route('hr-manager.templates.edit', $copy->id)
            ->with('success', trans('hr-manager::templates.template_duplicated'));
    }

    public function setDefault(int $id)
    {
        $template = FormTemplate::findOrFail($id);
        $this->assertCanAccessCorp($template->corporation_id);

        DB::transaction(function () use ($template) {
            FormTemplate::where('corporation_id', $template->corporation_id)
                ->lockForUpdate()
                ->update(['is_default' => false]);
            $template->update(['is_default' => true]);
        });

        return redirect()->route('hr-manager.templates.index')
            ->with('success', trans('hr-manager::templates.default_set'));
    }

    public function destroy(int $id)
    {
        $template = FormTemplate::findOrFail($id);
        $this->assertCanAccessCorp($template->corporation_id);

        // A used template can't be deleted: its applications reference it,
        // and a landing may still point at it. Deactivate it (edit → untick
        // Active) or Duplicate it instead. Past applications keep their data
        // either way; this just keeps the record intact.
        if ($template->isInUse()) {
            return redirect()->route('hr-manager.templates.index')
                ->with('error', trans('hr-manager::templates.delete_blocked_in_use'));
        }

        $template->delete();
        return redirect()->route('hr-manager.templates.index')
            ->with('success', trans('hr-manager::templates.template_deleted'));
    }

    private function corporationsForPicker()
    {
        $allowed = $this->getAllowedCorpIds();
        $query = \Seat\Eveapi\Models\Corporation\CorporationInfo::orderBy('name')
            ->select(['corporation_id', 'name']);
        if ($allowed !== null) {
            if (empty($allowed)) return collect();
            $query->whereIn('corporation_id', $allowed);
        }
        return $query->get();
    }

    private function replaceQuestions(FormTemplate $template, array $questions): void
    {
        DB::transaction(function () use ($template, $questions) {
            $template->questions()->delete();
            foreach ($questions as $index => $q) {
                $options = null;
                if (!empty($q['options']) && in_array($q['question_type'], ['select', 'checkbox', 'radio'])) {
                    $options = array_map('trim', explode("\n", $q['options']));
                }
                FormTemplateQuestion::create([
                    'template_id'    => $template->id,
                    'question_text'  => $q['question_text'],
                    'question_type'  => $q['question_type'],
                    'options'        => $options,
                    'is_required'    => !empty($q['is_required']),
                    'sort_order'     => $index + 1,
                    'help_text'      => $q['help_text'] ?? null,
                    'placeholder'    => $q['placeholder'] ?? null,
                ]);
            }
        });
    }
}
