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

### Face 1 — Recruitment funnel (public)

Everything your prospective members touch, without a SeAT account.

- **Public landing pages** at `/recruit/{ticker}/{slug}` with four visual templates (Classic / Showcase / Minimal / Industrial)
- **Eligibility engine** gating applications before they reach you: sec status / total SP / character age / blacklist / whitelist / connector requirements, with a manual-review escape hatch
- **Customizable form templates** with seven question types (text, textarea, select, checkbox, radio, number, URL), per-corp scoping
- **Link-more-characters on the apply form**: applicants see which of their characters recruiters will review (with a Main badge) and can link more alts inline via SeAT's add-character SSO, returning to the form afterward
- **Four post-submission modes**: Discord invite / SeAT Connector handoff / custom Markdown message / none — pair any of them with always-visible Markdown "Next steps" notes. The Connector handoff link is auto-derived from your SeAT address (no config), and there's an optional mid-application "Link Discord now" button too
- **SSO scope profile selection + sufficiency check** (Settings → SSO & Scopes): pick which SeAT SSO scope profile the funnel sends applicants through, with a verdict on whether it carries the scopes HR needs (broken / minimal-works / full). Scopes are tiered (required / recommended / optional intel) so you request just enough to assess and display, and unlock deeper assessment signals (clones, implants, and more) when applicants grant the optional intel tier
- **Applicant assessment** (recruiter intel on every application): an automated green / amber / red verdict composing signals HR already holds (corp-hopping, long NPC-corp parking as a possible spy or inactivity flag, character age, security status, a watchlist cross-check, a zKillboard PvP summary, and skill points). Progressive by granted scope: with intel scopes it also reads implants (real main vs throwaway alt), current-corp roles (a Director elsewhere flags amber), and standings (flags an applicant blue to an entity you mark hostile, sourced from SeAT Standings Builder or your own lists with a corp-vs-alliance precedence toggle). Intel for the recruiter, never a gate; every threshold is tunable in **Settings → Assessment**
- **Public applicant tracking page** at `/recruit/track/{token}` — applicants bookmark and check status without logging in. Shows status, transitions, own answers, joined-corp outcome. Never shows notes, recruiter comments, handler list, or internals
- **IP-hashed analytics**: visits, conversion, drop-off per landing
- **Re-applicant intelligence**: when someone applies again, the recruiter sees their prior history with the corp and lifetime contribution side-by-side

### Face 2 — Assessment console (director)

What leadership sees while doing the actual work of keeping the corp alive.

- **Player-centric view**: one row per human (not per character). Tier resolved from the highest-tier registered alt; activity rolled up across all alts
- **Corp Health classifier**: buckets every player into Active / At Risk / Inactive / Dead Weight nightly. Surfaces inactive directors as a critical-tier alert
- **Activity tiers** (L-1 Applicant → L0 Member → L1 Junior Officer → L2 Senior Officer → L3 Director) with per-corp threshold overrides
- **Wallet signals via Corp Wallet Manager** (when installed): stalled / negative / tax compliance / silent wallet director / loyalty hold flags, percentile rank, top categories, latest entries, contribution trend
- **Wallet Insights on Corp Health** (director-tier): corp-level rollups — untaxed-earner tax-dodge radar, wallet anomaly board, lowest-contributor list with active-but-not-paying call-out, loyalty recognition, and a corp wallet outflow audit showing where the corp's ISK goes
- **Mining signals via Mining Manager** (when installed): tax compliance, ore preferences, activity gaps, favourite ores + systems, and corp ore-op attendance ("attended 8 of 12 ops")
- **Blueprint engagement via Blueprint Manager** (when installed): a Blueprint activity panel on the player profile (requests / fulfilled / rejected / pending, favourite blueprint types, aggregated across alts) and a Blueprint engagement card on Corp Health → Economy. Fulfilled corp sourcing also strengthens the Industrialist role badge and acts as a positive engagement modifier. Reads `blueprint.request.*` events plus the `blueprint.getCharacterStats` / `getCorpSummary` capabilities through Manager Core; self-hides when absent
- **Character role badges**: what each character is *used for*, inferred from observed activity (Ratter / Mission Runner / Miner / Trader / Planetary Industrialist / Industrialist / PvPer / FC). Plus a **corp composition chart** showing the activity mix across the roster
- **FC activity via SeAT Broadcast** (EventBus): per-player fleet-command profile (broadcasts led / cadence / active span) + a Corp Health fleet-commander roster (active / faded / new FCs, ranked)
- **Structure compliance via Structure Manager** (when installed): Corp Health renders Structure Manager's per-structure doctrine compliance (rigs / services / online state vs your alliance fits) on its own tab, pulled live through Manager Core's PluginBridge. Structure Manager owns the doctrines and the data; HR just displays the verdict
- **Multi-handler tracking**: any recruiter can join an application; the page shows everyone working on it with optional role labels ("Reviewer", "Background check"). Auto-tracks on status changes
- **Temporary SeAT access for handlers** (opt-in): joining an application's handler list auto-attaches a SeAT role granting view permissions for the applicant's character data (wallet / mail / assets / skills), scoped strictly to the applicant's character IDs (+ alts via PlayerIdentity). Auto-revoked on leave / application close / expiry. Existing roles untouched, never widens beyond the applicant. A one-click **Grant access now** covers handlers who joined before the feature was enabled, and enabling it grants every current handler retroactively
- **Purge workflow** with T-7 / T-3 / T-48 / T-0 reminder ladder, one-button (or opt-in automatic) **squad cleanup** that clears a member's removable squads (manual / hidden, minus a never-touch exclusions list) so Connector-managed Discord roles drop, and a blinking warning banner urging operators to strip in-game titles + roles before the EVE 24-hour cooldown bites
- **In-game titles + roles surfacing**: corp titles and direct character roles are shown on every member / player profile, with high-impact roles (Director / Personnel Manager / Accountant / etc.) called out for the purge-strip checklist
- **History timeline**: 20+ event types (wallet signals / classifier transitions / purge milestones / LOA / squad removals / contribution drops / unusual recipients) rendered with semantic icons
- **Corp-join detection**: scans `character_corporation_histories` every 30 minutes for accepted applicants who actually joined. Surfaces "accepted but never joined" backlog on Corp Health

---

## Quick install

**SeAT Docker** (recommended): add `mattfalahe/hr-manager` to the `SEAT_PLUGINS` list in your seat-docker `.env`, then restart the stack so the entrypoint installs it. **Do not run `composer require` inside the running container** — that change vanishes on the next rebuild.

```bash
docker compose -f docker-compose.yml -f docker-compose.mariadb.yml -f docker-compose.traefik.yml down
docker compose -f docker-compose.yml -f docker-compose.mariadb.yml -f docker-compose.traefik.yml up -d
```

**Bare-metal**: `composer require mattfalahe/hr-manager` then `php artisan migrate`. Migrations otherwise auto-run on container boot.

After the container restarts:

1. Assign permissions via SeAT's **Access Management** (`hr-manager.view` / `.recruiter` / `.director` / `.admin`)
2. **HR Manager → Templates** → create at least one form template, marked Active, scoped to your corp
3. **HR Manager → Recruitment Pages** → create a landing, pick a visual template, set eligibility, bind your form template, publish
4. **HR Manager → Settings → Webhooks** → wire Discord/Slack notification channels for applications, classifier transitions, purge reminders, wallet alerts
5. **Add an ESI refresh token from a Director character** under Seat → Settings → API → Refresh tokens. Without one the Members page falls back to a sparse roster and login signals are unavailable

---

## Permissions

Four-tier model; higher tiers inherit lower-tier access.

| Permission | Access |
|---|---|
| `hr-manager.view` | Help & Documentation only |
| `hr-manager.recruiter` | Applications + applicant assessment, own notes, character checks, join/leave applications as handler |
| `hr-manager.director` | Member + player profiles (sensitive per-character data), Corp Health, manage templates + landings, accept/reject applications, schedule purges, refresh assessments, classifier re-runs |
| `hr-manager.admin` | Settings, webhooks, delete applications and templates, diagnostic page |

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
| applied → under_review | Recruiter, Director |
| under_review → interview | Recruiter, Director |
| interview → under_review (send back) | Director |
| any → accepted / rejected | Director |
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
| Recruiter joins handler list | Attaches a SeAT role `hr-mgr:apply:{id}` to the recruiter. Role grants the configured permission set (wallet / mail / etc.), scoped via SeAT's `permission_role.filters` JSON to ONLY the applicant's character IDs (+ alts via PlayerIdentity if enabled). |
| Feature enabled / "Grant access now" clicked | Enabling the feature retroactively grants every current handler on open applications; a handler with no active grant can also self-grant with one click (the per-join grant isn't retroactive on its own). |
| Recruiter leaves handler list | Detaches that recruiter from the role. Other handlers keep their grants. Role is deleted entirely when zero handlers remain. |
| Application accepted / rejected / withdrawn | Detaches every handler from the role at once. |
| Grant past expiry (default 7 days, hard cap 30) | Daily cron sweeper revokes — defensive backstop if a lifecycle hook missed. |

**On the application detail page**, handlers see a panel with deep-link buttons (Sheet / Wallet / Mail / Assets / Skills) per applicant character. Clicks open SeAT's own native page in a new tab — the recruiter operates in SeAT, not a copy in HR Manager. SeAT's permission middleware honours the grant transparently.

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
| [SeAT Broadcast](https://github.com/MattFalahe/seat-discord-pings) | Subscribes to HR's published events (`hr.application.*`, `hr.player.*`, `hr.purge.*`) for in-Discord coordination |
| [warlof/seat-connector](https://github.com/warlof/seat-connector) | Discord identity + role pull on the member profile sidebar |
| [zKillboard](https://zkillboard.com/) | Recent PvP card on member profiles (cached, no key required) |

---

## EventBus contract

HR publishes events for downstream subscribers. Payload shape is the contract; subscribers depend on the array structure, not on HR's class namespaces.

| Event | Payload includes |
|---|---|
| `hr.application.submitted` | application_id, character_id, corporation_id, template_id, status, submitted_at, handler_user_ids |
| `hr.application.{accepted,rejected,withdrawn,under_review,interview}` | + old_status, decided_at, decided_by, comment |
| `hr.application.joined_corp` | + joined_corp_at, joined_corp_id |
| `hr.player.flagged_{at_risk,inactive,dead_weight}` | user_id, corporation_id, days_inactive, threshold_days, tier_level, wallet_flags |
| `hr.player.recovered` | same shape, `new_category=active` |
| `hr.player.milestone_reached` | user_id, character_id, milestone_isk, lifetime_total |
| `hr.purge.{scheduled,cancelled,reminder_t7,reminder_t3,reminder_t48,reminder_t0,executed}` | user_id, corporation_id, purge_scheduled_for |

HR also **subscribes** to:

- `mining.*` (Mining Manager) — tax events + history timeline
- `member.contribution.{stalled,milestone,drop_detected}` and `member.tax.compliance_dropped` (Corp Wallet Manager) — wallet signals that feed the classifier
- `wallet.unusual_recipient_detected` (CWM) — corp-level audit trail entry
- `blueprint.request.*` (Blueprint Manager) — created / approved / rejected / fulfilled requests land on the requester's history timeline
- `pings.broadcast.sent` and `pings.formup.scheduled` (SeAT Broadcast) — FC activity + form-up planning, accumulated into HR's own table for the FC profile + Corp Health roster

> **Subscriber contract note:** every EventBus handler capability MUST be registered with the 3-arg signature `fn ($eventName, $publisher, array $payload) => ...`. Manager Core invokes subscribers with three positional args; a single-arg `fn (array $payload)` silently TypeErrors and the handler never runs. (See `hr.onMiningEvent` / `hr.onBroadcastSent` in the service provider for the canonical form.)

---

## Artisan commands

| Command | Cron | Purpose |
|---|---|---|
| `hr-manager:cache-assessments` | every 2 hours | Refresh cached MemberAssessment rows |
| `hr-manager:classify-players` | nightly 02:00 | Run the Corp Health classifier across every active player |
| `hr-manager:dispatch-purge-reminders` | every 12 hours | Fire T-7 / T-3 / T-48 / T-0 reminders for scheduled purges |
| `hr-manager:detect-corp-joins` | every 30 minutes | Watch SeAT histories for accepted applicants who actually joined the corp |
| `hr-manager:scan-watchlist` | every 15 minutes | Scheduled blacklist match check + intel scope-corp pass |
| `hr-manager:detect-token-loss` | every 10 minutes | Surface members whose ESI refresh token has lapsed |
| `hr-manager:sweep-access-grants` | nightly 04:00 | Revoke expired recruiter + applicant access grants |
| `hr-manager:cleanup` | nightly 03:00 | Permanently delete soft-deleted applications older than N days |
| `hr-manager:diagnose` | on-demand | Tables / bridge / event traffic / quick stats summary |

---

## Database

All tables prefixed with `hr_manager_`. Major tables:

| Table | Purpose |
|---|---|
| `settings`, `webhook_configurations` | configuration |
| `form_templates`, `form_template_questions` | per-corp application forms |
| `applications`, `application_answers`, `application_status_history` | application data + audit |
| `application_handlers` | multi-recruiter join-as-handler tracking |
| `notes` | polymorphic notes (application / member / player) |
| `member_assessments` | cached cross-plugin signals (mining / ratting / wallet aggregates) |
| `role_tier_mappings` | tier overrides per corp / per role |
| `player_status`, `purge_reminders` | LOA + purge workflow state |
| `player_classifications` | classifier output with `wallet_flags` JSON |
| `member_history_events` | append-only history timeline |
| `recruitment_landings`, `recruitment_views` | public funnel + analytics |

---

## Why "Two Faces"?

Most HR plugins solve half the problem: either a slick application form (without the retention side) or a member dashboard (without the public funnel). HR Manager treats recruitment and retention as one closed loop:

1. **Public landing** → applicant fills the form, hits eligibility gates, gets a tracking link
2. **Director review** → multi-handler workflow, status changes flow to webhooks + EventBus
3. **Accepted → joined → tracked** → corp-join detection completes the funnel; the human enters the assessment view
4. **Assessment → classification → action** → wallet signals + activity gaps + login signals roll up to a Corp Health verdict
5. **Decline → purge workflow** → reminder ladder + role-strip checklist + history event; departing player's whole roster handled together

Same human, same data model, both faces.

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
