<?php

namespace HrManager\Http\Controllers;

use HrManager\Services\VersionChecker;
use Illuminate\Routing\Controller;

class HelpController extends Controller
{
    /**
     * Display the help and documentation page.
     *
     * Injects the VersionChecker result so the Overview tab's Version
     * Status card can render installed-vs-latest at a glance. Cached
     * for 6 hours and resilient to Packagist outages — the page still
     * renders if the network call fails.
     */
    public function index()
    {
        $versionStatus = app(VersionChecker::class)->getStatus();

        return view('hr-manager::help.index', compact('versionStatus'));
    }
}
