# HR Manager — Two Faces

### The new era of recruitment for SeAT v5

[![Latest Version](https://img.shields.io/packagist/v/mattfalahe/hr-manager.svg?style=flat-square)](https://packagist.org/packages/mattfalahe/hr-manager)
[![License](https://img.shields.io/badge/license-GPL--2.0-blue.svg?style=flat-square)](LICENSE)
[![SeAT](https://img.shields.io/badge/SeAT-5.x-764ba2.svg?style=flat-square)](https://github.com/eveseat/seat)

> Recruit. Assess. Retain.

HR Manager is a two-sided recruitment and member-retention plugin for EVE Online corporations on SeAT. One installation, two faces:

- **A public recruitment funnel** for applicants — landing pages, customizable forms, eligibility gates, IP-hashed analytics, public progress tracking.
- **A director-side assessment console** for leadership — player-centric view, Corp Health classifier, purge workflow with reminder ladder + 24h cooldown warnings, in-game titles + role surfacing, history timeline.

Each face stands on its own. Together they close the loop between getting people in and keeping the corp healthy.

---

## The Two Faces

Most HR plugins solve half the problem: either a slick application form (without the retention side) or a member dashboard (without the public funnel). HR Manager treats recruitment and retention as one closed loop:

1. **Public landing** → applicant fills the form, hits eligibility gates, gets a tracking link
2. **Director review** → multi-handler workflow, status changes flow to webhooks + EventBus
3. **Accepted → joined → tracked** → corp-join detection completes the funnel; the human enters the assessment view
4. **Assessment → classification → action** → wallet signals + activity gaps + login signals roll up to a Corp Health verdict
5. **Decline → purge workflow** → reminder ladder + role-strip checklist + history event; departing player's whole roster handled together

Same human, same data model, both faces:

```
                    ┌────────────────────────────────────────────────┐
                    │                  HR  MANAGER                    │
                    │   recruitment funnel   +   assessment console   │
                    │      "who gets IN"          "who STAYS"         │
                    └────────────────────────────────────────────────┘

 PUBLIC  (no SeAT account) ────────────────────────────────────────────────────────

   Landing page   /recruit/{ticker}/{slug}
   │
   │   eligibility gate   (sec · SP · age · blacklist · alliance · SeAT Connector)
   ▼
   Application form   ──►   applicant assessment   (green / amber / red intel)
   │                        watchlist + intel screen   (whole account: main + alts)
   ▼
   applied ──► under_review ──► interview ──► accepted ──►  JOINS CORP
   │
   │   public tracking link · recruiter temp-access · Discord onboarding
   ▼
 DIRECTOR SIDE  (inside SeAT) ──────────────────────────────────────────────────────

   MEMBER   (one row per human; alts rolled up)
   │
   ├──► Corp Health classifier (nightly)    Active · At Risk · Inactive · Dead Weight
   ├──► activity tiers   L-1…L3   (Discord-role mapped; strictest threshold wins)
   ├──► token & scope compliance · role alignment · in-game titles / roles
   ├──► signals (richer with sibling plugins):  wallet · mining · blueprints · FC · PvP
   │
   ├──► SECURITY  (watches members continuously)
   │       watchlist / intel corp-match · flagged applicant
   │       Token-loss Watchdog:
   │          revoked ──► [grace] ──► auto-purge ──► squad-drop (remove-only)
   │                  └──────────────►  auto-cancel when the token returns
   │
   └──► RETENTION / OFFBOARD
           LOA · mark for purge ──► T-7 · T-3 · T-48 · T-0 ──► squad cleanup ──► kick

 ALWAYS ON  (both faces) ═══════════════════════════════════════════════════════════
   history timeline (append-only; survives corp moves) · activity log (opt-in)
   notifications:   per-webhook categories → Discord / Slack, role mentions, per-corp scope
   integrations:    Manager Core · Corp Wallet Manager · Mining Manager · Blueprint Manager
                    Structure Manager · SeAT Connector · SeAT Broadcast
```

Face 1 and Face 2 below zoom into each side. The application state machine, rejection and withdrawal branches and all, is drawn out under [Application workflow](#application-workflow).

### Face 1 — Recruitment funnel (public)

Everything your prospective members touch, without a SeAT account.

- **Public landing pages** at `/recruit/{ticker}/{slug}` with four visual templates (Classic / Showcase / Minimal / Industrial)
- **Eligibility engine** gating applications before they reach you: sec status / total SP / character age / blacklist / whitelist / connector requirements, with a manual-review escape hatch
- **Customizable form templates** with seven question types (text, textarea, select, checkbox, radio, number, URL), per-corp scoping
- **Link-more-characters on the apply form**: applicants see which of their characters recruiters will review (with a Main badge) and can link more alts inline via SeAT's add-character SSO, returning to the form afterward. The form requires registering every character/alt (not just the main), and a returning member with characters HR already knows but hasn't re-authed gets a blinking warning listing the missing ones
- **Four post-submission modes**: Discord invite / SeAT Connector handoff / custom Markdown message / none — pair any of them with always-visible Markdown "Next steps" notes. The Connector handoff link is auto-derived from your SeAT address (no config), and there's an optional mid-application "Link Discord now" button too
- **SSO scope profile selection + sufficiency check** (Settings → SSO & Scopes): pick which SeAT SSO scope profile the funnel sends applicants through, with a verdict on whether it carries the scopes HR needs (broken / minimal-works / full). Scopes are tiered (required / recommended / optional intel) so you request just enough to assess and display, and unlock deeper assessment signals (clones, implants, and more) when applicants grant the optional intel tier
- **Applicant assessment** (recruiter intel on every application): an automated green / amber / red verdict composing signals HR already holds (corp-hopping, long NPC-corp parking as a possible spy or inactivity flag, character age, security status, a watchlist cross-check, a zKillboard PvP summary, and skill points). Progressive by granted scope: with intel scopes it also reads implants (real main vs throwaway alt), current-corp roles (a Director elsewhere flags amber), and standings (flags an applicant blue to an entity you rate badly, configured in **Settings → Standings**). Intel for the recruiter, never a gate; every threshold is tunable in **Settings → Assessment**. The panel separates "scope not granted" from "not synced yet" and has a **Refresh** button that re-queues the ESI sync (intel jobs gated on scope), and it shows the applicant's current corporation with an NPC / player-corp flag, plus a per-character corp breakdown for multi-character applicants (so a main in a player corp with an alt in an NPC corp is obvious)
- **Standings** (**Settings → Standings**): your corp's view of who is hostile and *how* hostile, on EVE's own -10 / -5 / 0 / +5 / +10 scale with matching colour badges. Four source modes: off, a SeAT Standings Builder profile, HR's own list, or **hybrid** (SeAT as the baseline with HR overriding it per entity). Overrides work downward as well as upward, so something your alliance rates terrible can be set neutral locally and stop generating flags. Build the list by ID or exact name, mixed freely, one per line; type is auto-detected, adding an entity that is already listed revalues it, and rows can be revalued or removed in bulk. Feeds the assessment's contact check
- **Donation flags** (security, opt-in): a nightly scan flags direct ISK transfers (`player_donation` only, never contracts or market trades) between your members and entities your standings rate badly. Two tiers: transfers predating that human's membership are recorded as history, transfers dated after they were already inside are highlighted. The join date is account-level, so an established member bringing in a new alt is not graded as a fresh recruit. Each finding freezes the standing it was judged against and when, because EVE exposes only *current* affiliation and a rating resolved today cannot honestly be applied to a two-year-old transfer. Admin-set ISK floors per tier. Nothing reads the wallet journal on a page render
- **Flagged-applicant screening** (security): every new application is screened against the blacklist + intel across the *whole applying account* (main + registered alts). A hit fires a **Flagged applicant** webhook alert (naming the main, the flagged character, and the reason) and records the discovered link on the history timeline; an opt-in setting (Settings → General) also **auto-flags the account's other characters** — those auto-flags are attributed to **HR Counter-Intelligence**, not a blank *User #0*. Catches a spy who registers a known-bad alt under a clean main
- **Whole-account blacklist / whitelist / intel**: list a **main plus their possible alts** in one action instead of adding each by hand — every alt gets its own entry with the same reason, severity, scope and alerts. For someone already in SeAT, an *include known alts* option expands the account automatically, resolved only from proven links (shared SeAT account or HR player identity — never name similarity or shared corp, and never spanning different people). For an outsider there's nothing to expand: EVE's API has no account concept, so nothing can prove an alt link, and the names you supply are recorded as **claims**. A nightly pass then settles them — once both characters appear in SeAT, the same account **confirms** the link and different accounts **refute** it, flagging that someone was listed on a wrong assumption. State badges on the list, verdict written to the main's profile, and claims are kept out of HR's authoritative identity mappings so a suspicion never masquerades as fact. Once a person's account becomes visible, a **possible missing alts** panel (player profile + watchlist dossier) names any of their characters covered by neither an entry nor a claim — the alts nobody knew about when the original block was filed; shown only when part of that human is already listed
- **Blacklist warning on the player profile**: an active blacklist entry on any character of the account raises a banner above everything else, with severity, scope, reason and a link to the dossier. Active blacklist only — a cleared entry is history and shows on the timeline instead
- **Possible alt links flagged on the profile**: a standing claim that this player is (or has) someone else's alt shows in amber whether or not anyone on the account is blacklisted — a claim that this person is the alt of a blacklisted character is exactly what a recruiter needs told before acting. States the direction plainly, carries its verdict badge (suspected / confirmed / refuted), and marks when the character on the other end is itself blacklisted. Information, not a verdict — the red banner stays reserved for someone actually on the list
- **Handler notes ping the other handlers**: adding a public note to an application @-mentions the **other handlers on it**, with the webhook's role mention deliberately suppressed — the channel isn't the audience, the co-handlers are. The author is never pinged, so a single-handler application stays silent and it starts working the moment a second recruiter joins. Private notes never notify. Its own category, off by default; without SeAT Connector it still posts, naming the handlers in plain text
- **Copy character names**: a one-click button on the application's director card and the player profile's character list that puts every character on the account on the clipboard, one per line — ready to paste into an alliance blacklist check or a spreadsheet
- **Character dossier**: one click on any watchlist name opens `/watchlist/character/{id}` — a single page with the character's full (untruncated) watchlist reasons, every intel note you're allowed to see, and (director-only) the history timeline. Visibility is respected per section, so a recruiter without intel access never sees a hidden note. Reachable from the watchlist table and the intel page. The **watchlist lifecycle is preserved on that timeline** — added, application-hit, and cleared events (each with who + when + reason) stay on the record even after the entry drops off the active list
- **Clear a whole account at once**: clearing a character whose account has several listed alts (a spy whose whole footprint is on the blacklist) prompts *clear only this one* vs *clear all N on this account* — each character still gets its own cleared entry + history record, and any entries outside your scope are left untouched
- **Public applicant tracking page** at `/recruit/track/{token}` — applicants bookmark and check status without logging in. Shows status, transitions, own answers, joined-corp outcome. Never shows notes, recruiter comments, handler list, or internals. Optional applicant **self-withdrawal** (Settings → General) adds a Withdraw button while the application is still open, and can optionally **ask for (and require) a withdrawal reason** that lands on the director-side timeline (never shown publicly). When SeAT Connector is installed but the applicant hasn't linked Discord, the page surfaces a **Link your Discord** step so they see the outstanding action before accept time
- **Undo an accidental status change** (director-only) — revert the last status change on an application: the mistake is *voided* (kept in the internal history, struck-through with who/why) but hidden from the applicant's tracking timeline, and the status is restored to what it was, so a stray "Rejected" never lingers on the applicant's page. Mandatory reason, audited; reverting an Accepted warns it does not roll back the acceptance's side effects
- **IP-hashed analytics**: visits, conversion, drop-off per landing
- **Re-applicant intelligence**: when someone applies again, the recruiter sees their prior history with the corp and lifetime contribution side-by-side

### Face 2 — Assessment console (director)

What leadership sees while doing the actual work of keeping the corp alive.

- **Player-centric view**: one row per human (not per character). Tier resolved from the highest-tier registered alt; activity rolled up across all alts
- **Per-character token badge** on the player view: each alt is badged **token active / expired / missing** beside its in-corp / not-in-corp badge, so a director sees at a glance which of an account's characters hold a live ESI token vs a revoked/expired one (token-lost alts stay listed instead of vanishing)
- **Per-character activity badge** beside it: each character is badged **active (<30d) / idle (1–3mo) / inactive (>3mo — a dormant alt) / unknown**, with an account roll-up (N active / idle / dormant), so a director sees which alts an account actually uses
- **Everything folds by human**: the at-risk / inactive / dead-weight table, the **Director roster**, the Economy contributor cards, the Blueprint-engagement list and the **Recruitment access map** all group a person's characters into one row headed by their main, with an expandable per-character breakdown — a 30-alt human is one entry, not thirty — with names + portraits throughout (never a bare "User #id")
- **Corp Health classifier**: buckets every player into Active / At Risk / Inactive / Dead Weight nightly. Judged on the **account**, not the character — a player counts as active if *any* of their characters is, and their days-inactive figure is the lowest across their alts, so an unused alt never drags an active human into a purge bucket or an alert. Wallet signals are weighed the same way (a dormant cyno / mining alt no longer drags an active, contributing main to At Risk), and an otherwise-active account carrying 2+ long-dormant alts gets an informational "carrying dormant alts" flag — never a downgrade
- **Inactive directors panel** (Corp Health, critical-tier): roster-based, so it catches directors who never authed into SeAT and are invisible to the classifier. Judged on the **person** — someone whose account is active is not an absent director however dusty one of their director characters is, and each row reports their **most recently flown** director character rather than their oldest. Characters skipped because their account is active are summarised above the table, so a genuinely dormant director character is still visible
- **Activity tiers** (L-1 Applicant → L0 Member → L1 Junior Officer → L2 Senior Officer → L3 Director) with per-corp threshold overrides. Mapping Discord roles to tiers **auto-resolves only with the SeAT Connector framework** installed; without Connector you can skip tier mapping — set tiers by hand, or leave the `L0 Member` default (the classifier still works either way)
- **Wallet signals via Corp Wallet Manager** (when installed): stalled / negative / tax compliance / silent wallet director / loyalty hold flags, percentile rank, top categories, latest entries, contribution trend. **Silent wallet director** — a director who *is* still logging in but has no corp wallet activity attributed to them — is its own notification category (off by default), separate from the Inactive Director alert, so the two route and silence independently. It stays quiet when CWM has no attributed data at all, since "no data" cannot be told apart from "did nothing"
- **Wallet Insights on Corp Health** (director-tier): corp-level rollups — untaxed-earner tax-dodge radar, wallet anomaly board, lowest-contributor list with active-but-not-paying call-out, loyalty recognition, and a corp wallet outflow audit showing where the corp's ISK goes
- **Consolidated Notifications console** (Settings → Notifications): every notification type in one place, grouped by area, each with a **master on/off** that gates it on every channel (on top of the per-webhook toggles). **Watchlist Detection** and **Intel Match** finally have their own switches here — before, they rode other categories and couldn't be turned off independently. The recurring wallet-alert cadence lives here beside its types; all types default on
- **Wallet-alert cadence** (Settings → Notifications): the recurring wallet alerts (tax compliance dropped / contributions stalled) that CWM re-reports every sync cycle fire **once per episode by default** rather than every cycle — with an operator option to re-remind every 12h / 24h / 3 / 7 days while unresolved. A fresh alert always fires once the condition recovers and reappears
- **Mining signals via Mining Manager** (when installed): tax compliance, ore preferences, activity gaps, favourite ores + systems, and corp ore-op attendance ("attended 8 of 12 ops")
- **Blueprint engagement via Blueprint Manager** (when installed): a Blueprint activity panel on the player profile (requests / fulfilled / rejected / pending, favourite blueprint types, aggregated across alts) and a Blueprint engagement card on Corp Health → Economy. Fulfilled corp sourcing also strengthens the Industrialist role badge and acts as a positive engagement modifier. Reads `blueprint.request.*` events plus the `blueprint.getCharacterStats` / `getCorpSummary` capabilities through Manager Core; self-hides when absent
- **Character role badges**: what each character is *used for*, inferred from observed activity (Ratter / Mission Runner / Miner / Trader / Planetary Industrialist / Industrialist / PvPer / FC). Plus a **corp composition chart** showing the activity mix across the roster, and an **active vs dormant characters** bar (Corp Health → Composition) giving the share of the character base that is still flying versus gone — character-level on purpose, so it measures dormant-alt load rather than headcount
- **FC activity via SeAT Broadcast** (EventBus): per-player fleet-command profile (broadcasts led / cadence / active span) + a Corp Health fleet-commander roster (active / faded / new FCs, ranked)
- **Structure compliance via Structure Manager** (when installed): Corp Health renders Structure Manager's per-structure doctrine compliance (rigs / services / online state vs your alliance fits) on its own tab, pulled live through Manager Core's PluginBridge. Structure Manager owns the doctrines and the data; HR just displays the verdict
- **Access depth** (Corp Health → Access tab, director-only): corp-wide audit of who holds real power, **one row per registered account with SeAT access alongside Discord access**. The **SeAT** side lists superusers, in-game Directors, HR Director / Admin grants, and **every SeAT ACL role each account holds — from both direct Access Management assignment and squad membership**, colour-coded **via squad** (teal, tagged with the squad) vs **direct** (with a legend). The **Discord** side lists **every** connector-managed role each linked member holds — operator-flagged **sensitive** roles in red — and each role names **why** the account has it (hover: via a squad, a SeAT role, corp / alliance, direct, or public), so the **SeAT squad → SeAT role → Discord role** resolution chain is traceable. Rows **sort by activity tier**, **any stat badge filters** the list (including the *no Discord link* and *unregistered* gaps) and **filters stack**; long rosters **paginate** (25–500 / page). **Unregistered characters** (in the corp, no SeAT account) are listed with blinking gap badges. Cross-referenced against the classifier so the tab **leads with the dormant-with-power gap**. The same split renders per-person as **SeAT access depth** + **Discord access depth** boxes on the player profile. Only connector-managed Discord roles are visible to SeAT (the floor on Discord power, not the ceiling); the Discord side needs SeAT Connector, the SeAT side stands alone
- **Buyback contribution via Buyback Manager** (when installed): a Buyback contribution panel on the player profile (offers made / contracts completed / ISK sold / weighted contribution, aggregated across alts) and a Corp Health → Economy card (ISK credited to the corp + top contributors). The clever part is a **per-corp contribution policy** (Settings → Buyback Contribution): every buyback-running corp is valued as **Direct corp / Community / Personal** with a weight, and an **alt or holding corp's buyback can be credited to your main corp** as direct support. The default is read from Buyback Manager's own target model (own-corp → community, target-corp → direct credited to that corp, designated-character → personal). **Contributions freeze per era**: changing a policy keeps the contributions made so far at their old weights and applies the new weights to new ones — both count — unless you tick *Also re-value existing*. A recent **counted** contribution also holds a borderline at-risk member at active in the Corp Health classifier (a `buyback_hold` badge), like the blueprint-engagement modifier. Reads `buyback.offer.published` + `buyback.contract.completed` through Manager Core; self-hides when absent
- **Multi-handler tracking**: any recruiter can join an application; the page shows everyone working on it with optional role labels ("Reviewer", "Background check"). Auto-tracks on status changes
- **Stale application flag**: the applications list badges any still-open application awaiting review longer than the configurable `stale_days` window (Settings → General), so a review backlog is visible at a glance
- **Temporary SeAT access for handlers** (opt-in): joining an application's handler list auto-attaches a SeAT role granting view permissions for the applicant's character data, scoped strictly to the applicant's whole account (main + alts, resolved live). The panel shows **one deep-link button per granted permission** — choose the set in Settings → Recruiter Access and the buttons track it exactly (Sheet / Wallet / Mail … through to every SeAT character page). Auto-revoked on leave / application close / expiry. Existing roles untouched, never widens beyond the applicant. A one-click **Grant access now**, a **Re-sync characters** refresh (picks up a newly-registered alt or a changed permission set), and a retroactive grant on enable cover handlers who joined earlier
- **Purge workflow** with T-7 / T-3 / T-48 / T-0 reminder ladder, one-button (or opt-in automatic) **squad cleanup** that clears a member's removable squads (manual / hidden, minus a never-touch exclusions list) so Connector-managed Discord roles drop, and a blinking warning banner urging operators to strip in-game titles + roles before the EVE 24-hour cooldown bites. The "marked for purge" alert can optionally fire a separate **personal notice** that @-mentions the flagged player directly (for a member-facing "sort it out" channel), distinct from the role-pinged director alert. A purge **closes itself** once HR detects the player has actually left — the board stops listing them and the banner drops — and **writes the whole arc to the player**: who scheduled it and when, the date it was due against the date they actually went (on time / N days early / late), where they went, the reason, and the purge notes, as both a history event and a plain-English **HR Purge log** note on the profile
- **In-game titles + roles surfacing**: corp titles and direct character roles are shown on every member / player profile, with high-impact roles (Director / Personnel Manager / Accountant / etc.) called out for the purge-strip checklist
- **Role & title alignment** (optional, Settings → Features): checks whether all of a player's characters carry the **same in-game corp titles + roles as their main**, and flags the drift both ways — *missing* (an alt never got the Member title, so a Connector role / doctrine grant silently skips it) and *extra* (an alt still holds a Director / Personnel Manager role after a reshuffle — access-creep / awox risk). Adds a **Corp Health → Alignment** tab (corp-wide, misaligned accounts first) and an alignment panel on the player profile. Registered characters only (needs the account link to know which characters are one human)
- **"View in SeAT" deep-links** on the per-character Members profile: one-click buttons (Sheet / Wallet / Mail / Assets / Skills) that open SeAT's own character pages in a new tab. No temporary grant is attached (unlike recruiter access on applications) — the Members profile is already director-tier and SeAT's own permissions govern each page on click. The same deep-links also appear as a **Director deep link** card on the application detail, covering the applicant's whole account (no grant needed)
- **History timeline**: 20+ event types (wallet signals / classifier transitions / purge milestones / LOA / squad removals / contribution drops / unusual recipients) rendered with semantic icons, each **attributed to who did it** — the director for a manual action, or **HR (automated)** for cron / classifier / EventBus events — and each **naming the specific character it's about** (portrait + name), so a many-alt player's stack of per-alt wallet signals is distinguishable
- **EveWho roster back-fill** (opt-in, Settings → Features): when SeAT has no Director token for a corp, the Members page fills the gap from **EveWho's public corp list** so it isn't near-empty — name-only rows, badged **EveWho**, **merged with** (not replacing) the characters SeAT already places in that corp, seeded on first view and kept current by a daily `hr-manager:sync-external-rosters` cron. Shortfalls are measured against ESI's public `member_count`, so a partial roster says so instead of looking complete. A character SeAT can't resolve shows no View button (it has no profile). EveWho is a third-party aggregator (can list departed members, miss recent joins), so the authoritative fix is still a Director token — this only bridges the gap
- **Instant profiles**: the heavy per-alt panels are cached, and an opt-in background pre-warm (`hr-manager:warm-player-profiles`, every 30 min, rebuilding **only accounts whose data changed** and self-limiting with a lock + budget + cursor) keeps them ready so a many-alt profile opens fast. It is also the last step of `hr-manager:init`, so profiles are quick straight after setup, with the schedule taking over from there. Mutable panels a director acts on (access / Discord / squads / notes) always stay live — they're rebuilt on every view because stale data there would mislead — so on a modest server a large account can still take a few seconds even fully warmed. Pre-warming removes the heavy read-only half of an end-to-end player assessment, not the whole page
- **Corp-join detection**: scans `character_corporation_histories` every 30 minutes for accepted applicants who actually joined. Surfaces "accepted but never joined" backlog on Corp Health
- **Token-loss Watchdog** (security): the Members roster shows who currently holds a working SeAT token, and a 10-minute cron (`hr-manager:detect-token-loss`) watches for tracked members whose refresh token has gone (delinked, or rejected by CCP after a password change / app de-authorization, which soft-deletes it either way). It always fires the **SeAT Token Revoked** webhook to your security channel and records a critical history event. Opt-in (Settings → Features → Security Policy) it becomes a closed loop: choose what escalates an account to an auto-purge (the *main* character, *any* character, or a 25 / 50 / 75 / 100% share of the account's in-corp characters, with a single-character account always triggering); a character holding the in-game **Director** role always triggers and fires a louder **SECURITY BREACH** alert; the purge **auto-cancels** the moment the member re-links (green *token restored* alert); the whole arc is narrated as shield-badged **HR Watchdog** notes on the player; and it can optionally **drop the member from their manual / hidden squads** after a grace window (or immediately for a director) so Connector Discord roles cascade off before the kick. Squad-drop is **remove-only** (HR never re-adds; a director re-adds by hand once cleared, which is why the grace window exists). Cancelling an auto-purge by hand is director-only and requires a **written, noted reason**. Everything past the always-on alert is off by default
- **Token + scope compliance**: pick a SeAT SSO scope profile as your corp requirement (Settings → SSO & Scopes → Member token requirement) and HR grades every member token against it — **Token OK / Missing scopes / Token lost / Never linked** — as a badge on the Members roster (insufficient ones name the exact scopes they lack), a **Token & scope coverage** card on Corp Health (counts, coverage bar, lost-this-week, drill-down lists), and an opt-in **weekly digest** to a webhook (`hr-manager:token-coverage-digest`). Leave the profile as None to check token existence only. Reads `refresh_tokens.scopes` against the global `sso_scopes` profiles — no ESI, no changes to SeAT
- **Corp membership-change alerts**: a 30-minute roster diff notifies on **joins** (classified — an alt of a current member names the main; an applicant is tagged "applied") and **leaves** (noting whether the person still has another character in the corp), plus a **joined-without-a-valid-application** security flag for newcomers who bypassed recruitment. With **Manager Core** installed, joins and *voluntary* leaves are also picked up **~2 minutes** from the director ESI notification feed (`CorpAppAcceptMsg` / `CharLeftCorpMsg`) rather than waiting for the diff — a kick sends no EVE notification, so those stay caught by the roster diff, which dedups the fast path so nothing fires twice. It also flags a newcomer with **no SeAT account at all** (unregistered — no token, no visibility) as its own category, and that flag **auto-clears** the moment they register the character (as their own main, or an alt of any account). Forward-only: the first scan of each corp seeds the roster silently, so existing members are never announced, and separate webhook categories let you route the security flags to a leadership channel. Settings → Webhooks badges these four membership categories with a **⚡ fast-poll indicator** showing whether the fast path is live (and it works either way — without fast-poll they arrive from the 30-minute scan). Every change is also logged in-app on the director-only **Corp Health → Membership** tab — a recent joins/leaves history, a **review queue** where a director acknowledges each no-application flag (with a red unreviewed count), and a **Pending registration** list of current members not yet in SeAT
- **New-member onboarding welcome** (opt-in, Settings → Onboarding): when a genuinely new person joins a tracked corp, HR posts a **member-facing** greeting to a Discord channel that **@-mentions the newcomer** and links your first-steps guide, held for a configurable delay (default 30 min) and then until they are actually reachable — confirmed in the corp, a webhook subscribing, and (with SeAT Connector) a linked Discord identity — so the ping lands when they can see the channel rather than at a fixed guess. **Off by default per corporation** — the master switch only arms the feature, and each corp is turned on individually, so it never starts greeting members of a corp you did not intend. **Per-corp Markdown template** with a friendly built-in default and `{member}` / `{corp}` / `{care}` variables, plus a **new-member care team** role picker the `{care}` mention expands to, so your onboarding people are pinged alongside the newcomer. New people only (an existing member's alt is ignored) and once per account — the joining character can be any of theirs, main or alt. Drops the queued welcome if they leave during the wait, and gives up after 7 days if it never becomes deliverable. Without SeAT Connector it still posts, naming the member in plain text instead of pinging. Delivered on a 5-minute cron (`hr-manager:send-onboarding-welcomes`) and routed with the **New-member onboarding welcome** webhook category
- **Activity Log** (director-only, opt-in): a dedicated sidebar page recording who did — and viewed — what inside HR Manager. One middleware over the whole plugin captures **both** every internal page view (which application / player / member / dossier / intel record was opened, plus the Corp Health corp + tab) **and every successful action** — add / edit / delete across applications, players, members, notes, watchlist, intel, templates, recruitment pages, webhooks, tiers and settings — so nothing is missed. The security-critical ones carry richer detail: **blacklist / whitelist add + clear** and **intel add + delete** (naming the character + reason, snapshotting deletes before they vanish), **privilege** grants/revokes (subject, permissions, expiry, and *why* on revoke), application **decisions** and player-lifecycle actions, and the **action-driven notifications** that go out. Filter by category / action / actor / date / text, export to CSV, jump back to any referenced record. Off until you enable it in Settings → Features; entries are append-only and pruned after 180 days. Nothing is recorded before you turn it on

---

## Quick install

**SeAT Docker** (recommended): add `mattfalahe/hr-manager` to the `SEAT_PLUGINS` list in your seat-docker `.env`, then restart the stack so the entrypoint installs it. **Do not run `composer require` inside the running container** — that change vanishes on the next rebuild.

```bash
docker compose -f docker-compose.yml -f docker-compose.mariadb.yml -f docker-compose.traefik.yml down
docker compose -f docker-compose.yml -f docker-compose.mariadb.yml -f docker-compose.traefik.yml up -d
```

**Bare-metal**: `composer require mattfalahe/hr-manager` then `php artisan migrate`. Migrations otherwise auto-run on container boot.

After the container restarts:

1. Assign permissions via SeAT's **Access Management** (`hr-manager.view` / `.recruiter` / `.personnel` / `.director` / `.admin`). `.personnel` (Personnel Manager) is granted *alongside* `.recruiter` to let a senior recruiter accept/reject applications without the full Director role
2. **HR Manager → Templates** → create at least one form template, marked Active, scoped to your corp
3. **HR Manager → Recruitment Pages** → create a landing, pick a visual template, set eligibility, bind your form template, publish
4. **HR Manager → Settings → Webhooks** → wire Discord/Slack notification channels for applications, classifier transitions, purge reminders, wallet alerts
5. **Run the guided initializer** to verify prerequisites and populate the dashboards immediately instead of waiting for the nightly crons: `docker exec -it seat-docker-front-1 php artisan hr-manager:init` (add `--check` to only run the readiness pass). It builds the assessment cache, classifies the roster, marks corp-joins, and seeds buyback history; it deliberately skips the notification passes, so running it **before** wiring webhooks gives a quiet first load

> Reminder: HR reads SeAT's synced corp data, so a **Director ESI token** must be present in SeAT (your install almost certainly already has one). Without it the Members page falls back to a sparse roster and login signals are unavailable.

---

## Permissions

Five-tier model; higher tiers inherit lower-tier access — except **Personnel Manager**, a targeted add-on you grant *alongside* Recruiter.

| Permission | Access |
|---|---|
| `hr-manager.view` | Help & Documentation only |
| `hr-manager.recruiter` | Applications + applicant assessment, own notes, character checks, watchlist + intel, join/leave applications as handler. The final accept/reject needs Personnel Manager or Director |
| `hr-manager.personnel` | **Personnel Manager** — everything a Recruiter can do, **plus accept/reject applications** (the hire/decline decision). Mirrors EVE's in-game Personnel Manager role: no member/player profiles, no kicks, no sensitive data. Grant it *alongside* Recruiter to a senior recruiter who closes applications but shouldn't have Director |
| `hr-manager.director` | Member + player profiles (sensitive per-character data), Corp Health, Activity Log, manage templates + landings, accept/reject applications, schedule purges, refresh assessments, classifier re-runs |
| `hr-manager.admin` | Settings, webhooks, delete applications and templates, diagnostic page |

Corp Health flags a **coherence mismatch** when someone holds HR accept authority (Personnel Manager / Director) but lacks the in-game Personnel Manager role to actually complete the invite — or vice-versa.

Private notes are visible **only** to their author — enforced at the database query level. Not even directors or admins can see other users' private notes.

---

## Application workflow

```
applied ──► under_review ──► interview ──► accepted ──► joined corp
              │                  │
           rejected           rejected
              │                  │
           withdrawn          withdrawn
```

| Transition | Who |
|---|---|
| applied → under_review | Recruiter, Personnel Manager, Director |
| under_review → interview | Recruiter, Personnel Manager, Director |
| interview → under_review (send back) | Personnel Manager, Director |
| any → accepted / rejected | Personnel Manager, Director |
| any → withdrawn | Admin |

Every status change is logged with actor + timestamp + optional comment. A hidden background command (`hr-manager:detect-corp-joins`, every 30 min) detects when accepted applicants actually appear in `character_corporation_histories` for the corp and flips `joined_corp_at`. Failures to join surface on Corp Health as accepted-but-not-joined.

---

## Squad memberships & purge cleanup

HR surfaces each player's **SeAT squad memberships** on the player profile (director-tier) and on the purge board, split three ways: the squads HR can remove (`manual` / `hidden`), the ones the operator has **excluded** from cleanup, and the `auto` squads SeAT manages itself. A one-button **Remove from these squads** handles purge cleanup on demand.

**Only `manual` and `hidden` squads are removed** (explicit, operator-assigned membership). Removal uses SeAT's own native-kick call (`$squad->members()->detach()`), so the core squad observer fires, and when **SeAT Connector** is installed and the squad is bound to a Discord role, the matching Discord roles cascade off exactly as a manual kick would. Without Connector it just clears the SeAT squad membership. Each removal lands on the player's history timeline.

**`auto` squads are deliberately never touched** and shown for information only. SeAT recomputes auto-squad membership from filters and would re-add an eligible member on the next ESI sync, so detaching one is futile churn. They resolve themselves once the player stops matching the criteria (for example, after they leave the corp following the purge).

### Opt-in auto cleanup

By default HR never auto-removes anyone: the human clicks the button. You can opt in (Settings, Squad cleanup tab) to have HR clear a purged member's removable squads automatically on a safety schedule, so a scheduled purge never leaves stale Discord access behind:

- **Immediately** once the member is detected as having left the corp (there is no cancellation risk once they are gone), or
- otherwise at a configurable **T-24h** or **T-12h** before the kick date, fired by the `hr-manager:dispatch-purge-reminders` cron and stamped once per purge so it never repeats.

A **never-touch exclusions list** in the same settings tab protects keep-in-touch squads such as **Former Member** or **Alliance** access, so both the auto cleanup and the manual button skip them. `auto` squads are not offered in the list because they are never removed anyway.

HR reads and detaches through SeAT's own squad relationship; it never owns squads or recomputes membership. Auto cleanup stays off until you enable it, and even then it only ever touches the removable, non-excluded squads of a member already scheduled for purge.

> **Recruitment onboarding note:** earlier builds shepherded applicants through Prospect/Member squads to auto-assign Discord roles. That recruitment-squad *routing* was retired (it churned Connector re-syncs). Discord onboarding now happens via the optional applicant Connector-link grant plus your own Connector role mapping; see the in-app Help → Recruitment Site docs. The squad feature that remains is the purge-time cleanup described above.

---

## Temporary recruiter access to applicant data

Optional feature for directors who want handlers to be able to drop into SeAT's native UI and look at the applicant's wallet, mail, assets, skills, etc. — without giving them permanent broad access.

Wired under **Settings → Recruiter Access** (off by default — operator opts in explicitly).

**Lifecycle:**

| Trigger | What HR Manager does |
|---|---|
| Recruiter joins handler list | Attaches a SeAT role `hr-mgr:apply:{id}` to the recruiter. Role grants the configured permission set, scoped via SeAT's `permission_role.filters` JSON to ONLY the applicant's account — the main plus alts (resolved live from the shared SeAT account when *Include alts* is on). |
| Feature enabled / "Grant access now" clicked | Enabling the feature retroactively grants every current handler on open applications; a handler with no active grant can also self-grant with one click (the per-join grant isn't retroactive on its own). |
| Recruiter leaves handler list | Detaches that recruiter from the role. Other handlers keep their grants. Role is deleted entirely when zero handlers remain. |
| Application accepted / rejected / withdrawn | Detaches every handler from the role at once. |
| Grant past expiry (default 7 days, hard cap 30) | Daily cron sweeper revokes — defensive backstop if a lifecycle hook missed. |

**On the application detail page**, handlers see a panel with one deep-link button **per granted permission** for each of the applicant's characters — the button set tracks the permission set you configured (Sheet / Wallet / Mail through to every SeAT character page: Market, Industry, Killmails, PI, …). A **Re-sync characters** button refreshes an existing grant when the applicant registers a new alt or you change the permission set. Clicks open SeAT's own native page in a new tab — the recruiter operates in SeAT, not a copy in HR Manager. SeAT's permission middleware honours the grant transparently.

Directors don't need this grant at all: the same deep-links appear as a **Director deep link** card on the application (covering the applicant's whole account), since a director already holds the SeAT permissions.

**Safety guarantees:**

- Your existing Director / other roles are **never read or modified** — additive only.
- Scope is **per-character ID, never wider** — handlers can't accidentally see other applicants or random pilots through this role.
- Strict `hr-mgr:apply:` namespace prefix — HR Manager only touches roles it created.
- Detach-by-pivot, never delete-by-role-id — surgical revocation.
- Every grant + revoke logged to `hr_manager_recruiter_access_grants` with reason. Auditable.

**Caveat:** depends on the applicant having granted ESI scopes during SSO. If your recruitment SSO requests `publicData` only, SeAT has no wallet/mail/asset data to show. **Settings → SSO & Scopes** now lets you pick the recruitment SSO profile and tells you exactly which of these scopes it carries (and which assessment features are dark without them); bump the profile in `SeAT → Settings → SSO Scopes` to fill any gaps.

---

## Optional integrations

HR Manager runs **standalone**. Every integration is gated by `class_exists` / `Schema::hasTable` at runtime — sibling plugins are purely additive.

| Plugin | What it adds |
|---|---|
| [Manager Core](https://github.com/MattFalahe/manager-core) | PluginBridge capability registry, EventBus pub/sub, shared pricing |
| [Mining Manager](https://github.com/MattFalahe/Mining-Manager) | Mining activity, tax payments, ore preferences on member profiles |
| [Corp Wallet Manager](https://github.com/MattFalahe/Corp-Wallet-Manager) | Per-character contribution trends, percentile rank, lifetime totals, wallet signals, milestone events |
| [Blueprint Manager](https://github.com/MattFalahe/Blueprint-Manager) | Blueprint request activity + engagement on member profiles and Corp Health (EventBus + PluginBridge) |
| [Structure Manager](https://github.com/MattFalahe/Structure-Manager) | Per-structure doctrine compliance shown on Corp Health (via Manager Core PluginBridge) |
| [Buyback Manager](https://github.com/MattFalahe/Buyback-Manager) | Buyback contribution (offers + completions) valued by a per-corp policy, on member profiles and Corp Health (EventBus) |
| [SeAT Broadcast](https://github.com/MattFalahe/seat-discord-pings) | Subscribes to HR's published events (`hr.application.*`, `hr.player.*`, `hr.purge.*`) for in-Discord coordination |
| [warlof/seat-connector](https://github.com/warlof/seat-connector) | **Deep Discord integration** — see below |
| [zKillboard](https://zkillboard.com/) | Recent PvP card on member profiles (cached, no key required) |

### SeAT Connector (Discord) — deep integration

HR reads the **warlof/seat-connector** framework directly and understands its real schema end to end. It's never a hard dependency — every piece below self-hides when Connector isn't installed — but when it is, HR lights up:

- **Discord identity** — resolves each account's Discord **username** (player header + member Quick Info) and its **effective roles**, with a muted *"Not on Discord"* chip on linked-framework-but-unlinked accounts so the gap shows.
- **True role resolution** — a user's Discord roles are computed the way Connector's own driver grants them: the **union of every set (Discord role) granted to an entity the account matches** — the user, their characters' **corporation** / **alliance**, their **SeAT ACL roles**, their **squads**, or **public** — read from the polymorphic `seat_connector_set_entity` pivot (not guessed from one table).
- **Grant-source tracing** — on **Corp Health → Access**, every Discord role names *why* the account has it (via a squad / a SeAT role / corp / alliance / direct / public), so the **SeAT squad → SeAT role → Discord role** chain is traceable. Alongside SeAT-role detection that tells **squad-granted** from **directly-assigned** access (SeAT mirrors squad roles into `role_user`, so squad wins).
- **Discord access depth** — an operator-curated **sensitive roles** list flags leadership / FC / wallet Discord roles in red on the player profile and the Access tab, cross-referenced against the classifier to catch **sensitive access on a dormant account**.
- **Activity tiers** — maps **Discord roles → activity tiers** for the classifier (highest tier, strictest threshold when several apply).
- **Recruitment** — an optional per-applicant Connector-view grant powers the in-application *"Link Discord"* button, and the post-submission **Connector handoff** auto-roles accepted members.
- **Purge cleanup** — clearing a purged member's removable squads cascades their **Connector-bound Discord roles** off, exactly as a manual kick would.

---

## EventBus contract

HR publishes events for downstream subscribers. Payload shape is the contract; subscribers depend on the array structure, not on HR's class namespaces.

| Event | Payload includes |
|---|---|
| `hr.application.submitted` | application_id, character_id, corporation_id, template_id, status, submitted_at, handler_user_ids |
| `hr.application.{accepted,rejected,withdrawn}` | + old_status, decided_at, decided_by, comment |
| `hr.application.status_changed` (any other transition, e.g. under_review / interview) | + old_status, decided_at, decided_by, comment |
| `hr.application.joined_corp` | + joined_corp_at, joined_corp_id |
| `hr.player.flagged_{at_risk,inactive,dead_weight}` | user_id, corporation_id, days_inactive, threshold_days, tier_level, wallet_flags |
| `hr.player.flagged_{wallet_stalled,wallet_compliance_low,negative_contribution}` | wallet-signal flags, same player shape |
| `hr.player.{inactive_director,silent_wallet_director}` | director-specific critical flags |
| `hr.player.recovered` | same shape, `new_category=active` |
| `hr.player.milestone_reached` | user_id, character_id, milestone_isk, lifetime_total |
| `hr.purge.reminder` | user_id, corporation_id, player_status_id, milestone (`t7`/`t3`/`t48`/`t0` in the payload, not the topic), scheduled_for, characters_in_corp |
| `hr.purge.executed` | user_id, corporation_id, player_status_id, scheduled_for |

HR also **subscribes** to:

- `mining.*` (Mining Manager) — tax events + history timeline
- `member.contribution.{stalled,milestone,drop_detected}` and `member.tax.compliance_dropped` (Corp Wallet Manager) — wallet signals that feed the classifier
- `wallet.unusual_recipient_detected` (CWM) — corp-level audit trail entry
- `blueprint.request.*` (Blueprint Manager) — created / approved / rejected / fulfilled requests land on the requester's history timeline
- `pings.broadcast.sent` and `pings.formup.scheduled` (SeAT Broadcast) — FC activity + form-up planning, accumulated into HR's own table for the FC profile + Corp Health roster
- `buyback.offer.published` and `buyback.contract.completed` (Buyback Manager) — buyback engagement + realized contribution, accumulated into HR's own table and valued through the per-corp contribution policy (with `hr-manager:backfill-buyback` to seed history)

> **Subscriber contract note:** every EventBus handler capability MUST be registered with the 3-arg signature `fn ($eventName, $publisher, array $payload) => ...`. Manager Core invokes subscribers with three positional args; a single-arg `fn (array $payload)` silently TypeErrors and the handler never runs. (See `hr.onMiningEvent` / `hr.onBroadcastSent` in the service provider for the canonical form.)

---

## Artisan commands

| Command | Cron | Purpose |
|---|---|---|
| `hr-manager:cache-assessments` | every 2 hours | Refresh cached MemberAssessment rows |
| `hr-manager:classify-players` | nightly 02:00 | Run the Corp Health classifier across every active player |
| `hr-manager:dispatch-purge-reminders` | every 12 hours | Fire T-7 / T-3 / T-48 / T-0 reminders for scheduled purges |
| `hr-manager:detect-corp-joins` | every 30 minutes | Watch SeAT histories for accepted applicants who actually joined the corp |
| `hr-manager:detect-membership-changes` | every 30 minutes | Diff the corp roster for joins / leaves and notify (classified: alt of a member / applied / no application). Forward-only — first run per corp seeds silently |
| `hr-manager:scan-watchlist` | every 15 minutes | Scheduled blacklist match check + intel scope-corp pass |
| `hr-manager:detect-token-loss` | every 10 minutes | Token-loss Watchdog: detect revoked tokens (alert + history), apply the auto-purge policy, auto-cancel on restore, run the opt-in squad-drop |
| `hr-manager:send-onboarding-welcomes` | every 5 minutes | **Opt-in**: post queued new-member onboarding welcomes whose delay has elapsed, re-checking the member is still in the corp first. No-ops when off or nothing is due |
| `hr-manager:scan-donations` | nightly 05:40 | **Opt-in**: flag direct ISK transfers (`player_donation` only) between members and entities your standings rate badly. Incremental, so a nightly pass reads only what is new; the first pass after enabling reads each member's history once and `--limit` spreads that over several nights. No-ops when off or when no standings are configured |
| `hr-manager:reconcile-suspected-alts` | nightly 05:00 | Confirm or refute "possible alt of X" claims once both characters appear in SeAT (same account = confirmed, different accounts = refuted) |
| `hr-manager:sweep-access-grants` | nightly 04:00 | Revoke expired recruiter + applicant access grants |
| `hr-manager:cleanup` | nightly 03:00 | Permanently delete soft-deleted applications older than N days |
| `hr-manager:token-coverage-digest` | weekly (Mon 09:00) | Opt-in token + scope coverage summary per corp to subscribing webhooks |
| `hr-manager:prune-audit-log` | nightly 04:30 | Prune Activity Log entries past the 180-day retention window (runs even when the feature is off) |
| `hr-manager:warm-player-profiles` | every 30 minutes | **Opt-in**: pre-compute player-profile panels for instant loads; rebuilds only changed accounts; lock + time budget + cursor keep it safe at any roster size. No-ops when off. Run by hand with `--corporation=<name\|ticker\|id>` (preview + confirm) or `--all` |
| `hr-manager:sync-external-rosters` | nightly 04:30 | **Opt-in**: full multi-page refresh of the EveWho member rosters. Scheduled runs refresh corps already seeded (a director opened their Members page). To reach corps nobody has opened, pick a set: `--corporation=<name\|ticker\|id>` (repeatable / comma-separated), `--registered` (corps your registered characters are in), `--landings` (corps with a recruitment landing), `--alliance=<id\|name>`, or `--all` for the union. Previews the list and confirms first; corps SeAT already has a roster for are skipped unless `--include-tracked`. Merges EveWho's list with SeAT's own affiliation data rather than replacing it, and reports any shortfall against ESI's public member_count. No-ops when off |
| `hr-manager:diagnose` | on-demand | Tables / bridge / event traffic / quick stats summary |
| `hr-manager:init` | on-demand | Guided first-run: readiness check (prerequisites + setup steps) then a sequenced load that populates every dashboard. Skips the notification passes |
| `hr-manager:backfill-buyback` | on-demand | One-time seed of historical Buyback Manager offers + completed contracts |

**Performance tuning** (optional, env-overridable — defaults suit most corps): `HR_WARM_MAX_INACTIVE_DAYS` (default `180`) — the profile pre-warm skips accounts with no login in this many days; lower it on a big / old roster (or run the command with `--include-dormant` to warm everyone). `HR_WARM_RUN_BUDGET_SECONDS` (default `1200`) — how long one scheduled warm run works before saving its cursor for the next cycle. `HR_ACTIVITY_WINDOW_MONTHS` (default `6`) — how far back the role classifier looks; shorter is cheaper. The pre-warm also only warms corps HR manages (those with a director token), so an alt parked in another corp never triggers a wasted build.

---

## Database

All tables prefixed with `hr_manager_`. Major tables:

| Table | Purpose |
|---|---|
| `settings`, `webhook_configurations` | configuration |
| `wallet_alert_state` | per-character throttle for the recurring wallet alerts (cadence setting) |
| `form_templates`, `form_template_questions` | per-corp application forms |
| `applications`, `application_answers`, `application_status_history` | application data + audit |
| `application_handlers` | multi-recruiter join-as-handler tracking |
| `notes` | polymorphic notes (application / member / player) |
| `member_assessments` | cached cross-plugin signals (mining / ratting / wallet aggregates) |
| `role_tier_mappings` | tier overrides per corp / per role |
| `player_status`, `purge_reminders` | LOA + purge workflow state |
| `player_classifications` | classifier output with `wallet_flags` JSON |
| `standings` | HR's own entity standings (-10 to +10), the override layer over SeAT's Standings Builder |
| `donation_flags`, `donation_scan_state` | flagged member ISK transfers with badly-rated entities + the incremental scan watermark |
| `member_history_events` | append-only history timeline |
| `audit_log` | opt-in director Activity Log (views + privilege / decision / security / notification actions), 180-day retention |
| `recruitment_landings`, `recruitment_views` | public funnel + analytics |

---

## Support

- GitHub: https://github.com/MattFalahe/HR-Manager
- Issues: https://github.com/MattFalahe/HR-Manager/issues
- SeAT Discord: https://discord.gg/azquy29nqs
- Email: mattfalahe@gmail.com

If HR Manager helps your corp run better:

- ⭐ Star the GitHub repository
- 🐛 Report bugs and edge cases
- 💡 Suggest features that fit the two-faces model
- 🔧 Contribute code improvements
- 🌟 Share with other SeAT-running corps

---

## License

GPL-2.0-or-later — see [LICENSE](LICENSE).
