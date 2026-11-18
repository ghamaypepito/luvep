# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

ePathways — a New Zealand education & immigration consultancy platform. Laravel 12 backend, React 19
frontend rendered through Inertia.js (no separate API/SPA split — controllers return Inertia page
components). Public marketing pages + lead/assessment/booking funnels, an authenticated `/admin` area,
seven staff portals at `/portal/<role>`, an external client portal at `/portal/lead`, and a
no-login client tracking surface at `/track/{code}`.

## Commands

```bash
# Full dev environment (server + queue worker + log tailer + vite, all in one):
composer run dev          # = php artisan serve + queue:listen + pail + npm run dev

# Or run pieces individually:
php artisan serve         # app on http://localhost:8000
npm run dev               # Vite dev server (HMR)
npm run build             # production asset build (PR CI also runs the full PHP suite)
php artisan queue:listen  # required for AI analysis, campaign/email sends, Drive push, findings
php artisan schedule:work # required for campaigns, reminders, reply sync, findings sweep

# Tests
composer test             # config:clear + php artisan test (PHPUnit, sqlite :memory:)
php artisan test --filter ProgramControllerTest        # single test class
php artisan test tests/Feature/Immigration             # a directory
php artisan test tests/Feature/FreeAssessmentTest.php  # single file
npx playwright test                                    # e2e (needs app running on :8000)
npx playwright test e2e/free-assessment.spec.ts

# Lint / format
./vendor/bin/pint         # PHP formatter (Laravel Pint) — no JS linter configured

# DB
php artisan migrate
php artisan db:seed --class=AdminSeeder    # creates admin login from ADMIN_SEED_EMAIL / ADMIN_SEED_PASSWORD env vars
php artisan migrate:fresh --seed
```

Local DB uses the `mysql` driver (`DB_CONNECTION=mysql`, db name `epathways`); copy `.env.example` to
`.env` and `php artisan key:generate`. Tests force sqlite in-memory + `QUEUE_CONNECTION=sync` +
`MAIL_MAILER=array` via `phpunit.xml`, so they don't touch your dev DB, queue or mailbox.

**DB engine differs local vs prod, and it bites.** Dev machines commonly run **MariaDB** (lenient) while
production is **MySQL** (strict about InnoDB's ~8126-byte in-row limit). The **`leads` table is a
200+ column "god table" already at that limit**, so *adding a `VARCHAR` column to `leads` passes locally
but fails in production with `SQLSTATE 1118 "Row size too large"`*. Before adding any column to `leads`:
prefer `TEXT` (BLOB types are exempt from the 8126 limit) or `JSON`; if a real `VARCHAR` is needed, free
space in the **same atomic `ALTER`** (`ROW_FORMAT=DYNAMIC` + convert some large non-indexed columns to
`TEXT`) rather than a sequence of ALTERs (each intermediate step fails on an over-limit table). Migrations
that touch `leads` are MySQL-guarded (sqlite tests skip the row-format work). See
`docs/refactors/leads-table-split.md` for the long-term plan to split the table.

PR validation runs the complete PHP regression suite plus frontend checks. Keep fixtures aligned with
the current client-portal, checklist, template-sharing and manual-email contracts; see
`docs/regression-contracts-2026-10-28.md` for the resolved legacy expectations. Do not skip failures or
restore superseded behavior to make an old assertion pass.

Useful diagnostic/maintenance commands (`app/Console/Commands/`) — several are the fastest way to
reproduce a scheduled behaviour by hand: `immigration:evaluate-findings`,
`immigration:licence-expiry-check`, `email:sync-replies`, `campaigns:dispatch-due`,
`bookings:dispatch-due-reminders`, `events:dispatch-due-emails`, plus `case:docs`,
`immigration:diagnose-checklists`, `immigration:findings-preview`, `immigration:licence-audit`,
`documents:privatize`, `gdrive:sync-approved`, `ep:check-mail`, `ep:search`, `ep:test-sms`,
`ep:test-notification`, `zernio:profiles`.

## Architecture

### Request flow (Inertia)
- Routes in `routes/web.php` (~1500 lines — the whole app's routing lives here) either
  `return inertia('pages/SomePage', [...props])` directly for static pages, or call a controller that
  does `Inertia::render(...)`.
- `app/Http/Middleware/HandleInertiaRequests.php` shares into every page's props:
  - `auth.user` and `auth.ai_enabled` (true only when `config('ai.enabled')` **and** the DB toggle
    `Setting::get('ai_enabled')` are both on — the two-key kill switch, see `AIService::isEnabled()`).
  - A wide **flash** bag: `success`, `error`, `lead_id`, `intake_id`, `review_success`, `review_id`,
    `edit_link_url`, `edit_link_intake_id`, `invitation_link`, `invitation_link_lead_id`,
    `generated_credentials`, `import_summary`, `draft_saved`, `draft_id`, `intake_submitted`.
    **Form-submit → redirect-with-flash → frontend reads `usePage().props.flash`** is the canonical
    pattern; `resources/js/components/ui/FlashToaster.jsx` turns these into sonner toasts.
  - `contact` (phone/whatsapp/messenger/facebook/email from `config('services.contact.*')`) for the
    sticky CTA, floating contact, and footer.
  - `sidebarBadges` — a lazy closure returning badge counts only for the portal whose URL prefix
    matches the request (avoids running sales queries on the lead portal etc.).
  - `notifications.unread_count` — drives the topbar bell across every portal, re-shared on every
    Inertia navigation so it stays fresh without polling.
- Frontend entry is `resources/js/app.jsx`. It auto-resolves page components from
  `resources/js/pages/**/*.jsx` by the string passed to `inertia(...)`, and **auto-wraps pages by
  path prefix** (`PORTAL_LAYOUTS`): `admin/*` → `AdminLayout`; `ai/*` → `RoleLayout` (role-agnostic
  pages that adopt the signed-in user's chrome); `portal/{sales,education,english,immigration,
  accommodation,finance,agent,lead}/*` → the matching layout. Adding a portal = new layout import +
  new map entry. The resolver throws a diagnostic error naming the missing page path rather than the
  cryptic Inertia `undefined.default`.
- Global UI mounted once at the app root: `FlashToaster`, `FloatingContact`, `MobileStickyCTA`, and a
  sonner `<Toaster>` — public pages render their own `<Navbar />` / `<Footer />`; portal pages get
  chrome from their layout.
- `routes/api.php` has only `POST /api/chat` (Gemini chatbot) and the Sanctum `/api/user`.
  **Everything else that looks like an API lives in `routes/web.php` on purpose**, so it inherits
  session auth + CSRF that Inertia POSTs already carry: `/api/ai/*`, `/api/tasks/*`, `/api/search`,
  `/api/notifications/*`, `/api/sync-calendar`. CSRF-exempt endpoints are listed in `bootstrap/app.php`:
  `/api/sync-calendar`, `/api/chat`, `/stripe/webhook`, `/webhook/zernio`.
- Global middleware appended to the `web` group: `HandleInertiaRequests` then
  `EnsureNotInMaintenance` (super-admin maintenance window over the public site; the maintenance
  admin routes are on its always-allow list so you can't lock yourself out). Aliases registered:
  `portal` → `EnsurePortalAccess`, `tracker.enabled` → `EnsureTrackerEnabled`.

### Auth, roles, and portals
`User` has a single `role` column — one account, one role, no multi-role assignment.
`docs/USERS_AND_FEATURES.md` is the authoritative "who can do what" reference.

| Role | Rank | Lands on |
|---|:--:|---|
| `super_admin` | 100 | `/admin/super-dashboard` |
| `admin` | 80 | `/admin/dashboard` |
| `immigration_manager` | 50 | `/portal/immigration/dashboard` |
| `immigration_adviser` | 20 | `/portal/immigration/dashboard` |
| `User::PORTAL_ROLES` = `sales`, `education`, `english`, `immigration`, `accommodation`, `finance`, `agent` | — | `/portal/<role>/dashboard` |
| `lead` (external client) | — | `/portal/lead/dashboard` |

- Route protection is `auth` + **`portal:<role>[,<role>...]`** (`EnsurePortalAccess`). Multiple roles
  act as OR. `User::canAccessPortal()` lets admins/super-admins satisfy every `portal:*` check, and
  maps `User::IMMIGRATION_ROLES` (manager + adviser) onto the `immigration` portal. **Exception:**
  `portal:super_admin` is an exact-role check — plain admins are kept out (Super Dashboard,
  Maintenance Mode).
- `isAtLeast('admin')` compares the numeric `ROLE_RANK`; department roles have no rank (0).
- `templateDepartment()` resolves which department's message templates a user manages (null = admin,
  manages all + the shared/global set).
- **`EnsurePortalAccess` is role-level only — it does not scope rows.** Controllers must re-check
  ownership themselves (e.g. Case Profile hard-404s a non-case lead; document downloads re-check
  role + lead ownership before streaming). Several controllers were audited and found to lack a
  record-level check — see the AI Agent constraints below.
- Some `/admin/...` URLs are shared with the owning department (e.g.
  `/admin/immigration/resident-intakes` is `portal:admin,immigration`; lead-document staff writes are
  a wide `portal:admin,sales,education,english,immigration,immigration_manager,immigration_adviser,accommodation,finance`
  group). The immigration portal sidebar deep-links into `/admin/immigration/...` for historical reasons.
- A `foreach (User::PORTAL_ROLES)` loop in `routes/web.php` generates the per-department
  `/portal/<role>/email-templates/*` routes from one `MessageTemplateController` — add department
  features there rather than duplicating route blocks.

### Backend domains

**Leads pipeline (the spine).** `LeadController` + `Lead` (uses the `LogsActivity` trait → everything
surfaces in `/admin/activity-logs`). Nearly every other domain hangs off a `Lead`: sub-resources
`LeadNote`, `LeadTag` (free-form, auto-created), `LeadTask` (+ attachments/comments), `LeadDocument`.
Conversions (`convert-to-student`, `convert-to-case`, `convert-to-accommodation`, each reversible) all
funnel through `LeadController`. Bulk CSV import dedupes by email or name+phone and flashes
`import_summary`. `app/Traits/BuildsLeadRow.php` builds the shared lead/case row payload — custom row
payloads (Cases, Students) must explicitly include fields the shared row menus read (e.g.
`portal_invitation_status`); they don't come through automatically.

**Intake funnels.** Free Assessment (`LeadController::storeFreeAssessment` + `LeadEducationExp` +
`LeadStudyPlan`) queues `AnalyzeLeadAssessment` → `CerebrasService` returns an eligibility-score JSON
into `leads.ai_analysis` with `ai_analysis_status` (`processing`/`completed`/`failed`).
`CerebrasService` deliberately does **not** use `response_format: json_object` and instead
extracts/validates JSON from the response (small models misbehave with that flag — see
`docs/deployment.md`). `QuickLeadController` is the inline hero/exit-intent/footer capture.
Visa intakes: `ResidentIntakeController` (richest, with token-based edit links at
`/resident-interest/edit/{token}`), plus `WorkIntakeController`, `StudentIntakeController`,
`VisitorIntakeController`. Events (`EventController` + `Event` + `EventSession`) and
`/register?ref=AGT-XXXXXX` agent referrals also create leads.

**Immigration cases.** The largest domain — **read `docs/immigration-architecture.md` before touching
it.** A case *is* a `Lead` with `is_immigration_case = true`, moved through `IMMIGRATION_STAGES` with
`immigration_priority`/`immigration_assignee`/`stage_history`. Key pieces:
- `Portal\ImmigrationController` (portal pages, case CRUD, inline stage/visa/priority),
  `Immigration\CaseProfileController` (Case Profile + financials/payments),
  `Immigration\AgreementController` (managed agreements), `VisaTypeController` (catalogue).
- **Two coexisting agreement systems** — the older *engagement pack*
  (`LeadDocumentController::generateEngagement` + `EngagementDocumentGenerator`, Blade→dompdf, stored
  as `LeadDocument` with `source_variant=engagement:*`) and *managed agreements* (`Agreement` +
  `AgreementService`, generate→send→sign→void on the Case Profile). Check which surface you're on.
- **Fees are GST-exclusive in the DB and totals are derived, never stored.** `VisaType` prices on two
  axes — location (onshore/offshore) × tier (discounted/normal) — via
  `professionalFeeFor($tier, $location)` / `feeBreakdown($location)` at `GST_RATE = 0.15`; the INZ
  application fee is a single shared government charge.
- Build 12 collaboration layer: `CaseThread`, `CaseStepTemplate`/`CaseStepState`, `CaseFinding`
  (+ `CaseFindingRun`), `CaseAttestation`, `CaseView`/`CaseAuditView`, `CaseFinancial`/
  `CaseFinancePayment`. Findings are a rule engine — `app/Services/Immigration/Findings/Rules/*` each
  implement `FindingRule`; `EvaluateCaseFindings` runs them on write and nightly at 04:30 NZ.
- INZ form filling: `InzForm`/`InzFormVersion` + `InzFieldExtractor`/`InzFormFiller` (fpdm/fpdi/fpdf
  + smalot/pdfparser).

**Lead documents.** `LeadDocumentController` + `LeadDocument`/`LeadDocumentRequest` — the
cross-department document engine: staff request docs, leads upload via `/portal/lead/documents` or
`/track/{code}`, staff review (`Submitted`/`UnderReview`/`Approved`/`Rejected`/`StaffShared`).
Generation is Blade view → dompdf → attached as a `LeadDocument` with `source='generated'`
(`generateDocument` types: `proposal | consultancy_single | consultancy_partner | english_engagement`;
plus engagement pack and tax invoice, each with a live HTML preview route that skips dompdf).
Approved documents can be pushed to Google Drive (`PushApprovedDocumentToDrive`). Downloads always
re-check role + ownership inside the controller before streaming from the private disk.

**Client-facing surfaces.**
- `/track/{code}` (`LeadTrackingController`, `throttle:tracker` + `tracker.enabled`): no auth — the
  `tracking_code` is the bearer credential. Clients see status/timeline/checklist, edit a tightly
  scoped allow-list of fields, upload documents, e-sign the written agreement, and pick a program
  from the staff shortlist. Client uploads stream through `streamUpload()` from the private disk
  (public URLs here were a Privacy Act exposure).
- `/portal/lead` (`LeadPortalController`): logged-in client portal. Access is
  request→approve→credentials: `LeadPortalInvitationController` (sales requests, admin
  approves/rejects/revokes, admin "generate credentials" flashes `invitation_link` +
  `generated_credentials` once), then `/lead-portal/setup/{token}` with no auth middleware.

**Accommodation.** `Portal\PropertyController` + `Property`/`PropertyImage`, `TenantController` +
`Tenant` (lifecycle: notice → vacate → renew → move, archive/restore with `withTrashed`),
`EoiSubmissionController` + `EoiSubmission` onboarding pipeline (URL is `/onboarding`, route names
stay `applications.*`), viewings, `RentPayment`/rent-utilities, payment schedule, gas delivery,
`Concern`, and a `CalendarController` consolidating events from several sources. Some sidebar
sections are still `Placeholder` stubs.

**Education / English.** `Portal\EducationController` (students, `School` catalog),
`ProgramController` + `Program` (public program-levels/details/fee-guide + admin CRUD; migrations
reshape `entry_requirements`/`employment_outcomes` into structured "sections" JSON),
`ProgramPromoController` + `PromoFeed` (time-bound campaigns on Home/Journey/Programs; banners in
`storage/app/public/promos`). `Portal\EnglishController` + `EnglishClass`/`EnglishClassEnrollment`/
`EnglishAssessment` for learners flagged `is_english_student`.

**Communications.** `CommunicationService` is the single door for outbound lead messaging: resolve a
`MessageTemplate` by key (department version preferred, falling back to shared/global), fill
`{{variables}}`, route to email and/or SMS, and write a `MessageLog` per send. SMS goes through the
`SmsProvider` contract (`TwilioSmsProvider` / `BrevoSmsProvider` / `NullSmsProvider`). Around it:
`BulkEmailController` + `EmailCampaign` + `SendCampaign`, `EmailReplyController` + `EmailReply` fed by
`email:sync-replies` (IMAP, every 5 min), `Sales\ComposeMessageController`, `LeadMessageController`
(manual per-lead sends), `TemplateFolder` grouping, `EmailFooterComposer` branding. Stage-change
emails are config-driven — `config/stage_emails.php` maps a `Lead::STAGES` value to a template key and
`SendStageTransitionEmail` re-checks the lead's current stage before sending after a small delay.

**Email automation** (admin/super-admin, `/admin/email-automation`). `EmailEventRegistry` is the
catalogue of fireable events per department (named events like `immigration.engagement.sent`,
`immigration.invoice.paid`, plus one generated **per case stage**, keyed
`immigration.stage.<Str::slug(stage,'_')>`); admins attach `EmailAutomationMessage` rows (recipient +
template + channel, OFF by default) via `Admin\EmailAutomationController`. `EmailAutomationService::fire($key,$lead,$ctx)`
is the single door — a safe no-op unless a message is enabled, it returns whether it sent to the **client**
so callers can implement **single-source** sending (fire the automation; only fall back to the built-in
email when no client message is configured — this avoids the double-send that bit engagement/document
flows). Fire points are wired across `Lead::advanceImmigrationStage`, `ImmigrationController::updateCaseStage`
(BOTH the process-chain path **and** the legacy path — a stage change that misses either path sends
nothing), `LeadDocumentController` (document requested/approved/rejected, invoice paid), and the
engagement/signing/outcome flows. Template variables resolve in `CommunicationService::buildContext`
(the map that fills `{{first_name}}`, `{{visa_type}}`, `{{invoice_number}}`, …); `password`/`signed_at`/
`status_detail` only fill when the calling action passes them in `$ctx`.

**Bookings & calendar.** `BookingController` + `Booking` (+ `BookingReminder`, `StaffAvailability`,
`AvailabilityRule`, `SlotGenerator`, `CalendarEvent`). Optional Stripe payment step for paid
consultations (`PaymentController`, webhook at `/stripe/webhook`). `GoogleCalendarService` +
`CreateBookingCalendarEvent`; `SyncController` (`POST /api/sync-calendar`, `X-Sync-Token` header ==
`CALENDAR_SYNC_TOKEN`) lets an external Google Apps Script push confirmed times back onto bookings.

**Cross-cutting.** `TaskController` (cross-portal Task Board API under `/api/tasks`, handles
department/personal tasks with no `lead_id`), `SearchController` + `SearchService` (Cmd+K global
search across leads/tenants/properties/programs/schools/classes/applications/bookings, role-gated
inside the service), `NotificationController` + Laravel database notifications (`app/Notifications/`),
`SystemTicketController` (support tickets + per-role "My Tickets"), `SettingController` + `Setting`
(DB key/value toggles), `Admin\MaintenanceController` + `MaintenanceMode`,
`Admin\SuperAdminDashboardController`, `UserReviewController` (public submit + per-department
moderation + a unified `/admin/user-reviews`), `FacebookLiveController`, `NewsFeedService` (hourly
cache warm for the public immigration page).

**AI surfaces.** Four distinct integrations — don't conflate them:
- `config/ai.php` + `AIService`/`AiChatService`/`AiRecordContext` — staff CRM assistant (OpenRouter,
  default `google/gemini-2.5-flash`). Topbar chat panel + `/assistant` page (`AiAssistantController`;
  `?subject_id` scopes it to a record, authorised in the controller) + JSON endpoints under
  `/api/ai/*` (`AiChatController`, `AiLeadAnalysisController`, `AiCaseAnalysisController`), persisting
  `AiConversation`/`AiMessage`/`AiRecordAnalysis`. Gated by the two-key kill switch above.
- `CerebrasService` — free-assessment eligibility scoring and ad-copy brainstorming.
- `ChatController` (invokable, `/api/chat`) — public Gemini chatbot with an ePathways system prompt.
- `AiAdController` (PLAI Partner API, **dormant until `PLAI_API_KEY` is set**) and the Social MVP
  (`AiAdsWebhookController` under `/webhook/social/*` + `ZernioService`) — falls back to stub data
  when unconfigured so `pages/admin/social/*` can be built in isolation.

### Frontend conventions
- React 19 + Vite 7 + Tailwind CSS v4 (via `@tailwindcss/vite`, configured in CSS, not a JS config).
  UI libs: `flowbite-react`, `framer-motion`, `lucide-react`, `swiper`, `sonner`, `@dnd-kit`,
  `@tiptap` (rich-text template editor), `react-day-picker`, `react-signature-canvas`, `xlsx`.
- Path aliases: `@` → `resources/js`, `@assets` → `resources/assets` (`vite.config.js`).
  **Linux CI is case-sensitive** — match the real `resources/assets` casing exactly in imports, or
  `npm run build` fails on GitHub Actions while passing on Windows.
- **Tailwind v4 JIT needs literal class strings** — no dynamic class concatenation.
- `pages/` = full Inertia pages; each marketing page (e.g. `pages/home/HomePage.jsx`) composes section
  components colocated in the same folder. `components/layout/` = Navbar/Footer + the nine
  admin/portal layouts plus `RoleLayout` (picks the layout matching the signed-in user's role);
  `components/ui/` = shared widgets; `components/immigration/case-profile/` = the Case Profile tabs.

### Config / external services
Read external creds via `config('services.*')` / `config('ai.*')`, **never `env()` in app code**
(config is cached in deployed envs — `env()` returns `null`). `config/services.php` holds `cerebras`,
`openai`, `openrouter`, `gemini`, `stripe`, `twilio`, `brevo` (SMS), `google_drive`,
`google_calendar`, `calendar.sync_token`, `plai`, `social` (n8n webhook), `zernio`, `booking`
(fee/currency/timezone), and `contact.*` (public channels). Queue/cache/session default to the
`database` driver locally, `redis` in production.

Several behaviours are **config-driven, not hard-coded** — change the config, not the call sites:
`config/immigration.php` (IAA licence warning thresholds, case-ageing bands),
`config/stage_emails.php` (stage → template key), `config/lead_document_checklist.php`
(**server mirror of `resources/js/data/leadDocumentChecklist.js` — the two must be edited together,
and changing an item `id` orphans previously uploaded files**). Immigration cases use
`VisaType.checklist_items` instead of this general checklist.

### Scheduled work
`routes/console.php`: news-feed cache warm (hourly); due campaigns / event emails / booking reminders
(every minute); IMAP reply sync (5 min); `immigration:licence-expiry-check` (09:00 NZ);
`immigration:evaluate-findings` (04:30 NZ). All `withoutOverlapping()`.

## Deployment

Pushes to `staging` → `staging.epathways.co.nz`, pushes to `main` → `epathways.co.nz`, via GitHub
Actions (`.github/workflows/deploy-*.yml`) which `composer install --no-dev` + `npm ci` + `npm run build`
on the runner and rsync to a single Hostinger VPS. **PR CI runs the full test suite**; also run
`composer test` locally before merging. **`docs/deployment.md` is the authoritative ops/troubleshooting
reference** — read it before touching deploy workflows, server config, file-upload/permissions code, or
the Cerebras integration. Key gotchas it documents: rsync `--delete` will eat
`storage/app/{public,private}` user uploads if not excluded; rsync `-p` wipes `storage` setgid perms
each deploy (post-deploy step re-chmods + re-`storage:link`); `env()` returns null after `config:cache`.

Workflow: feature branch → merge to `staging` (verify) → merge to `main` (production).
`.env` holds live secrets — never recreate or echo it.

## Documentation map

- `docs/deployment.md` — ops, server config, troubleshooting (authoritative).
- `docs/production-config.md` — production env/config reference.
- `docs/USERS_AND_FEATURES.md` — authoritative role/permission/feature matrix.
- `docs/immigration-architecture.md` — the immigration domain end to end; read before working there.
- `docs/immigration/build-12-*.md` — case collaboration brief, open items, release notes, test plan.
- `docs/ai-agent/` — the AI agent layer spec (see constraints below).
- `docs/audits/` — point-in-time audit findings (lead portal, tickets, system bugs, case detail).
- `docs/refactors/leads-table-split.md` — plan to split the 200+ column `leads` table (see the DB note above).

## AI Agent layer — standing constraints

Specification: `docs/ai-agent/` — overview, guardrails, tool registry, capability list, phase 0.
Read `docs/ai-agent/01-guardrails.md` and `docs/ai-agent/04-phase-0.md` before working on anything
in this layer.

**Non-negotiable:**

1. **No AI surface gives immigration advice.** Only the Licensed Immigration Adviser does, under the
   Immigration Advisers Licensing Act 2007. Agent output on immigration matters is internal,
   indicative, and drafted for the LIA to review, adopt and sign. Client-facing surfaces state status
   and process only and escalate anything advisory.
2. **Scope before retrieval.** The caller resolves to a row-level filter applied before the model
   sees any data. Agents and Leads see only their own records. Treat this as a security control.
   **`EnsurePortalAccess` does not provide this** — it is role-level, not row-level. The scope broker
   injects the ownership/department predicate itself and does not delegate scoping to endpoints;
   several controllers were audited and found to have no record-level check.
3. **No privileged bypass.** Agent tools call the same server-side policied endpoints as the UI.
   Advice-bearing content is licence-gated, not role-gated (`AdviceBearingPolicy` /
   `User::holdsCurrentLicence()`): the AI may draft an advice-bearing artifact but must **never be
   recorded as its approver** — an AI actor holds no licence, so the `approve` ability fails for it.
   (Build 12 §2 replaced the old "`immigration_adviser` is read-only" rule with this content-level
   control; the human adviser now has full case write. Do not reinstate the role-level rule. See
   `docs/immigration-architecture.md` §2.)
4. **Autonomy levels are binding.** Each capability ships at the level in `03-capabilities.md`.
   L3 = blocks on human approval. L4 = only where the scope is narrow, logged and reversible, and
   never where advice, money or immigration outcomes are involved. Do not raise a level to simplify
   an implementation.
5. **No generated numbers.** Fees, balances and dates come from a tool call against live data, or the
   agent says it does not know.
6. **No fabricated facts.** Assert only what is in the record or a cited source. An empty field is
   "unknown", never a plausible guess.
7. **Audit everything.** One log entry per prompt, retrieval, tool call, approval and override,
   attributable to a user.
8. **Writes are idempotent and reversible**, and carry a `source_ref` where they originate from an
   extracted document value.
9. **Do not build anything marked BLOCKED** in `03-capabilities.md` until its prerequisite lands.
   Treat a RE-SCORE marker as "the Effort estimate is not meaningful", not as a small number.

**Build order:** row-level authorization (+ leaky-controller retrofit) → tool layer + agent-event
audit store → approval queue → eval harness → escalation classifier → consent register and legal
entity model → first capability (`AI-002`). Do not ship an L3 capability before the eval harness and
audit trail exist, and nothing client-facing ships before the escalation classifier.
