<?php

/*
|--------------------------------------------------------------------------
| HR Manager Routes (v1.0.0)
|--------------------------------------------------------------------------
|
| Three top-level groups:
|   1. /recruit/{ticker}/{slug}    - PUBLIC unauthenticated landing pages
|   2. /recruit/{ticker}/{slug}/apply - auth-gated apply flow (SeAT login
|                                      triggers if visitor isn't authed)
|   3. /hr-manager/*               - internal admin/recruiter/director UI
|
*/

// === PUBLIC unauthenticated landing pages ===
Route::group([
    'namespace'  => 'HrManager\Http\Controllers',
    'prefix'     => 'recruit',
    'middleware' => ['web', 'locale'], // NO auth
], function () {
    // Public, no-auth applicant progress page. Keyed off the unguessable
    // tracking_token generated at submit time. Renders a curated subset
    // (status + timeline + own answers + joined-corp outcome). No notes,
    // no recruiter comments, no handler list.
    //
    // MUST be declared BEFORE the catch-all /{ticker}/{slug} below —
    // otherwise '/recruit/track/<token>' matches /{ticker}/{slug} first
    // (ticker='track') and 404s on a non-existent corp landing.
    Route::get('/track/{token}', [
        'as'   => 'hr-manager.recruit.track',
        'uses' => 'PublicApplicationTrackingController@track',
    ]);

    Route::get('/{ticker}/{slug}', [
        'as'   => 'hr-manager.recruit.show',
        'uses' => 'PublicRecruitmentController@show',
    ]);
    Route::post('/{ticker}/{slug}/click-apply', [
        'as'   => 'hr-manager.recruit.click-apply',
        'uses' => 'PublicRecruitmentController@clickApply',
    ]);

    // Public hero image stream. Streams the stored hero file directly via
    // the framework so the URL works regardless of `php artisan storage:link`
    // being run (some Docker stacks never run it; some mount over the
    // `public/storage` directory). Same /recruit URL family as the landing
    // itself so CORS / CDN configs stay simple.
    Route::get('/{ticker}/{slug}/hero', [
        'as'   => 'hr-manager.recruit.hero',
        'uses' => 'PublicRecruitmentController@hero',
    ]);
});

// === Auth-gated apply flow (same /recruit prefix, requires SeAT login) ===
Route::group([
    'namespace'  => 'HrManager\Http\Controllers',
    'prefix'     => 'recruit',
    'middleware' => ['web', 'auth', 'locale'],
], function () {
    Route::get('/{ticker}/{slug}/apply', [
        'as'   => 'hr-manager.recruit.apply',
        'uses' => 'PublicRecruitmentController@apply',
    ]);

    // Kick off SeAT's add-character SSO from the apply form, remembering
    // the apply URL so the post-SSO redirect()->intended() lands the
    // applicant right back on the form (with their new alt now linked).
    Route::get('/{ticker}/{slug}/link-character', [
        'as'   => 'hr-manager.recruit.link-character',
        'uses' => 'PublicRecruitmentController@linkCharacter',
    ]);

    // JSON poll endpoint for the hydrating screen. Returns whether
    // SeAT has finished loading the character data the landing's
    // eligibility rules actually need. Auth-required since it leaks
    // which character's data is missing.
    Route::get('/{ticker}/{slug}/hydrate', [
        'as'   => 'hr-manager.recruit.hydrate',
        'uses' => 'PublicRecruitmentController@checkHydration',
    ]);
    Route::post('/{ticker}/{slug}/apply', [
        'as'   => 'hr-manager.recruit.apply.submit',
        'uses' => 'PublicRecruitmentController@submitApply',
    ]);
    Route::get('/{ticker}/{slug}/applied/{applicationId}', [
        'as'   => 'hr-manager.recruit.applied',
        'uses' => 'PublicRecruitmentController@applied',
    ]);
});

// === Internal HR Manager surface (recruiter / director / admin) ===
Route::group([
    'namespace'  => 'HrManager\Http\Controllers',
    'prefix'     => 'hr-manager',
    'middleware' => ['web', 'auth', 'locale'],
], function () {

    // --- VIEW TIER: help only ---
    Route::get('/help', [
        'as'         => 'hr-manager.help',
        'uses'       => 'HelpController@index',
        'middleware' => 'can:hr-manager.view',
    ]);

    // --- AUTHENTICATED direct-form flow (rejoin / internal testing) ---
    Route::group(['prefix' => 'apply'], function () {
        Route::get('/confirmation/{id}', [
            'as'   => 'hr-manager.apply.confirmation',
            'uses' => 'PublicFormController@confirmation',
        ]);
        Route::get('/{slug?}', [
            'as'   => 'hr-manager.apply',
            'uses' => 'PublicFormController@showForm',
        ]);
        Route::post('/', [
            'as'   => 'hr-manager.apply.submit',
            'uses' => 'PublicFormController@submitForm',
        ]);
    });

    // --- RECRUITER TIER ---
    Route::get('/dashboard', [
        'as'         => 'hr-manager.dashboard',
        'uses'       => 'DashboardController@index',
        'middleware' => 'can:hr-manager.recruiter',
    ]);

    Route::group(['prefix' => 'applications', 'middleware' => 'can:hr-manager.recruiter'], function () {
        Route::get('/',           ['as' => 'hr-manager.applications.index', 'uses' => 'ApplicationController@index']);
        Route::get('/{id}',       ['as' => 'hr-manager.applications.show',  'uses' => 'ApplicationController@show']);
        Route::post('/{id}/status', ['as' => 'hr-manager.applications.status', 'uses' => 'ApplicationController@updateStatus']);
        // Handler tracking — recruiters can join/leave themselves; directors
        // can leave/edit anyone via the same routes (controller checks).
        Route::post('/{id}/handlers/join',   ['as' => 'hr-manager.applications.handlers.join',   'uses' => 'ApplicationController@joinAsHandler']);
        Route::post('/{id}/handlers/leave',  ['as' => 'hr-manager.applications.handlers.leave',  'uses' => 'ApplicationController@leaveAsHandler']);
        Route::post('/{id}/handlers/role',   ['as' => 'hr-manager.applications.handlers.role',   'uses' => 'ApplicationController@updateHandlerRole']);
        // Manual re-trigger of the temporary applicant-data access grant for
        // the current viewer (a handler) — no leave/re-join needed.
        Route::post('/{id}/grant-access',    ['as' => 'hr-manager.applications.grant-access',    'uses' => 'ApplicationController@grantAccess']);
        Route::post('/{id}/refresh-assessment', ['as' => 'hr-manager.applications.refresh-assessment', 'uses' => 'ApplicationController@refreshAssessment']);
    });

    // --- DIRECTOR-ONLY: member + player profiles ---
    // These pages expose sensitive per-character ESI + assessment data
    // (wallet, mail, assets, skills, login history, intel cross-refs), so
    // the dedicated profile pages are director-gated (raised from recruiter
    // 2026-06-18). Recruiters work from the Applications surface, which only
    // shows the application's own scoped data.
    Route::group(['prefix' => 'members', 'middleware' => 'can:hr-manager.director'], function () {
        Route::get('/',              ['as' => 'hr-manager.members.index', 'uses' => 'MemberController@index']);
        Route::get('/{characterId}', ['as' => 'hr-manager.members.show',  'uses' => 'MemberController@show']);
    });

    // Player surface. URL param {id} is the canonical PlayerIdentity.id.
    // Legacy seat_user_id URLs auto-redirect to the identity-keyed URL
    // via 301 in PlayerController::show. Reassign + merge (director-only)
    // live here too — the Player Identity surface was folded in. Director-
    // gated (raised from recruiter 2026-06-18) for the same sensitivity
    // reason as the member profiles above.
    Route::group(['prefix' => 'players', 'middleware' => 'can:hr-manager.director'], function () {
        Route::get('/',                          ['as' => 'hr-manager.players.index', 'uses' => 'PlayerController@index']);
        Route::get('/{id}',                      ['as' => 'hr-manager.players.show',  'uses' => 'PlayerController@show']);
        Route::post('/{id}/loa',                 ['as' => 'hr-manager.players.loa',           'uses' => 'PlayerController@markLoa']);
        Route::post('/{id}/clear-status',        ['as' => 'hr-manager.players.clear-status',  'uses' => 'PlayerController@clearStatus']);
        Route::post('/{id}/refresh',             ['as' => 'hr-manager.players.refresh',       'uses' => 'PlayerController@refreshAssessments']);
        Route::post('/{id}/notes',               ['as' => 'hr-manager.players.notes',         'uses' => 'PlayerController@addNote']);
        Route::post('/{id}/reassign/{characterId}', ['as' => 'hr-manager.players.reassign-character', 'uses' => 'PlayerController@reassignCharacter']);
        Route::post('/{id}/merge',               ['as' => 'hr-manager.players.merge-identity', 'uses' => 'PlayerController@mergeIdentity']);
        // Remove the player from their SeAT squads (purge cleanup). Mirrors
        // SeAT's native kick so Connector-managed Discord roles cascade off.
        Route::post('/{id}/remove-squads',       ['as' => 'hr-manager.players.remove-squads', 'uses' => 'PlayerController@removeSquads']);
    });

    Route::group(['prefix' => 'watchlist', 'middleware' => 'can:hr-manager.recruiter'], function () {
        Route::get('/',          ['as' => 'hr-manager.watchlist.index',   'uses' => 'WatchlistController@index']);
        Route::post('/',         ['as' => 'hr-manager.watchlist.store',   'uses' => 'WatchlistController@store']);
        Route::delete('/{id}',   ['as' => 'hr-manager.watchlist.destroy', 'uses' => 'WatchlistController@destroy']);
    });

    // Intel database — visibility controlled at the controller level
    // by IntelController::assertCanViewIntel (directors always; recruiters
    // only when intel.recruiter_view_enabled is true). Route middleware
    // is the floor (recruiter+); controller layer narrows further.
    Route::group(['prefix' => 'intel', 'middleware' => 'can:hr-manager.recruiter'], function () {
        Route::get('/',                ['as' => 'hr-manager.intel.index',   'uses' => 'IntelController@index']);
        Route::get('/character/{id}',  ['as' => 'hr-manager.intel.show',    'uses' => 'IntelController@show']);
        Route::post('/notes',          ['as' => 'hr-manager.intel.store',   'uses' => 'IntelController@store']);
        Route::delete('/notes/{id}',   ['as' => 'hr-manager.intel.destroy', 'uses' => 'IntelController@destroy']);
    });

    Route::group(['prefix' => 'notes', 'middleware' => 'can:hr-manager.recruiter'], function () {
        Route::post('/',     ['as' => 'hr-manager.notes.store',  'uses' => 'NoteController@store']);
        Route::put('/{id}',  ['as' => 'hr-manager.notes.update', 'uses' => 'NoteController@update']);
        Route::delete('/{id}', ['as' => 'hr-manager.notes.destroy', 'uses' => 'NoteController@destroy']);
    });

    // --- DIRECTOR TIER ---
    Route::group(['middleware' => 'can:hr-manager.director'], function () {
        Route::post('/members/{characterId}/refresh', [
            'as' => 'hr-manager.members.refresh', 'uses' => 'MemberController@refreshAssessment',
        ]);

        Route::group(['prefix' => 'templates'], function () {
            Route::get('/',          ['as' => 'hr-manager.templates.index',  'uses' => 'TemplateController@index']);
            Route::get('/create',    ['as' => 'hr-manager.templates.create', 'uses' => 'TemplateController@create']);
            Route::post('/',         ['as' => 'hr-manager.templates.store',  'uses' => 'TemplateController@store']);
            Route::get('/{id}/edit', ['as' => 'hr-manager.templates.edit',   'uses' => 'TemplateController@edit']);
            Route::put('/{id}',      ['as' => 'hr-manager.templates.update', 'uses' => 'TemplateController@update']);
            Route::post('/{id}/default', ['as' => 'hr-manager.templates.set-default', 'uses' => 'TemplateController@setDefault']);
            Route::post('/{id}/duplicate', ['as' => 'hr-manager.templates.duplicate', 'uses' => 'TemplateController@duplicate']);
        });

        // Recruitment landings (CRUD)
        Route::group(['prefix' => 'landings'], function () {
            Route::get('/',           ['as' => 'hr-manager.landings.index',   'uses' => 'RecruitmentLandingController@index']);
            Route::get('/create',     ['as' => 'hr-manager.landings.create',  'uses' => 'RecruitmentLandingController@create']);
            Route::post('/',          ['as' => 'hr-manager.landings.store',   'uses' => 'RecruitmentLandingController@store']);
            Route::get('/{id}/edit',  ['as' => 'hr-manager.landings.edit',    'uses' => 'RecruitmentLandingController@edit']);
            Route::put('/{id}',       ['as' => 'hr-manager.landings.update',  'uses' => 'RecruitmentLandingController@update']);
            Route::post('/{id}/toggle-publish', ['as' => 'hr-manager.landings.toggle-publish', 'uses' => 'RecruitmentLandingController@togglePublish']);
            Route::get('/{id}/analytics', ['as' => 'hr-manager.landings.analytics', 'uses' => 'RecruitmentLandingController@analytics']);
        });

        // Corp Health (classifier output)
        Route::get('/corp-health', [
            'as' => 'hr-manager.corp-health.index', 'uses' => 'CorpHealthController@index',
        ]);
        Route::post('/corp-health/run-now', [
            'as' => 'hr-manager.corp-health.run-now', 'uses' => 'CorpHealthController@runNow',
        ]);

        // Director-tier player actions. Same {id} = PlayerIdentity.id
        // semantics as the recruiter-tier routes above.
        Route::post('/players/{id}/mark-for-purge', [
            'as' => 'hr-manager.players.mark-for-purge', 'uses' => 'PlayerController@markForPurge',
        ]);
        Route::post('/players/{id}/purge-executed', [
            'as' => 'hr-manager.players.purge-executed', 'uses' => 'PlayerController@markPurgeExecuted',
        ]);
        Route::post('/corp-health/purge/{id}/step', [
            'as' => 'hr-manager.corp-health.purge-step', 'uses' => 'CorpHealthController@purgeStep',
        ]);
        Route::post('/corp-health/purge/{id}/note', [
            'as' => 'hr-manager.corp-health.purge-note', 'uses' => 'CorpHealthController@purgeNote',
        ]);
        Route::post('/corp-health/purge/{id}/remove-squads', [
            'as' => 'hr-manager.corp-health.purge-remove-squads', 'uses' => 'CorpHealthController@purgeRemoveSquads',
        ]);
    });

    // --- ADMIN TIER ---
    Route::group(['prefix' => 'settings', 'middleware' => 'can:hr-manager.admin'], function () {
        Route::get('/',  ['as' => 'hr-manager.settings.index',  'uses' => 'SettingsController@index']);
        Route::post('/', ['as' => 'hr-manager.settings.update', 'uses' => 'SettingsController@update']);
        // JSON role list for the AJAX-lazy-load role picker (mirrors
        // mining-manager.settings.notifications.roles + the equivalent
        // SM endpoint). One source of truth for every Discord role
        // dropdown in the plugin.
        Route::get('/roles', ['as' => 'hr-manager.settings.roles', 'uses' => 'SettingsController@roles']);
        Route::post('/webhooks',        ['as' => 'hr-manager.settings.webhooks.store',   'uses' => 'SettingsController@storeWebhook']);
        Route::put('/webhooks/{id}',    ['as' => 'hr-manager.settings.webhooks.update',  'uses' => 'SettingsController@updateWebhook']);
        Route::delete('/webhooks/{id}', ['as' => 'hr-manager.settings.webhooks.destroy', 'uses' => 'SettingsController@deleteWebhook']);
        Route::post('/webhooks/{id}/test', ['as' => 'hr-manager.settings.webhooks.test', 'uses' => 'SettingsController@testWebhook']);

        Route::post('/tiers',          ['as' => 'hr-manager.settings.tiers.store',     'uses' => 'SettingsController@storeTierMapping']);
        Route::put('/tiers/{id}',      ['as' => 'hr-manager.settings.tiers.update',    'uses' => 'SettingsController@updateTierMapping']);
        Route::delete('/tiers/{id}',   ['as' => 'hr-manager.settings.tiers.destroy',   'uses' => 'SettingsController@deleteTierMapping']);
        Route::post('/tiers/defaults', ['as' => 'hr-manager.settings.tiers.defaults',  'uses' => 'SettingsController@updateTierDefaults']);
    });

    Route::group(['middleware' => 'can:hr-manager.admin'], function () {
        Route::delete('/applications/{id}', ['as' => 'hr-manager.applications.destroy', 'uses' => 'ApplicationController@destroy']);
        Route::delete('/templates/{id}',    ['as' => 'hr-manager.templates.destroy',    'uses' => 'TemplateController@destroy']);
        Route::delete('/landings/{id}',     ['as' => 'hr-manager.landings.destroy',     'uses' => 'RecruitmentLandingController@destroy']);

        // Diagnostic dashboard — admin-only, deliberately NOT in the sidebar.
        // Reach it directly at /hr-manager/diagnostic.
        Route::get('/diagnostic', ['as' => 'hr-manager.diagnostic', 'uses' => 'DiagnosticController@index']);
        Route::post('/diagnostic/test-notification', ['as' => 'hr-manager.diagnostic.test-notification', 'uses' => 'DiagnosticController@sendTestNotification']);
    });
});
