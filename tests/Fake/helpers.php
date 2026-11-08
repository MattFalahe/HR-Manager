<?php

// Laravel's global helpers that the services call unqualified. collect() comes
// from illuminate/collections, so only these two are needed.

use Carbon\Carbon;

if (!function_exists('now')) {
    /**
     * Real Carbon, so Carbon::setTestNow() in a test fixes the clock and every
     * "days since" figure is the same on every run.
     */
    function now($timezone = null): Carbon
    {
        return Carbon::now($timezone);
    }
}

if (!function_exists('app')) {
    function app($abstract = null)
    {
        return HrManager\Tests\Fake\Container::get($abstract);
    }
}
