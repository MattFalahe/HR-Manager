<?php

namespace HrManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use HrManager\Models\FormTemplate;
use HrManager\Services\ApplicationService;

/**
 * Direct application form (auth-required SeAT-internal flow). The public
 * recruitment landing flow lives in PublicRecruitmentController; this
 * controller handles direct `/hr-manager/apply/{slug}` access for already-
 * logged-in members (rejoins, internal testing, etc).
 *
 * v1.0.0 dropped the global-template fallback — every form template now
 * has a required corporation_id and the application is stamped with it.
 */
class PublicFormController extends Controller
{
    public function showForm(?string $slug = null)
    {
        $template = $slug
            ? FormTemplate::active()->where('slug', $slug)->with('questions')->firstOrFail()
            : FormTemplate::active()->default()->with('questions')->first();

        if (!$template) {
            return redirect()->route('hr-manager.help')
                ->with('error', 'No application form available.');
        }

        $characterId = auth()->user()->main_character_id;
        if (!$characterId) {
            return redirect()->route('hr-manager.help')
                ->with('error', 'You must have a linked EVE character to apply.');
        }

        $applicationService = app(ApplicationService::class);
        $hasPending = $applicationService->hasPendingApplication($characterId);

        return view('hr-manager::form.apply', compact('template', 'hasPending'));
    }

    public function submitForm(Request $request)
    {
        $request->validate([
            'template_id' => 'required|integer|exists:hr_manager_form_templates,id',
            'answers'     => 'required|array',
        ]);

        $applicationService = app(ApplicationService::class);
        $user = auth()->user();
        $characterId = $user->main_character_id;

        if (!$characterId) {
            return redirect()->back()->with('error', 'You must have a linked EVE character to apply.');
        }

        if ($applicationService->hasPendingApplication($characterId)) {
            return redirect()->back()->with('error', trans('hr-manager::applications.apply_already_pending'));
        }

        $template = FormTemplate::findOrFail($request->template_id);
        // corp_id comes from the template (always set in v1.0.0)
        $corporationId = (int) $template->corporation_id;

        $application = $applicationService->submitApplication(
            $characterId,
            $request->template_id,
            $corporationId,
            $request->answers,
            $user->id
        );

        return redirect()->route('hr-manager.apply.confirmation', $application->id);
    }

    public function confirmation(int $id)
    {
        $application = \HrManager\Models\Application::findOrFail($id);

        $characterId = auth()->user()->main_character_id;
        if (!$characterId || $application->character_id !== $characterId) {
            abort(403);
        }

        return view('hr-manager::form.confirmation', compact('application'));
    }
}
