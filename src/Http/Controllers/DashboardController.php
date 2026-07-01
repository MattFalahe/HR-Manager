<?php

namespace HrManager\Http\Controllers;

use Illuminate\Routing\Controller;
use HrManager\Http\Controllers\Traits\ScopesCorporationAccess;
use HrManager\Models\Application;
use HrManager\Models\FormTemplate;

class DashboardController extends Controller
{
    use ScopesCorporationAccess;

    public function index()
    {
        // Scoped stats — only counts apps in user's accessible corps
        $stats = [
            'pending'      => $this->scopeToAllowedCorps(Application::withStatus('applied'))->count(),
            'under_review' => $this->scopeToAllowedCorps(Application::withStatus('under_review'))->count(),
            'interview'    => $this->scopeToAllowedCorps(Application::withStatus('interview'))->count(),
            'total_active' => $this->scopeToAllowedCorps(Application::pending())->count(),
        ];

        $recentApplications = $this->scopeToAllowedCorps(Application::with('character'))
            ->orderBy('submitted_at', 'desc')
            ->limit(10)
            ->get();

        $templateCount = FormTemplate::active()->count();

        return view('hr-manager::dashboard.index', compact('stats', 'recentApplications', 'templateCount'));
    }
}
