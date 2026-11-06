# Ep Workspaces Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans task-by-task.

**Goal:** Add the approved private chat and read-only Manager interfaces on the deployed foundation.
**Architecture:** Existing Inertia role shell; a shared chat component; new SQL-scoped read service for Manager; reversible explicit pilot gate. No new tables or provider transport.
**Tech Stack:** Laravel12/PHP8.3, React19/Inertia, existing CSS and Lucide icons.
**Spec:** `docs/superpowers/specs/2026-11-06-ep-workspaces-design.md`, `docs/ep-agent/readiness.md` and the already approved preview.

## Global Constraints

Preserve ownership and impersonation denial. Paid generation off. Default UI/module flags off. No automatic messages or operational writes. Client/personnel fields are allowlisted. Use actual authorized data, no synthetic production records. Do not alter existing assistant for non-pilot staff. Deployment authorization persists; pilot activation awaits the pending audience answer. Existing private history is paginated and bounded. No browser storage of private chat content.

## Review Focus

- Permission changes during asynchronous requests must clear stale content and deny subsequent access.
- Compact/full view must retain conversation identity and drafts must not be silently discarded.
- Manager aggregates and personnel detail may not disclose records outside the caller's current SQL scope.
- Unknown timestamps and unmapped document requirements must not become invented alerts/counts.
- Module/UI/pilot flags and user-view must preserve existing navigation and block new surfaces appropriately.

### Task 1: Scoped read APIs and pilot access

Files: `app/EpAgent/Pilot.php`, `ManagerService.php`, `Access.php`; `app/Http/Controllers/Api/EpManagerController.php`; `app/Http/Controllers/EpWorkspaceController.php`; `routes/ep-agent.php`; `config/ep_agent.php`; `tests/Feature/EpAgent/WorkspaceTest.php` and `ManagerTest.php`.
Interfaces: `Pilot::state(?User): array` returns enabled/manager/portals; `Access::leads(User,string): Builder` applies mapped portal/department/assignment/grant predicates before selection. `ManagerService::overview(User,string,?int): array` returns scoped counts and paginated records; client/personnel methods reauthorize before selecting allowlisted fields. Manager role admins or immigration_manager (latter Immigration only), without extra assignment bypass. Routes `/ep-agent`, `/ep-manager`, `/api/ep-agent/manager/...` use current session/CSRF and private responses.
- [ ] Write tests first: flags/pilot defaults, external/inactive/impersonation denial, owner context, SQL-scoped counts/records, cross-portal denial, personnel reachable only via accessible assignments, no secrets/doc bodies, stale vs unknown timestamps, bounded pagination.
- [ ] Run failing tests; implement minimal scoped services/routes; run tests green and existing Ep regressions.
- [ ] Commit scoped read APIs/pilot gates.

### Task 2: Private full/compact conversation UI

Files: `resources/js/lib/epAgentClient.js`, `components/ep-agent/ConversationPanel.jsx`, `EpDock.jsx`, `pages/ai/EpWorkspace.jsx`, `resources/css/ep-agent.css`, frontend interaction tests.
Interfaces: shared component accepts initialConversationId, portal, leadId, embedded, callbacks; sends user message to existing append endpoint then creates blocked run only if requested, visibly explains no reply generated. Client uses current XSRF cookie helper and same-origin credentials, no-store GET, AbortSignal. Separate history and message cursors use ids. Never represent local optimistic content as saved. Request identities/abort guards prevent stale threads overwriting selection. Portal/context immutable after creation. Expand shares same conversation id, disabled while saving or draft exists.
- [ ] Write interaction/client tests first for auth/CSRF, failed save preserving draft, race cancellation, unmount/revocation clearing, history/resume, archived state, blocked-run notice and no fabricated assistant text.
- [ ] Run RED; implement shared green-styled interface with history, header/context, private status, clear paid-off state, retry/error/empty/loading states, keyboard access and bounded history.
- [ ] Run green interaction tests and build; commit private UI.

### Task 3: Manager and reversible navigation

Files: `pages/ai/EpManager.jsx`, `components/ep-agent/QuickDetails.jsx`, shared CSS, `HandleInertiaRequests.php`, `components/ai/AssistantDock.jsx`, `components/layout/DashboardLayout.jsx`; frontend interaction and middleware tests.
Interfaces: auth.ep_agent = Pilot state, no private contents in shared props. Eligible users use EpDock on existing open event, others retain exact old dock. Manager link only managers in pilot. Manager Today/Attention/Team/Ask render real API data and client/personnel dialogs, source context from record id and portal. Ask links scoped private chat without fabricating AI outputs. Refresh on visibility return, cancel on unmount/logout; no hidden polling.
- [ ] Write failing tests for pilot vs existing navigation, Manager role gating, tab/portal selection, accessible dialogs/error recovery, safe source links and mobile controls.
- [ ] Implement and run green tests; inspect desktop/mobile screenshots in one batch, fix confirmed issues once, run detector; commit.

### Task 4: Verify, review and release

- [ ] Run full PHP suite and current frontend regression/build checks, compare existing warnings; review changed React components with React best-practices guidance.
- [ ] Perform one fresh independent whole-branch review; fix significant findings with reproducing tests, then rerun affected/full checks.
- [ ] Document exact scope and activation, preserve current main, commit/push PR, wait CI. Deploy disabled unless audience approval explicitly permits a pilot. Retain paid-off state.
- [ ] Verify live source, flags, routes/health and no provider calls; report concrete entry points and remaining unavailable capabilities.
