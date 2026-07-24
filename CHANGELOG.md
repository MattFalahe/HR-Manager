# Changelog

All notable changes to HR Manager will be documented in this file.

## [1.0.0] - 2026-07-24

First release. HR Manager is two faces in one plugin: a **public recruitment funnel** prospects move through without a SeAT account (landing pages, eligibility gating, application forms, applicant assessment, SSO scope control, Discord onboarding), and a **director-side assessment console** for keeping the corp healthy (Corp Health classifier, activity tiers, wallet / mining / blueprint / FC signals, structure compliance, purge workflow). It works standalone and gets richer when Manager Core, Corp Wallet Manager, Mining Manager, Blueprint Manager, Structure Manager, or SeAT Broadcast are installed.

> Mental model: the recruitment funnel decides who gets *in*; the assessment console decides who should *stay*. They share one data spine (applications, players, tiers, history), so a pilot's whole arc lives in one place.

### 🎉 Recruitment funnel (public)

- **Public landing pages** at `/recruit/{ticker}/{slug}`, no SeAT account needed to view. Share the URL on forums, Discord, or Reddit; Open Graph meta tags make embeds render the corp pitch + hero image.
- **Four visual templates** (Classic / Showcase / Minimal / Industrial) with operator-set primary + accent colours and an optional hero image (streamed via the framework, so it works with or without `storage:link` and behind restrictive proxies). Markdown body editor for the pitch, with a small formatting toolbar.
- **Eligibility engine** gating applications before they reach you: security status min/max, total SP, character age, blacklisted past corps, whitelisted alliances, SeAT Connector requirement. Failures show the specific reason, with a manual-review escape hatch for borderline applicants.
- **Customizable form templates** with seven question types, per-corp scoping. Once a template has collected an application its questions lock; a one-click **Duplicate** makes a fresh editable copy. Each answer snapshots its question text at submit time, so historical Q&A is never rewritten.
- **Link more characters from the apply form**: the applicant sees which of their characters recruiters will review (portraits + a Main badge) and can link more alts inline via SeAT's add-character SSO, returning to the form afterwards. The form **requires** registering every character / alt (not just the main), and a **returning member** who has characters HR already knows (from the player-identity record) but has not re-authed gets a blinking red warning listing the missing ones, so they re-add everything before submitting.
- **Post-submission modes**: Discord invite, SeAT Connector hand-off (auto-roles new members on accept), custom Markdown, or a deliberate None mode, each pairable with always-visible "Next steps" notes. A mid-application "Link Discord now" button shows when Connector is installed.
- **Hydrating screen** for the post-SSO window: detects which SeAT character signals are still loading, dispatches the jobs, and polls with a per-signal status display so a fresh applicant is assessed on real data, with a manual-review escape hatch if it stalls.
- **Public applicant tracking page** at `/recruit/track/{token}`: applicants bookmark and check status without logging in. Shows status, transitions, own answers, and joined-corp outcome, and never notes / recruiter comments / internals. When the corp enables it (Settings → General), the applicant gets a **Withdraw application** button while their application is still open. The corp can optionally **ask for a withdrawal reason** — and make it required — which lands on the director-side application timeline (never echoed on the public page). When SeAT Connector is installed but the applicant hasn't linked Discord, the page also surfaces a **Link your Discord** step so they see the outstanding action before accept time.
- **Undo an accidental status change** (director): revert the last status change on an application. The mistake is *voided* (kept in the internal history, struck-through, "reverted by X: reason") but hidden from the applicant's tracking timeline, and the status is restored to what it was — so a stray "Rejected" never lingers on the applicant's page. Director-only, mandatory reason, audited; reverting an Accepted warns that it does not roll back the acceptance's side effects.
- **Stale application flag**: the applications list badges any still-open application awaiting review longer than the configurable `stale_days` window (Settings → General), so a review backlog is visible at a glance.
- **Analytics** (director): views by day, IP-hashed unique viewers, apply-click rate, conversion %, and top referrers over 7 / 30 / 90 / 180 day windows.
- Operators can override the public-page Blade via `php artisan vendor:publish --tag=hr-manager-recruit-views`.

### 🔎 Applicant assessment (recruiter intel)

- An automated **green / amber / red verdict** on every application, composing what HR already holds: **corp-hopping** (corporations joined in 12 months), **NPC-corp parking**, character age, security status, a **watchlist cross-check**, a **zKillboard** PvP summary, and skill points.
- **Progressive by granted scope**: with the optional intel scopes it also reads **implants** (an established main vs a throwaway alt), **current-corp roles** (a Director elsewhere flags amber), and **standings** (flags an applicant blue to an entity you mark hostile, from SeAT's Standings Builder or your own hostile / friendly lists, with a corp-vs-alliance precedence toggle).
- The panel separates **scope not granted** from **not synced yet** (scope present, ESI data still landing), and a **Refresh** button re-queues the ESI sync (public info / corp history / skills / implants / corp roles / contacts, the intel jobs gated on scope) so a recruiter can pull fresh numbers when apply-time hydration lags or a scope was granted late. It also shows the applicant's **current corporation** with an NPC / player-corp flag, and for a multi-character applicant a per-character breakdown (each character on the account with its corp + NPC / player badge), so a main in a player corp with an alt parked in an NPC corp reads clearly.
- Intel for the recruiter, **never a gate** (the eligibility engine stays the gate). Every threshold is operator-tunable in Settings → Assessment.

### 🔑 SSO scope profile selection

- Pick which SeAT SSO scope profile the recruitment funnel sends applicants through (Settings → SSO & Scopes), with a sufficiency verdict: **broken** (missing `publicData`), **minimal** (applications work but assessment features have no data), or **full**.
- Scopes are tiered **required** (`publicData`) / **recommended** (skills / wallet / assets / mail) / **optional intel** (clones / implants, corp roles, killmails, standings, contacts). A missing optional scope is an unlit assessment signal, never a problem. SeAT still owns the scope profiles; HR selects and verifies, and a stale choice self-heals to the SeAT default.

### 🎯 Activity tiers

- Five-tier hierarchy: L-1 Applicant / L0 Member / L1 Junior Officer / L2 Senior Officer / L3 Director. Higher number means a stricter activity expectation; Director defaults to the tightest window because corp survival depends on active directors.
- Per-tier default thresholds plus Discord role to tier mappings (corp-specific or global). A player with multiple mapped roles takes the **highest tier** for their badge but the **strictest inactivity threshold** — the fewest days across all their roles — so a Director (14d) who also holds an IT role capped at 7d is held to 7 days. Each mapping is **editable inline** (tier / override / notes) with a **Notes column** on the table, so you can adjust a mapping without deleting and re-adding it. Auto-resolution via the SeAT Connector framework; without it the tier column reads "Unmapped" and everything else still works. Tier mappings are read **live** each classification run, so adding or changing one takes effect on the next nightly pass (02:00) or immediately via **Corp Health → Run now** — no re-`init` needed. If you did `init` before mapping, `hr-manager:init --corporation="name|ticker|ID"` re-applies the assessment + classification for a single corp.

### 🩺 Corp Health assessment console (director)

- A nightly classifier (`hr-manager:classify-players`) buckets every player in every tracked corp into **Active / At Risk / Inactive / Dead Weight** from tier and last activity. An L3 player who goes quiet raises a separate **inactive-director** critical alert — fired only once they are genuinely past their tier threshold, with an operator-chosen repeat cadence (once / daily / 3 days / weekly / biweekly / monthly) for as long as they stay dark.
- Organised into lazy-loaded tabs — Overview / Composition / Economy / Structure Compliance / Membership / Recruitment / Access / Purge, plus an optional Alignment tab — each building only its own sections. The assessment views come first and the recruitment + purge workflow tabs sit at the end; the active tab is high-contrast so it's always clear which section you're on.
- **Corp composition chart**: what fraction of the roster rats / mines / trades / does PI / does industry, from a handful of bulk queries.
- **Corp-wide activity (all members)**: buckets the whole roster (registered or not) on last in-game login, using the same day bands as the Member tier, with a drill-down of flagged members.
- **Fleet-commander roster + Organizers** (Composition): active / faded / new FCs ranked by broadcasts, plus who *plans* ops, with next-op countdowns.
- Soft **Personnel Manager coherence check**: how many HR recruiters in a corp hold the in-game Personnel Manager role.
- **Access tab** (director): corp-wide **access depth** in one place — **one row per registered account, SeAT access alongside Discord access**, so a person's two access surfaces read together at a glance. SeAT shows superusers, in-game Directors, HR Director/Admin grants **and every SeAT role each account holds — detected from both direct Access Management assignments and squad membership** (squad-granted roles tagged with the squad; HR badges include permissions inherited through a squad). Discord shows **every** connector-managed role each linked member holds, operator-flagged **sensitive** roles in red and the rest neutral, with unlinked accounts flagged. Each Discord role carries its **grant source** from the connector's set-entity mapping (hover to see whether it comes via a **squad**, a **SeAT role**, corp / alliance membership, a direct grant, or public) — so the *squad → SeAT role → Discord role* resolution chain is visible; squad-tied Discord roles get a squad icon. Rows are **sorted by activity tier** (highest first), falling back to superuser → in-game Director → HR grant → the rest for accounts with no tier mapping, and **any stat badge is clickable to filter the list** — including "no Discord link" and "unregistered" gaps — and **filters stack** (click several to widen the view). **Unregistered characters** (in the corp, no SeAT account) are listed too, with blinking "No SeAT account" + "No Discord link" badges. Long rosters are **paginated** (25 / 50 / 100 / 250 / 500 per page, default 50). Cross-referenced against the classifier so **deep access sitting on a disengaged account** leads the tab as a risk call-out. The **Alignment** tab gets the same treatment — clickable Aligned / Misaligned filters and pagination. Batched reads, director-only, honest about its floor: only connector-managed Discord roles are visible to SeAT.
- **Everything groups by human.** The at-risk / inactive / dead-weight table, the **Director roster**, the Economy contributor cards, the Blueprint-engagement list and the **Recruitment access map** all fold a person's characters into one row headed by their main, with an expandable per-character breakdown — so a 30-alt human is one entry, not thirty. Names + portraits throughout (never a bare "User #id"), and the access map shows which alt actually holds the in-game Personnel Manager / Director role.
- **Account-holistic wallet weighting.** The classifier's Corp Wallet signals are judged on the *account*, not the alt — "stalled" fires only when the account's freshest contribution across all its characters is old, "negative" only when the account's *summed* net is negative. A dormant cyno / mining alt no longer drags an active, contributing main down to At Risk; a real tax-compliance collapse still nudges immediately.
- **"Active but carrying dormant alts"** — an informational flag (not a new bucket) on an otherwise-active account holding 2+ characters idle 90+ days, with a Characters roll-up (N active / idle / dormant) on the profile. Classification stays per-account, so a dormant alt never counts as its own dead weight.
- **Economy resolves per human.** Wallet Insights, top contributors and the anomaly board aggregate a person's alts into one figure; "Members with recent wallet flags" filters to *current* corp members, so an ex-member's stale flag no longer lingers on the board.

### 👤 Member + player profiles (director-tier)

- **Members** is per-character (one row per character, including unregistered alts); **Players** is per-human (one row per SeAT account, aggregating alts). Both require `hr-manager.director` because the profiles expose sensitive per-character ESI + assessment data; recruiters work from the Applications surface.
- **Character role classifier**: activity-based badges (Ratter / Mission Runner / Miner / Trader / Planetary Industrialist / Industrialist / PvPer / FC) inferred from observed activity, never guessed. PI / Industry / Trader / PvP work standalone.
- **In-game titles + roles** surfaced on every profile, with high-impact roles (Director / Personnel Manager / Accountant and similar) called out for the purge role-strip checklist.
- **Access depth on the player profile**: a **SeAT access depth** box (in-game roles/titles + this account's SeAT roles, permission depth, and critical-access flags) with a companion **Discord access depth** box that splits the account's Discord roles by an **operator-curated sensitive list** (Settings → Features → Sensitive Discord roles), highlighting anyone holding a leadership / FC / wallet role. Only connector-managed roles are visible to SeAT, so it's honest about being the floor on Discord power, not the ceiling. Both self-hide when their source isn't installed.
- **Last online** on the Members Quick Info, and the account's **Discord username** on the player header, when SeAT Connector is installed and mapped.
- **Player Identity**: a persistent human-level record independent of the SeAT user, lazy-materialized on first lookup, supporting character reassignment and identity merge (director-only).
- Three note surfaces (player / member / application) merged into one timeline.
- **Per-character token status** on the Players view: each character on the account is badged **token active / expired / missing** next to the in-corp / not-in-corp badge, so a director can spot an alt whose data has gone stale because its ESI token died. The card list deliberately includes characters whose token was revoked (not just live ones), so a token-lost alt still shows.
- **Per-character activity badge** alongside it: each character is badged **active (<30d) / idle (1–3mo) / inactive (>3mo — a dormant alt) / unknown**, with an account roll-up above the grid (N active / idle / dormant), so a director sees which alts an account actually uses at a glance.
- **The history timeline names its subject**: every event shows the specific character it's about (portrait + name), so a many-alt player's stack of per-alt wallet signals is finally distinguishable.
- **EveWho roster back-fill** (opt-in, Settings → Features): when SeAT has no Director token for a corp, the **Members** page fills the gap from EveWho's public corp list so it isn't near-empty — rows are name-only, badged **EveWho**, and de-duplicated against anyone SeAT already knew. A first view seeds the list; a daily `hr-manager:sync-external-rosters` cron does the full multi-page pull and keeps it fresh. Characters SeAT can't resolve show no View button (they have no profile). EveWho is a third-party aggregator, so the authoritative fix is still a Director token — this just fills the gap.
- **Instant profiles.** The heavy per-alt panels are cached, and an opt-in background pre-warm (`hr-manager:warm-player-profiles`, Settings → Features) pre-computes them every 30 min — rebuilding only accounts whose data actually changed — so a many-alt profile opens instantly instead of fanning out on the request. Mutable panels a director acts on (access, Discord, squads, notes) always stay live.
- **"View in SeAT" deep-links** on the per-character Members profile: one-click buttons (Sheet / Wallet / Mail / Assets / Skills) that jump straight into SeAT's own character pages in a new tab. Unlike the recruiter-access grant on applications, no temporary role is attached — the Members profile is already director-tier and SeAT's own permissions govern each page on click. The same deep-links also appear as a **Director deep link** card on the application detail (signed with the character, covering the applicant's whole account), so a director reviewing an application doesn't need a temporary grant.

### 🪦 Purge workflow

- `hr-manager:dispatch-purge-reminders` (every 12h) fires reminders at **T-7d / T-3d / T-48h / T-0** for players flagged for purge. The T-48h notification lists every in-corp character on the account so a human can strip Discord roles and queue the in-game kick before the deadline.
- No automatic **in-game kick** — ESI cannot remove a character from a corp, so a director performs the kick (the T-48h notification lists the characters). **Discord-role removal is a separate, opt-in story — see squad cleanup below.** A director-only **Mark Purge Executed** records the history event and archives the status row. Dedup on `(player_status_id, milestone)` keeps the cron safe to run repeatedly.
- **Squad memberships + cleanup**: the player profile and the purge board list the account's SeAT squads, split three ways: removable (`manual` / `hidden`), kept (on the operator's never-touch list), and `auto`. A one-button **Remove from these squads** clears only the removable ones via SeAT's own native-kick call, so the core squad observer fires and any Connector-bound Discord roles cascade off (without Connector it just clears the SeAT membership).
- **Opt-in auto squad cleanup** (Settings, Squad cleanup tab, off by default) clears a purged member's removable squads on a safety schedule so a scheduled purge never leaves stale Discord access: immediately once the member is detected as having left the corp (no cancellation risk), otherwise at a configurable **T-24h / T-12h** before the kick date, fired by the reminder cron and stamped once per purge. A **never-touch exclusions list** protects keep-in-touch squads such as Former Member or Alliance access; `auto` squads are never touched (SeAT recomputes them from filters and would re-add an eligible member). Every removal, manual or automatic, lands on the history timeline.

### 🛡️ Token-loss Watchdog (security)

When a member revokes their SeAT refresh token (delinked, or rejected by CCP after a password change or app de-authorisation, which soft-deletes it either way), HR loses visibility into them while they may still hold corp access. A 10-minute cron (`hr-manager:detect-token-loss`) turns that into a closed-loop security response. The alert always fires; everything past it is opt-in under **Settings → Features → Security Policy**, off by default.

- **Always-on alert + history**: every revocation fires the **SeAT Token Revoked** security webhook (naming the account main so an alt's revocation points at a person) and records a critical history event.
- **Configurable auto-purge trigger**: choose what escalates an account to a security purge — the account's *main* character losing its token, *any* character, or a *share* of the account's in-corp characters (25 / 50 / 75 / 100%). A single-character account always triggers, whatever the mode.
- **Director escalation**: a character holding the in-game **Director** role losing its token always triggers (it can't hide under a percentage) and fires a distinct dark-red **SECURITY BREACH: Director token revoked** alert — full corp access with zero visibility is the worst case.
- **Auto-cancel on restore**: any auto-purge whose account has re-linked enough to no longer trip the policy clears itself back to active, with a green **Resolved: SeAT token restored** alert. A director's manual purge is never auto-cancelled.
- **HR Watchdog notes**: the arc (revoked → auto-scheduled → restored) is narrated as shield-badged **HR Watchdog** notes on the player's profile, not only the history log.
- **Access reduction (squad-drop)**: after a grace window (or immediately for a director, when that override is on), the flagged member is dropped from their **manual / hidden** squads so their Connector-driven Discord roles cascade off before the kick. Auto squads and the never-touch exclusions are left alone; HR only *removes* (a dropped squad is not re-added on restore, which is why the grace window exists).
- **Accountable override**: cancelling an auto-purge by hand is director-only and requires a written reason, recorded as an override note on the player and in the history.

### 📜 History timeline

- An append-only chronological log per player across every character and corp move: corp join/leave, application lifecycle, LOA, purge milestones, tier changes, classifier transitions, and cross-plugin signals. Idempotency keys prevent double-recording on EventBus replays.
- **Actor attribution**: every entry shows who took the action — the director's name for a manual action (auto-captured from the request), `via <plugin>` for an external EventBus signal, or **HR (automated)** for cron / classifier / the opt-in auto squad cleanup.

### 🔗 Cross-plugin signals (each self-hides when its source is absent)

- **Corp Wallet Manager**: contribution + tax-compliance + wallet signals on the classifier, a Wallet Activity panel and a Wallet Audit panel on the member profile, five director Wallet Insights cards on Corp Health (untaxed-earner radar, anomaly board, lowest contributors, loyalty recognition, corp outflows), and a **Financial pulse** strip on the Corp Health Economy tab (corp wallet balance + income / expense / net + per-month trend, via CWM v3.1's `wallet.getCorpSummary`; self-hides on older CWM).
- **Mining Manager**: favourite ores + systems and corp ore-op attendance ("attended 8 of 12 ops") on member profiles.
- **Blueprint Manager**: a Blueprint Activity panel on the player profile and a Blueprint Engagement card on Corp Health, with the signal feeding the Industrialist role badge and a positive engagement modifier.
- **SeAT Broadcast**: an FC Activity profile (broadcasts led, cadence, active span) plus a planning block on the player profile, and the fleet-commander roster + Organizers on Corp Health.
- **Structure Manager**: per-structure doctrine compliance on a Corp Health tab (each Upwell structure verdicted against the alliance fit, with a slot-by-slot diff and Copy / Appraise buttons). Structure Manager owns the doctrines and the compute; HR renders the report.
- **Buyback Manager**: buyback engagement (offers) + realized contribution (completed contracts) on a Buyback panel on the player profile and a Corp Health → Economy card (ISK credited + top contributors). Valued through a **per-corp contribution policy** (Settings → Buyback Contribution): each buyback-running corp is **Direct corp / Community / Personal** with a weight, and an alt or holding corp's buyback can be **credited to your main corp** as direct support. The policy default is read from Buyback Manager's own target model. **Contributions are valued per era**: changing a policy *freezes* the contributions made so far at their current tier/weight/attribution and applies the new policy going forward — so an old model and a new model each keep their own weight and both still count (tick **Also re-value existing** to instead re-value all history, e.g. at initial setup). A recent **counted** contribution also acts as a positive engagement signal in the Corp Health classifier — it holds a borderline at-risk member at active (a `buyback_hold` badge), exactly like the blueprint-engagement and wallet-loyalty modifiers. `hr-manager:backfill-buyback` seeds history once.

### 🔌 Cross-plugin integration

- HR works **standalone**; Manager Core is `suggest`-only, with every cross-plugin call guarded by `class_exists`.
- **Publishes** (via MC's EventBus): `hr.application.*` (submitted / accepted / rejected / withdrawn / status_changed / joined_corp), `hr.player.*` (the flagged / recovered / milestone / director ladder), and `hr.purge.*` (reminders + executed).
- **Subscribes to**: `mining.*` (Mining Manager), `member.contribution.*` + `member.tax.compliance_dropped` + `wallet.unusual_recipient_detected` (Corp Wallet Manager), `blueprint.request.*` (Blueprint Manager), `pings.broadcast.sent` + `pings.formup.scheduled` (SeAT Broadcast), and `buyback.offer.published` + `buyback.contract.completed` (Buyback Manager).
- **Exposes capabilities**: `hr.getAssessment(characterId, callerCorpId)` and `hr.getApplicationStatus(characterId, callerCorpId)`, both corp-scoped to prevent cross-corp leaks. Consumes Structure Manager's `compliance.getForCorporation` and Blueprint Manager's stat capabilities through MC.

### 🎮 SeAT Connector (Discord) — deep integration

HR reads the **warlof/seat-connector** framework directly (no hard dependency — everything below self-hides when it isn't installed), and understands its real schema end to end:

- **Discord identity**: resolves each account's Discord **username** and **effective roles** from the connector — the username shows on the player header + member Quick Info, and a muted "Not on Discord" chip marks linked-framework-but-unlinked accounts so the gap is visible.
- **True role resolution**: a user's effective Discord roles are computed the way the connector's own driver grants them — the **union of every set (Discord role) granted to an entity the account matches**: the user directly, their characters' **corporation** / **alliance**, their **SeAT ACL roles**, their **squads**, or **public** sets (from the polymorphic `seat_connector_set_entity` pivot). No more guessing from a single table.
- **Grant-source tracing** (Corp Health → Access): every Discord role names **why** the account has it — via a **squad**, a **SeAT role**, corp / alliance membership, a direct grant, or public — so the full **SeAT squad → SeAT role → Discord role** chain is visible in one place. Pairs with SeAT-role detection that distinguishes **squad-granted** (SeAT mirrors squad roles into `role_user`, so squad wins) from **directly-assigned** access.
- **Discord access depth**: an operator-curated **sensitive Discord roles** list (Settings → Features) flags leadership / FC / wallet roles in red on the player profile and the Access tab, and the tab cross-references them against the classifier to surface **sensitive Discord access sitting on a dormant account**.
- **Activity tiers**: maps **Discord roles → activity tiers** for the classifier (highest tier + strictest inactivity threshold when a member holds several), auto-resolved through the connector.
- **Recruitment**: an optional per-applicant Connector-view grant so the "Link Discord" button works during application, plus a post-submission **Connector handoff** that auto-roles accepted members (link auto-derived from your SeAT address).
- **Purge cleanup**: clearing a purged member's removable squads uses SeAT's native kick, so **Connector-bound Discord roles cascade off** exactly as a manual kick would.

### 🔔 Notifications & routing

- **Every alert deep-links to its subject.** Discord embeds get a clickable title plus an explicit *Open in HR Manager* link (Slack gets a linked line): application alerts open that application, player-level alerts (inactive director / dead weight / purge reminders / status changes / token revoked) open the player profile, per-character alerts (member joined / left / joined-without-application / unregistered / wallet signals / watchlist / intel) open that character's profile, and the token-coverage digest opens Corp Health. Links are absolute (built from your SeAT `APP_URL`).

- **Consolidated Notifications console** (Settings → Notifications): every notification type in one place, grouped by area (Applications / Membership changes / Security / Corp health / Wallet & tax / Purge & tokens), each with a **master on/off** that turns the type on or off on *every* channel — a global gate on top of the per-webhook category toggles. **Watchlist Detection** and **Intel Match** get their own dedicated switches here; previously they rode the inactive-director / compliance categories and couldn't be silenced on their own. The recurring wallet-alert cadence lives here beside its types, and the membership types carry the ⚡ fast-poll badge. Every type defaults on, so an install that never opens the tab behaves exactly as before.
- Discord + Slack webhooks, editable inline, each row showing the categories it fires and an Enabled toggle. Categories cover the application lifecycle, a **flagged-applicant** security alert (a new application registered a blacklisted / intel-flagged character), classifier flags (inactive-director / dead-weight), purge reminders, **four separately-routable player-status categories** — *marked on LOA* / *marked for purge* / *marked for purge → notify the player* / *status cleared* — so the role-pinged "marked for purge" alert can go to a director channel while a separate **notify-the-player** category @-mentions the flagged player directly (via SeAT Connector, once they've linked Discord) in a member-facing channel, SeAT token revocations (a dedicated security category, naming the account main so an alt's revocation points at a person), an opt-in weekly token-coverage digest, and **corp membership changes** — a member joined (naming the account main; an alt of a current member is called out as such), a member left, a **joined-without-a-valid-application** security flag for newcomers who bypassed recruitment, and a **joined-while-unregistered** flag for a character with no SeAT account at all (no token, no visibility). The 30-minute roster diff is the reliable detector; with **Manager Core** installed, joins (`CorpAppAcceptMsg`) and *voluntary* leaves (`CharLeftCorpMsg`) are also picked up **~2 minutes** from the director ESI notification feed — kicks send no EVE notification, so those stay on the diff, which dedups the fast path so nothing fires twice. Every change is also logged in-app on the director-only **Corp Health → Membership** tab: a recent joins / leaves history, a **review queue** of unreviewed no-application flags a director acknowledges (the nav tab carries a red unreviewed count), and a **Pending registration** list of current members not yet in SeAT — which **auto-clears** the moment a character registers (as their own main, or as an alt of any account).
- **Wallet-alert cadence + fast-poll badge** (Settings → Webhooks): the recurring wallet signals (tax compliance dropped / contributions stalled) — which Corp Wallet Manager re-reports every sync cycle — alert **once per episode by default** instead of every cycle, with an operator option to re-remind every 12h / 24h / 3 days / 7 days while the condition persists (a fresh alert always fires once it recovers and reappears; a per-character throttle in `wallet_alert_state` drives it). The four membership categories carry a **⚡ fast-poll badge** showing whether Manager Core's fast path (~2 min) is live; without it they still fire from the 30-minute scan.
- Discord role-mention picker with an AJAX-lazy-loaded, cached, multi-source role list (Broadcast / Connector / legacy warlof), per-source colour badges, and search.
- **Notification Routing Map** (Settings): a read-only view of which webhooks fire for each category and which role each pings. The per-webhook category toggles are grouped into labelled boxes (Applications / Security / Corp health, retention & purge / Corp membership / Wallet & tax) so a long list reads by concern.
- HTTPS-only webhook URLs with an end-anchored host allowlist (`discord.com` / `hooks.slack.com` / `slack.com`), no IP literals, a port 443 lock, and a 2-retry policy with backoff.

### 🛡️ Recruiter + applicant access (opt-in)

- **Temporary recruiter access**: when a recruiter joins an application's handler list, HR attaches a SeAT role granting view access to that applicant's character data in SeAT's own UI, scoped strictly to the applicant's whole account (main + alts, resolved live), auto-revoked on leave / close / expiry, namespace-isolated to `hr-mgr:apply:*`. The panel renders **one deep-link button per granted permission** — pick the permission set in Settings → Recruiter Access and the buttons track it exactly, from Sheet / Wallet / Mail through to every SeAT character page (Market, Industry, Killmails, PI, …). A one-click **Grant access now**, a **Re-sync characters** refresh (picks up a newly-registered alt or a changed permission set on an existing grant), and a retroactive grant on enable cover handlers who joined earlier.
- **Applicant Discord-link access**: optionally mints the Connector view permission for an applicant on submit (a temporary `hr-mgr:connector:*` role) so the "Link Discord" button works, held until they join the corp then revoked. HR never assigns Discord roles, only the page-view permission; the Connector owns roles.

### 🗂️ Activity log (director, opt-in)

- A director-only **Activity Log** page (sidebar → Activity Log) recording who did — and viewed — what inside HR Manager, so a corp has an accountable trail. **Opt-in**: off until enabled in Settings → Features; nothing is recorded before then.
- **View tracking**: every internal page a recruiter or director opens is logged with actor, timestamp and target — which application, player, member, dossier or intel record they looked at, and which Corp Health corp / tab. Captured by one middleware on the whole `/hr-manager` route group, so there are no per-page hooks to miss.
- **Every action, captured**: the same middleware logs *every* successful mutating request — add / edit / delete across applications, players, members, notes, watchlist, intel, templates, recruitment pages, webhooks, tiers and settings — so the trail is complete, not a hand-picked subset. Only genuinely-failed requests (validation errors, denied) are skipped. The high-value actions add richer detail on top (below); anything else lands under a **Management** category with a clear summary.
- **Privilege changes**: recruiter data-access and applicant Connector-link grants + revokes, each with the subject, the permission set, the expiry, and — on revoke — **why** it was lost (manual / application closed / expired).
- **Security actions**: adding or clearing a **blacklist / whitelist** entry (naming the character, severity and reason) and adding or deleting an **intel note** are recorded under the Security category — the delete rows snapshot the character before it's gone. A HIGH-severity blacklist match consciously **overridden** to accept is logged with the written justification.
- **Decisions**: every application status change (the full recruitment process), snapshotting the applicant name so the row survives a later purge, plus every player-lifecycle action (LOA / mark-for-purge / clear status / merge / reassign) named to the player.
- **Notifications**: records the action-driven notifications that go out (status-change and blacklist-override), attributed to the acting user.
- **Filter + export**: filter by category (View / Privilege / Decision / Security / Notification / Management / System), action, actor, date range, or free-text search; export the current filter to CSV. Colour-coded categories, deep links back to the referenced record, and a daily-rotating IP hash (traceable within a day, never stored raw).
- **Retention**: entries are append-only and pruned after 180 days by a daily job (`hr-manager:prune-audit-log`), which runs even when the feature is off so a disabled log still drains.

### 🔭 Watchlist + intel

- **Watchlist** (blacklist + whitelist) with alliance scope, an immediate and scheduled match check, alt-aware application warnings, and an audit trail on clear. Visible to recruiters, managed by directors. The full **lifecycle is preserved on the player timeline** — added, application-hit, and cleared events (each with who + when + reason) stay on the dossier + player profile even after the entry drops off the active list. Clearing a character whose **account has several listed alts** prompts *clear only this one* vs *clear all N on this account*, so wiping a spy's whole footprint is one action.
- **Applicant screening on submit**: every new application is checked against the blacklist + intel across the *whole applying account* (main + registered alts, resolved via shared token ownership). A hit fires a **Flagged applicant** security notification — naming the applicant's main, the flagged character, and the reason — and records the discovered link on the account's history timeline. With the opt-in **auto-flag** setting (Settings → General, off by default), HR also blacklists the account's other characters (clearly marked auto-flagged, high severity) so a later corp/alliance scan catches every alt. Closes the loop on a spy who registers a known-bad alt under a clean main. Auto-flagged entries are attributed to **HR Counter-Intelligence** (with a bot icon) rather than a meaningless *User #0*.
- **Intel Database** for long-memory per-character intel with per-corp visibility tiers and scope-corp heads-up alerts (director viewing by default; recruiter viewing is an opt-in setting).
- **Character dossier** (`/watchlist/character/{id}`): one page gathering everything HR holds on a character — the full, untruncated watchlist reasons (active *and* cleared), every intel note the viewer is allowed to see, and the director-only history timeline. Reached from the watchlist table (the character name, or an *open dossier* link on any clipped reason) and the intel page. Visibility is honoured per section: watchlist entries are scope-filtered, the intel section self-filters to what the viewer may see (empty *and* hidden for a recruiter without intel access, so nothing hidden leaks), and history is director-only.
- Corp-join detection no longer double-fires: a blacklisted character in one of *your own* corps raises a single corp ALERT instead of that plus a redundant alliance WARNING (the alliance warning stays for chars in the *other* corps of your alliance, which is what it's for).

### 🩺 Diagnostic dashboard

- Admin-only at `/hr-manager/diagnostic` (deliberately not in the sidebar): eight tabs (Health Checks, Master Test, System Validation, Ecosystem Map, Settings Health, Data Integrity, Notification Test, Application Trace), each with a what / when / heads-up intro. **Master Test** runs every check group at once — health, validation, settings and integrity — for one combined OK/WARN/FAIL verdict. **System Validation** lists consumed capabilities, owned subscriptions, published-topic registration, and a Suite-plugins-detected panel. **Ecosystem Map** shows the data HR exchanges with each sibling plugin (what it receives, what it publishes, and which HR screens that feeds) annotated with live installed status. **Application Trace** walks one application through template, answers, recruiters, and status history with a chronological timeline.

### 🛡️ Security + correctness

- **Data-level corporation scoping**: every recruiter / director query is filtered to allowed corps; admins see all. The `hr.getAssessment` capability rejects callers whose corp does not match the target, and direct URL guessing is blocked.
- **Private notes** are visible only to their author, enforced at query-scope level: not even directors or admins can read another user's private notes.
- The application state machine gates the accept / reject decision to the **Personnel Manager** tier (`hr-manager.personnel`, granted alongside Recruiter to mirror EVE's in-game recruitment role) or Director / Admin above it, with transactional, lock-protected transitions. Corp Health flags the coherence mismatch when someone holds that HR authority but not the in-game Personnel Manager role needed to complete the invite (or vice-versa).
- Discord role-id validation, and HTTPS-only webhook validation with an end-anchored host check.
- **Token-loss detection**: a 10-minute cron (`hr-manager:detect-token-loss`) watches SeAT for tracked members whose refresh token has gone — delinked, or rejected by CCP after a password change / app de-authorization (SeAT soft-deletes the token either way). It fires the dedicated **SeAT Token Revoked** webhook category, records a critical history event, and can optionally auto-schedule a security purge at T+N hours. Catches passive token death, not just deliberate delinks.
- **Member token + scope compliance**: pick a SeAT SSO scope profile as the corp requirement (Settings → SSO & Scopes → Member token requirement) and HR measures every member token against it. Each member is classified **Token OK / Missing scopes / Token lost / Never linked** — surfaced as a badge on the Members roster (the insufficient ones name the exact scopes they lack), a **Token & scope coverage** card on Corp Health (counts + coverage bar + lost-this-week + drill-down lists), and an opt-in weekly **token-coverage digest** to a webhook (`hr-manager:token-coverage-digest`). Leave the profile as None to check token existence only. Pure read of `refresh_tokens.scopes` + the global `sso_scopes` profiles; no ESI calls, no changes to SeAT.

### 🗄️ Schema

All tables carry the `hr_manager_` prefix and are created by the bundled migrations, which auto-run on boot. Major groups:

| Group | Tables |
|---|---|
| Configuration | `settings`, `webhook_configurations`, `wallet_alert_state` (recurring-wallet-alert throttle) |
| Form definition | `form_templates`, `form_template_questions` |
| Applications | `applications`, `application_answers`, `application_status_history`, `application_handlers` |
| Notes | `notes` (polymorphic: application / member / player) |
| Assessment | `member_assessments`, `fc_activity` |
| Activity tiers | `role_tier_mappings` |
| Player state | `player_status`, `player_classifications`, `member_history_events`, `player_identities` |
| Purge | `purge_reminders` |
| Watchlist + intel | `watchlist`, `intel_notes` |
| Access grants | `recruiter_access_grants`, `applicant_connector_grants` |
| Activity log | `audit_log` (opt-in director activity trail, 180-day retention) |
| Recruitment | `recruitment_landings`, `recruitment_views` |
| Buyback contribution | `buyback_activity`, `buyback_policies` |
| Corp membership | `corp_members` (roster snapshot), `membership_events` (join / leave log + review queue) |

### ⏱️ Scheduled jobs + commands

Auto-registered via the schedule seeder:

| Command | Purpose |
|---|---|
| `hr-manager:classify-players` | Nightly Corp Health classification across every active player |
| `hr-manager:cache-assessments` | Refresh the cached cross-plugin assessment signals |
| `hr-manager:dispatch-purge-reminders` | Fire the T-7 / T-3 / T-48 / T-0 purge reminders |
| `hr-manager:detect-corp-joins` | Detect accepted applicants who actually joined the corp |
| `hr-manager:detect-membership-changes` | Diff the corp roster for joins / leaves and notify (forward-only; first run seeds silently) |
| `hr-manager:scan-watchlist` | Scheduled blacklist match check + intel scope-corp pass |
| `hr-manager:sweep-access-grants` | Revoke expired recruiter + applicant access grants |
| `hr-manager:detect-token-loss` | Surface members whose ESI token has lapsed |
| `hr-manager:cleanup` | Permanently delete long-soft-deleted applications + orphan notes |
| `hr-manager:token-coverage-digest` | Weekly opt-in token + scope coverage summary per corp to subscribing webhooks |
| `hr-manager:prune-audit-log` | Daily prune of activity-log entries past the 180-day retention window (opt-in feature) |
| `hr-manager:warm-player-profiles` | Every 30 min: pre-compute player-profile panels for instant loads; skips unchanged accounts, self-limits with a lock + budget + cursor (opt-in; no-ops when off) |
| `hr-manager:sync-external-rosters` | Daily full refresh of the EveWho member rosters for corps that use the back-fill (opt-in; no-ops when off) |

Plus three manual (not scheduled) commands:

- **`hr-manager:init`** — guided first-run setup. A readiness check (required vs optional prerequisites — migrations, SeAT synced data, Manager Core + detected suite plugins, recruitment SSO profile, webhooks) that points at the setup steps, then a sequenced load (`backfill-buyback` when BB is present → `cache-assessments` → `classify-players` → `detect-corp-joins`) that populates every dashboard at once instead of waiting for the nightly crons. It deliberately skips the notification passes (token loss / watchlist), so running it before wiring webhooks gives a quiet first load. `--check` runs the readiness pass only; `--force` skips the confirmation prompt.
- **`hr-manager:backfill-buyback`** — a one-time seed of historical Buyback Manager offers + completed contracts (live data flows via the EventBus thereafter). `--dry-run` counts what would be seeded without writing.
- **`hr-manager:diagnose`** — the headless CLI counterpart of the diagnostic dashboard, for a quick health check from the shell.

### 🔧 Install

**SeAT Docker** (recommended): add `mattfalahe/hr-manager` to the `SEAT_PLUGINS` list in your seat-docker `.env`, then restart the stack so the entrypoint installs it. (Do not `composer require` inside the running container; that change is lost on the next rebuild.)

```bash
docker compose -f docker-compose.yml -f docker-compose.mariadb.yml -f docker-compose.traefik.yml down
docker compose -f docker-compose.yml -f docker-compose.mariadb.yml -f docker-compose.traefik.yml up -d
```

**Bare-metal**: `composer require mattfalahe/hr-manager` then `php artisan migrate`.

After install:

1. Assign permissions via SeAT's **Access Management** (`hr-manager.view` / `.recruiter` / `.personnel` / `.director` / `.admin`). `.personnel` (Personnel Manager) is granted *alongside* `.recruiter` to let a senior recruiter accept/reject applications without the full Director role.
2. **Templates → Create**: build at least one form template, mark it Active, scope it to your corp.
3. **Recruitment Pages → Create**: create a landing, pick a visual template, set eligibility, bind your form template, publish. The public URL goes live at `/recruit/{ticker}/{slug}`.
4. **Settings → Activity Tiers** (optional): map Discord roles to tiers and set per-tier inactivity thresholds. Auto-resolution needs the SeAT Connector framework — skip this step if you don't use Connector (set tiers manually, or leave the L0 Member default).
5. **Settings → Webhooks**: wire Discord/Slack channels and pick which categories fire to each.
6. **Settings → Features** (optional): turn on **background profile pre-warm** for instant profile loads, and — for corps you don't hold a Director token for — the **EveWho roster back-fill**. Both are off by default and do background work only when enabled.

> Reminder: HR reads SeAT's synced corp data, so a **Director ESI token** must be present in SeAT (your install almost certainly already has one). Without it the Members roster is sparse and login signals are unavailable.

### 📊 Honest limitations

- **Tier auto-resolution** requires the SeAT Connector framework (`warlof/seat-connector` + a Discord driver). Without it the tier column reads "Unmapped" and tiers are set manually; everything else still works.
- **Showcase + Industrial public templates** currently render the Classic layout. Full visual differentiation lands in a later minor release.
- **No automatic in-game kick, by design.** ESI cannot remove a character from a corp, so the T-48h notification lists the characters and a director performs the kick. **Discord-role removal is opt-in, not on by default:** HR never touches Discord directly, but if your SeAT Connector binds Discord roles to SeAT squads and you turn on auto squad cleanup (Settings → Squad cleanup), those roles cascade off automatically when HR purges the removable (`manual` / `hidden`) squads — at the timing you choose: immediately once the member is detected as having left the corp, or **T-24h / T-12h** before the kick date. Leave auto cleanup off and squad removal (with its Discord cascade) stays a one-button manual action on the purge board.
- **Assessment depth follows the granted SSO scopes.** If your recruitment profile requests only `publicData`, the wallet / skills / clones / standings signals stay dark until the profile carries those scopes. Settings → SSO & Scopes shows exactly which are present.
- **EveWho roster back-fill is a secondary source, opt-in.** For a corp with no Director token it fills the Members list from EveWho's public aggregator — which can list departed members, miss very recent joins, and returns name-only rows (no assessment, and no profile page to open). The authoritative roster still needs a Director character token with `read_corporation_membership`; the back-fill only bridges the gap until then.
