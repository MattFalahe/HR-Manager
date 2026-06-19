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
- **Purge workflow** with T-7 / T-3 / T-48 / T-0 reminder ladder, SeAT squad cleanup (Discord roles auto-cascade via seat-connector), and a blinking warning banner urging operators to strip in-game titles + roles before the EVE 24-hour cooldown bites
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

## Discord squad routing

HR Manager can shepherd applicants through SeAT Squads automatically — a `@Prospect` Discord role while their application is open, swapped to `@Member` on accept. Discord role assignment itself stays downstream (warlof/seat-connector reads squad membership and pushes roles), so HR Manager only moves users between squads. HR adds/removes membership through SeAT's own relationship, so the squad's **attached roles are conferred to the applicant** (and stripped on removal) by SeAT's squad observer — that role inheritance is how the applicant picks up any permission the squad carries.

Wired under **Settings → Recruitment squads** (the tab only appears when SeAT Squads is installed). Per-corp table with two dropdowns: Prospect squad + Member squad. Pick from any squad on your install.

**Lifecycle:**

| Status change | What HR Manager does |
|---|---|
| Submit | Add applicant to per-corp Prospect squad → Connector syncs `@Prospect` role |
| Accept | Remove from Prospects, add to Members (if configured) → role swap |
| Reject / Withdraw | Remove from Prospects, no Member change |

**Three setup scenarios** (covered in detail in Help & Documentation → Recruitment Site → Discord squad routing):

| Scenario | When | HR Manager settings |
|---|---|---|
| **A — Auto Members squad exists** | You already have an auto squad rule "user in corp X → Members squad" with Connector pushing the Discord role | Prospect: pick the new manual squad you create. **Member: `(none)`** — let the existing auto squad handle it. |
| **B — Both squads HR-managed** | No auto squad, OR you want the `@Member` role assigned the moment recruiter clicks Accept (before applicant joins corp in EVE) | Prospect + Member: both pointing at manual squads HR Manager populates directly. |
| **C — No Connector / no Squads** | SeAT Connector or Squads framework isn't installed | The Recruitment squads tab doesn't appear. Set the landing's `discord_invite_url` instead — the apply form shows a "Join our Discord server" button as fallback. |

**Letting applicants reach the "link Discord" page:** the SeAT Connector identity page is permission-gated, so a brand-new applicant can only see the link button if they hold the Connector **view** permission. Deliver it through the squad: attach a role carrying *only* that permission to your Prospect squad (SeAT → Squads → the squad's Roles section, needs a SeAT superuser). HR adds the applicant to the squad; SeAT confers the squad's roles to them; the button appears. Keep that role permission-light so applicants don't inherit anything sensitive. HR never attaches roles to squads for you — that's a deliberate boundary (a plugin silently granting permissions via squads is a security smell).

**Common gotchas:**

- Squad type must be **`manual`** or **`hidden`** — `auto` rebuilds membership from rules and would evict the user; `apply` requires user-initiated requests. HR now warns you (Settings picker + diagnostic + runtime log) if a Prospect/Member map points at an `auto` squad.
- Discord role appears within Connector's sync window (typically 30s – few minutes). Squad change is immediate; the Discord push waits on Connector.
- The apply form and the post-application page surface a "Link Discord" button when Connector is installed (the link is auto-derived from your SeAT address). You can also fall back to a plain `discord_invite_url`, or pick the **None** post-submission mode for corps that don't onboard via Discord.
- One alliance Discord, multiple corps? Point every corp row at the same Prospect/Member squad.
- Squad sync failures log a warning but never block the status change. Reconciling drift is operator work.

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
