# Changelog

All notable changes to HR Manager will be documented in this file.

## [1.0.0] - 2026-06-19

First release. HR Manager is two faces in one plugin: a **public recruitment funnel** prospects move through without a SeAT account (landing pages, eligibility gating, application forms, applicant assessment, SSO scope control, Discord onboarding), and a **director-side assessment console** for keeping the corp healthy (Corp Health classifier, activity tiers, wallet / mining / blueprint / FC signals, structure compliance, purge workflow). It works standalone and gets richer when Manager Core, Corp Wallet Manager, Mining Manager, Blueprint Manager, Structure Manager, or SeAT Broadcast are installed.

> Mental model: the recruitment funnel decides who gets *in*; the assessment console decides who should *stay*. They share one data spine (applications, players, tiers, history), so a pilot's whole arc lives in one place.

### 🎉 Recruitment funnel (public)

- **Public landing pages** at `/recruit/{ticker}/{slug}`, no SeAT account needed to view. Share the URL on forums, Discord, or Reddit; Open Graph meta tags make embeds render the corp pitch + hero image.
- **Four visual templates** (Classic / Showcase / Minimal / Industrial) with operator-set primary + accent colours and an optional hero image (streamed via the framework, so it works with or without `storage:link` and behind restrictive proxies). Markdown body editor for the pitch, with a small formatting toolbar.
- **Eligibility engine** gating applications before they reach you: security status min/max, total SP, character age, blacklisted past corps, whitelisted alliances, SeAT Connector requirement. Failures show the specific reason, with a manual-review escape hatch for borderline applicants.
- **Customizable form templates** with seven question types, per-corp scoping. Once a template has collected an application its questions lock; a one-click **Duplicate** makes a fresh editable copy. Each answer snapshots its question text at submit time, so historical Q&A is never rewritten.
- **Link more characters from the apply form**: the applicant sees which of their characters recruiters will review (portraits + a Main badge) and can link more alts inline via SeAT's add-character SSO, returning to the form afterwards. The form urges registering **every** character / alt (most corps require all), and a **returning member** who has characters HR already knows (from the player-identity record) but has not re-authed gets a blinking red warning listing the missing ones, so they re-add everything before submitting.
- **Post-submission modes**: Discord invite, SeAT Connector hand-off (auto-roles new members on accept), custom Markdown, or a deliberate None mode, each pairable with always-visible "Next steps" notes. A mid-application "Link Discord now" button shows when Connector is installed.
- **Hydrating screen** for the post-SSO window: detects which SeAT character signals are still loading, dispatches the jobs, and polls with a per-signal status display so a fresh applicant is assessed on real data, with a manual-review escape hatch if it stalls.
- **Public applicant tracking page** at `/recruit/track/{token}`: applicants bookmark and check status without logging in. Shows status, transitions, own answers, and joined-corp outcome, and never notes / recruiter comments / internals.
- **Analytics** (director): views by day, IP-hashed unique viewers, apply-click rate, conversion %, and top referrers over 7 / 30 / 90 / 180 day windows.
- Operators can override the public-page Blade via `php artisan vendor:publish --tag=hr-manager-recruit-views`.

### 🔎 Applicant assessment (recruiter intel)

- An automated **green / amber / red verdict** on every application, composing what HR already holds: **corp-hopping** (corporations joined in 12 months), **NPC-corp parking**, character age, security status, a **watchlist cross-check**, a **zKillboard** PvP summary, and skill points.
- **Progressive by granted scope**: with the optional intel scopes it also reads **implants** (an established main vs a throwaway alt), **current-corp roles** (a Director elsewhere flags amber), and **standings** (flags an applicant blue to an entity you mark hostile, from SeAT's Standings Builder or your own hostile / friendly lists, with a corp-vs-alliance precedence toggle).
- The panel separates **scope not granted** from **not synced yet** (scope present, ESI data still landing), and a **Refresh** button re-queues the ESI sync (public info / corp history / skills / implants / corp roles / contacts, the intel jobs gated on scope) so a recruiter can pull fresh numbers when apply-time hydration lags or a scope was granted late. It also shows the applicant's **current corporation** with an NPC / player-corp flag.
- Intel for the recruiter, **never a gate** (the eligibility engine stays the gate). Every threshold is operator-tunable in Settings → Assessment.

### 🔑 SSO scope profile selection

- Pick which SeAT SSO scope profile the recruitment funnel sends applicants through (Settings → SSO & Scopes), with a sufficiency verdict: **broken** (missing `publicData`), **minimal** (applications work but assessment features have no data), or **full**.
- Scopes are tiered **required** (`publicData`) / **recommended** (skills / wallet / assets / mail) / **optional intel** (clones / implants, corp roles, killmails, standings, contacts). A missing optional scope is an unlit assessment signal, never a problem. SeAT still owns the scope profiles; HR selects and verifies, and a stale choice self-heals to the SeAT default.

### 🎯 Activity tiers

- Five-tier hierarchy: L-1 Applicant / L0 Member / L1 Junior Officer / L2 Senior Officer / L3 Director. Higher number means a stricter activity expectation; Director defaults to the tightest window because corp survival depends on active directors.
- Per-tier default thresholds plus Discord role to tier mappings (corp-specific or global; highest tier wins, per-mapping override beats the default). Auto-resolution via the SeAT Connector framework; without it the tier column reads "Unmapped" and everything else still works.

### 🩺 Corp Health assessment console (director)

- A nightly classifier (`hr-manager:classify-players`) buckets every player in every tracked corp into **Active / At Risk / Inactive / Dead Weight** from tier and last activity. An L3 player who goes quiet raises a separate **inactive-director** critical alert.
- Organised into six lazy-loaded tabs (Overview / Composition / Economy / Structure Compliance / Recruitment / Purge); each tab builds only its own sections.
- **Corp composition chart**: what fraction of the roster rats / mines / trades / does PI / does industry, from a handful of bulk queries.
- **Corp-wide activity (all members)**: buckets the whole roster (registered or not) on last in-game login, using the same day bands as the Member tier, with a drill-down of flagged members.
- **Fleet-commander roster + Organizers** (Composition): active / faded / new FCs ranked by broadcasts, plus who *plans* ops, with next-op countdowns.
- Soft **Personnel Manager coherence check**: how many HR recruiters in a corp hold the in-game Personnel Manager role.

### 👤 Member + player profiles (director-tier)

- **Members** is per-character (one row per character, including unregistered alts); **Players** is per-human (one row per SeAT account, aggregating alts). Both require `hr-manager.director` because the profiles expose sensitive per-character ESI + assessment data; recruiters work from the Applications surface.
- **Character role classifier**: activity-based badges (Ratter / Mission Runner / Miner / Trader / Planetary Industrialist / Industrialist / PvPer / FC) inferred from observed activity, never guessed. PI / Industry / Trader / PvP work standalone.
- **In-game titles + roles** surfaced on every profile, with high-impact roles (Director / Personnel Manager / Accountant and similar) called out for the purge role-strip checklist.
- **Player Identity**: a persistent human-level record independent of the SeAT user, lazy-materialized on first lookup, supporting character reassignment and identity merge (director-only).
- Three note surfaces (player / member / application) merged into one timeline.

### 🪦 Purge workflow

- `hr-manager:dispatch-purge-reminders` (every 12h) fires reminders at **T-7d / T-3d / T-48h / T-0** for players flagged for purge. The T-48h notification lists every in-corp character on the account so a human can strip Discord roles and queue the in-game kick before the deadline.
- No auto-removal (ESI cannot kick, and auto-stripping Discord is a footgun). A director-only **Mark Purge Executed** records the history event and archives the status row. Dedup on `(player_status_id, milestone)` keeps the cron safe to run repeatedly.
- **Squad memberships + cleanup**: the player profile and the purge board list the account's SeAT squads, split three ways: removable (`manual` / `hidden`), kept (on the operator's never-touch list), and `auto`. A one-button **Remove from these squads** clears only the removable ones via SeAT's own native-kick call, so the core squad observer fires and any Connector-bound Discord roles cascade off (without Connector it just clears the SeAT membership).
- **Opt-in auto squad cleanup** (Settings, Squad cleanup tab, off by default) clears a purged member's removable squads on a safety schedule so a scheduled purge never leaves stale Discord access: immediately once the member is detected as having left the corp (no cancellation risk), otherwise at a configurable **T-24h / T-12h** before the kick date, fired by the reminder cron and stamped once per purge. A **never-touch exclusions list** protects keep-in-touch squads such as Former Member or Alliance access; `auto` squads are never touched (SeAT recomputes them from filters and would re-add an eligible member). Every removal, manual or automatic, lands on the history timeline.

### 📜 History timeline

- An append-only chronological log per player across every character and corp move: corp join/leave, application lifecycle, LOA, purge milestones, tier changes, classifier transitions, and cross-plugin signals. Idempotency keys prevent double-recording on EventBus replays.
- **Actor attribution**: every entry shows who took the action — the director's name for a manual action (auto-captured from the request), `via <plugin>` for an external EventBus signal, or **HR (automated)** for cron / classifier / the opt-in auto squad cleanup.

### 🔗 Cross-plugin signals (each self-hides when its source is absent)

- **Corp Wallet Manager**: contribution + tax-compliance + wallet signals on the classifier, a Wallet Activity panel and a Wallet Audit panel on the member profile, five director Wallet Insights cards on Corp Health (untaxed-earner radar, anomaly board, lowest contributors, loyalty recognition, corp outflows), and a **Financial pulse** strip on the Corp Health Economy tab (corp wallet balance + income / expense / net + per-month trend, via CWM v3.1's `wallet.getCorpSummary`; self-hides on older CWM).
- **Mining Manager**: favourite ores + systems and corp ore-op attendance ("attended 8 of 12 ops") on member profiles.
- **Blueprint Manager**: a Blueprint Activity panel on the player profile and a Blueprint Engagement card on Corp Health, with the signal feeding the Industrialist role badge and a positive engagement modifier.
- **SeAT Broadcast**: an FC Activity profile (broadcasts led, cadence, active span) plus a planning block on the player profile, and the fleet-commander roster + Organizers on Corp Health.
- **Structure Manager**: per-structure doctrine compliance on a Corp Health tab (each Upwell structure verdicted against the alliance fit, with a slot-by-slot diff and Copy / Appraise buttons). Structure Manager owns the doctrines and the compute; HR renders the report.

### 🔌 Cross-plugin integration

- HR works **standalone**; Manager Core is `suggest`-only, with every cross-plugin call guarded by `class_exists`.
- **Publishes** (via MC's EventBus): `hr.application.*` (submitted / accepted / rejected / withdrawn / status_changed / joined_corp), `hr.player.*` (the flagged / recovered / milestone / director ladder), and `hr.purge.*` (reminders + executed).
- **Subscribes to**: `mining.*` (Mining Manager), `member.contribution.*` + `member.tax.compliance_dropped` + `wallet.unusual_recipient_detected` (Corp Wallet Manager), `blueprint.request.*` (Blueprint Manager), and `pings.broadcast.sent` + `pings.formup.scheduled` (SeAT Broadcast).
- **Exposes capabilities**: `hr.getAssessment(characterId, callerCorpId)` and `hr.getApplicationStatus(characterId, callerCorpId)`, both corp-scoped to prevent cross-corp leaks. Consumes Structure Manager's `compliance.getForCorporation` and Blueprint Manager's stat capabilities through MC.

### 🔔 Notifications & routing

- Discord + Slack webhooks, editable inline, each row showing the categories it fires and an Enabled toggle. Categories cover the application lifecycle, classifier flags (inactive-director / dead-weight), purge reminders, player-status changes (LOA / purge / cleared, fired inline), SeAT token revocations (a dedicated security category), and an opt-in weekly token-coverage digest.
- Discord role-mention picker with an AJAX-lazy-loaded, cached, multi-source role list (Broadcast / Connector / legacy warlof), per-source colour badges, and search.
- **Notification Routing Map** (Settings): a read-only view of which webhooks fire for each category and which role each pings.
- HTTPS-only webhook URLs with an end-anchored host allowlist (`discord.com` / `hooks.slack.com` / `slack.com`), no IP literals, a port 443 lock, and a 2-retry policy with backoff.

### 🛡️ Recruiter + applicant access (opt-in)

- **Temporary recruiter access**: when a recruiter joins an application's handler list, HR attaches a SeAT role granting view access to that applicant's character data (sheet / wallet / mail / assets / skills) in SeAT's own UI, scoped strictly to the applicant's character IDs (and alts), auto-revoked on leave / close / expiry, namespace-isolated to `hr-mgr:apply:*`. A one-click "Grant access now" plus a retroactive grant on enable covers handlers who joined earlier.
- **Applicant Discord-link access**: optionally mints the Connector view permission for an applicant on submit (a temporary `hr-mgr:connector:*` role) so the "Link Discord" button works, held until they join the corp then revoked. HR never assigns Discord roles, only the page-view permission; the Connector owns roles.

### 🔭 Watchlist + intel

- **Watchlist** (blacklist + whitelist) with alliance scope, an immediate and scheduled match check, alt-aware application warnings, and an audit trail on clear. Visible to recruiters, managed by directors.
- **Intel Database** for long-memory per-character intel with per-corp visibility tiers and scope-corp heads-up alerts (director viewing by default; recruiter viewing is an opt-in setting).

### 🩺 Diagnostic dashboard

- Admin-only at `/hr-manager/diagnostic` (deliberately not in the sidebar): six tabs (Health Checks, System Validation, Settings Health, Data Integrity, Notification Test, Application Trace), each with a what / when / heads-up intro. System Validation lists consumed capabilities, owned subscriptions, published-topic registration, and a Suite-plugins-detected panel. Application Trace walks one application through template, answers, recruiters, and status history with a chronological timeline.

### 🛡️ Security + correctness

- **Data-level corporation scoping**: every recruiter / director query is filtered to allowed corps; admins see all. The `hr.getAssessment` capability rejects callers whose corp does not match the target, and direct URL guessing is blocked.
- **Private notes** are visible only to their author, enforced at query-scope level: not even directors or admins can read another user's private notes.
- The application state machine gates director-only actions (accept / reject) with transactional, lock-protected transitions.
- Discord role-id validation, and HTTPS-only webhook validation with an end-anchored host check.
- **Token-loss detection**: a 10-minute cron (`hr-manager:detect-token-loss`) watches SeAT for tracked members whose refresh token has gone — delinked, or rejected by CCP after a password change / app de-authorization (SeAT soft-deletes the token either way). It fires the dedicated **SeAT Token Revoked** webhook category, records a critical history event, and can optionally auto-schedule a security purge at T+N hours. Catches passive token death, not just deliberate delinks.
- **Member token + scope compliance**: pick a SeAT SSO scope profile as the corp requirement (Settings → SSO & Scopes → Member token requirement) and HR measures every member token against it. Each member is classified **Token OK / Missing scopes / Token lost / Never linked** — surfaced as a badge on the Members roster (the insufficient ones name the exact scopes they lack), a **Token & scope coverage** card on Corp Health (counts + coverage bar + lost-this-week + drill-down lists), and an opt-in weekly **token-coverage digest** to a webhook (`hr-manager:token-coverage-digest`). Leave the profile as None to check token existence only. Pure read of `refresh_tokens.scopes` + the global `sso_scopes` profiles; no ESI calls, no changes to SeAT.

### 🗄️ Schema

All tables carry the `hr_manager_` prefix and are created by the bundled migrations, which auto-run on boot. Major groups:

| Group | Tables |
|---|---|
| Configuration | `settings`, `webhook_configurations` |
| Form definition | `form_templates`, `form_template_questions` |
| Applications | `applications`, `application_answers`, `application_status_history`, `application_handlers` |
| Notes | `notes` (polymorphic: application / member / player) |
| Assessment | `member_assessments`, `fc_activity` |
| Activity tiers | `role_tier_mappings` |
| Player state | `player_status`, `player_classifications`, `member_history_events`, `player_identities` |
| Purge | `purge_reminders` |
| Watchlist + intel | `watchlist`, `intel_notes` |
| Access grants | `recruiter_access_grants`, `applicant_connector_grants` |
| Recruitment | `recruitment_landings`, `recruitment_views` |

### ⏱️ Scheduled jobs + commands

Auto-registered via the schedule seeder:

| Command | Purpose |
|---|---|
| `hr-manager:classify-players` | Nightly Corp Health classification across every active player |
| `hr-manager:cache-assessments` | Refresh the cached cross-plugin assessment signals |
| `hr-manager:dispatch-purge-reminders` | Fire the T-7 / T-3 / T-48 / T-0 purge reminders |
| `hr-manager:detect-corp-joins` | Detect accepted applicants who actually joined the corp |
| `hr-manager:scan-watchlist` | Scheduled blacklist match check + intel scope-corp pass |
| `hr-manager:sweep-access-grants` | Revoke expired recruiter + applicant access grants |
| `hr-manager:detect-token-loss` | Surface members whose ESI token has lapsed |
| `hr-manager:cleanup` | Permanently delete long-soft-deleted applications + orphan notes |
| `hr-manager:token-coverage-digest` | Weekly opt-in token + scope coverage summary per corp to subscribing webhooks |
| `hr-manager:diagnose` | CLI counterpart of the diagnostic dashboard |

### 🔧 Install

**SeAT Docker** (recommended): add `mattfalahe/hr-manager` to the `SEAT_PLUGINS` list in your seat-docker `.env`, then restart the stack so the entrypoint installs it. (Do not `composer require` inside the running container; that change is lost on the next rebuild.)

```bash
docker compose -f docker-compose.yml -f docker-compose.mariadb.yml -f docker-compose.traefik.yml down
docker compose -f docker-compose.yml -f docker-compose.mariadb.yml -f docker-compose.traefik.yml up -d
```

**Bare-metal**: `composer require mattfalahe/hr-manager` then `php artisan migrate`.

After install:

1. Assign permissions via SeAT's **Access Management** (`hr-manager.view` / `.recruiter` / `.director` / `.admin`).
2. **Templates → Create**: build at least one form template, mark it Active, scope it to your corp.
3. **Recruitment Pages → Create**: create a landing, pick a visual template, set eligibility, bind your form template, publish. The public URL goes live at `/recruit/{ticker}/{slug}`.
4. **Settings → Activity Tiers**: map Discord roles to tiers and set per-tier inactivity thresholds.
5. **Settings → Webhooks**: wire Discord/Slack channels and pick which categories fire to each.
6. Add an ESI refresh token from a **Director character** so the Members roster and login signals are fully populated.

### 📊 Honest limitations

- **Tier auto-resolution** requires the SeAT Connector framework (`warlof/seat-connector` + a Discord driver). Without it the tier column reads "Unmapped" and tiers are set manually; everything else still works.
- **Showcase + Industrial public templates** currently render the Classic layout. Full visual differentiation lands in a later minor release.
- **No automatic Discord-role removal or in-game kick at purge time, by design.** ESI cannot kick from a corp, and silently stripping Discord roles is a footgun, so the T-48h notification lists the characters and a human acts.
- **Assessment depth follows the granted SSO scopes.** If your recruitment profile requests only `publicData`, the wallet / skills / clones / standings signals stay dark until the profile carries those scopes. Settings → SSO & Scopes shows exactly which are present.
