<?php

return [

    // The hardcoded casual default welcome. {member} / {corp} / {care} are
    // auto-filled; the [bracketed] bits are for the operator to replace. Used
    // when a corp has no custom template row, and pre-fills the editor.
    'default_body' => "Welcome to {corp}, {member}! Great to have you aboard. A few first steps to get you settled in:\n\n**In-game intel channels:** join [your intel channels] in game so you are in the loop.\n**Home station:** we are based out of [your home station].\n**Trading hub:** we mainly buy and sell at [your trading hub].\n**Bookmarks:** load our shared bookmark folder by [how to load it].\n\nAny questions at all, our new-member team {care} are here to help. Welcome to the corp!",

    // ── Settings → Onboarding ───────────────────────────────────────────────
    'tab'            => 'Onboarding',
    'heading'        => 'New-member onboarding welcome',
    'intro'          => 'When a genuinely new person joins the corp, HR can post a member-facing welcome to a Discord channel that @-mentions them with your first-steps guide. It fires after a short delay so SeAT Connector has time to assign the Discord role that lets them see the channel. Opt-in, off by default; route it with the <strong>New-member onboarding welcome</strong> webhook category.',
    'enabled'        => 'Enable the onboarding welcome',
    'enabled_help'   => 'Master switch. On its own this does nothing: each corporation must also be turned on individually below, so enabling this never starts greeting people somewhere you did not intend. With both on, a genuinely new person joining that corp is queued for a welcome — new people only (not an existing member\'s alt), once per account. Delivery also needs an enabled webhook subscribing to the onboarding category.',
    'delay'          => 'Delay after join (minutes)',
    'delay_help'     => 'Minimum wait after the join before posting, giving SeAT Connector time to link the member and grant the role that lets them see the channel. Default 30. HR then holds the welcome until they are actually reachable (still in the corp, a webhook subscribing, and a linked Discord identity), so someone who links a day late is still welcomed properly. Dropped if they leave during the wait; abandoned if still undeliverable after 7 days.',
    'template_label' => 'Welcome message (per corp)',
    'template_help'  => 'Markdown. Variables: <code>{member}</code> (@-mentions the new member, or names them if Discord isn\'t linked), <code>{corp}</code> (corp name), and <code>{care}</code> (the @-mentions of your new-member-care roles below). Leave blank to use the built-in default.',
    'care_roles_label' => 'New-member care team (roles to @-mention)',
    'care_roles_help'  => 'Discord roles the <code>{care}</code> variable expands to, so your onboarding team is pinged alongside the new member.',
    'corp_picker_label' => 'Corporation',
    'corp_picker_help'  => 'Pick the corp whose welcome you want to edit. Changes to any corp you have edited are saved together when you press Save.',
    'corp_enabled'      => 'Run onboarding welcomes for this corporation',
    'corp_enabled_help' => 'Off by default — every corp has to be turned on individually. The master switch above only arms the feature; turning it on does not start greeting new members anywhere until you pick the corps. A corp left off never queues a welcome at all.',
    'corp_hook_ok'      => 'A webhook covers this corp with the onboarding category enabled — welcomes can be delivered.',
    'corp_hook_missing' => 'No enabled webhook covers this corp with the "New-member onboarding welcome" category. Welcomes will queue and wait rather than send — add the category to a webhook (global, or scoped to this corp) under Settings → Webhooks.',
    'corp_context'   => 'Editing template for',
    'saved'          => 'Onboarding welcome settings saved.',
    'reset_default'  => 'Reset to default',
    'no_connector'   => 'SeAT Connector is not installed, so the welcome will name the new member in plain text instead of @-mentioning them. Everything else works; install Connector later to turn the ping on.',
    'no_corps'       => 'No corporations are visible to HR yet. Once a corp is tracked, its welcome template will appear here to edit.',
];
