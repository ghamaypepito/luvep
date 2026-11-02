# Add Client Pathway Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans to implement task-by-task. Steps use checkbox syntax for tracking.

**Goal:** Add any of four pathways to one client without resetting another department.

**Architecture:** A shared ClientPathways service supplies definitions, membership checks, and transactional additions. An authenticated controller exposes scoped JSON options and an Inertia form POST. A shared accessible dialog serves both existing lead profiles and the Immigration case profile.

**Tech Stack:** Laravel 12, React 19, Inertia, Tailwind, PHPUnit, Playwright.

**Spec:** docs/superpowers/specs/2026-11-02-add-client-pathway-design.md

## Global Constraints

- Keep one client/login and existing record associations; no schema changes or dependencies.
- Preserve source department stages, assignments, and history.
- Education starts at New Lead unless staff select another valid stage.
- No real client-data repair, deployment, or newly introduced outbound client messages.
- Use existing role/access and stage/assignee definitions.

## Review Focus

- Implicit English/Immigration membership must not reset progress.
- Unrelated staff and external clients must not retrieve options or mutate a client.
- A replay must not create a second audit/history/notification event.
- Blank assignments must preserve existing values, never replace the shared owner.
- Cross-portal navigation must not direct staff to a forbidden portal.

### Task 1: Scoped transactional pathway addition

**Files:** Create app/Services/ClientPathways.php, app/Http/Controllers/ClientPathwayController.php, tests/Feature/Lead/ClientPathwayTest.php; modify routes/web.php.

**Interfaces:** GET /clients/{lead}/pathways returns pathways with key, label, active, stage, stages, assignee, assignees, default_stage, open_url. POST accepts pathway, optional stage, assignee, note and redirects with success. ClientPathways::canManage(User, Lead): bool; ::options(User, Lead): array; ::add(User, Lead, array): bool.

- [x] Write tests for Immigration Approved Visa → Education Proposal Sent, defaults for all departments, attachment/identity preservation, invalid states, role access, implicit membership, blank assignee preservation, replay safety, and rollback.
- [x] Run php artisan test tests/Feature/Lead/ClientPathwayTest.php; expect failure from missing routes.
- [x] Implement definitions, server validation, source-department access, lockForUpdate and one destination-only mutation. Audit and add optional internal handoff note in the transaction. Notify through existing database channel after commit; reuse configured Immigration conversion automation once. Suppress automatic stage email side effects during initialization and do not infer a fresh visa outcome from a handoff.
- [x] Run the focused tests; expect all passing. Run existing visibility/conversion tests.

### Task 2: Shared Add pathway dialog

**Files:** Create resources/js/components/leads/AddPathwayButton.jsx; modify resources/js/pages/admin/LeadDetails.jsx and resources/js/components/immigration/case-profile/CaseProfileHeader.jsx; create tests/browser/client-pathway.test.mjs.

**Interfaces:** AddPathwayButton({leadId}) fetches scoped GET options when opened and submits the existing POST using useForm. Active pathways show existing progress and a permitted Open pathway link when available; never submit a reset.

- [x] Add component interaction tests for Education Proposal Sent submission, active-pathway behavior, load errors/retry, form errors, pending submission and keyboard dismissal; run and observe missing component failure.
- [x] Implement matching existing neutral form styling, labeled inputs, focus restoration/trapping, Escape dismissal, scroll lock, error announcements, and mobile overflow handling. Replace only the old menu's add actions; retain admin revert controls separately.
- [x] Mount the same action in the case profile, so Immigration staff can perform Emma's transition directly.
- [x] Run node tests/browser/client-pathway.test.mjs and npm run build; expect success.

### Task 3: Verification and review

**Files:** Record completion evidence in docs/superpowers/plans/2026-11-02-add-client-pathway-progress.md.

- [x] Run focused backend, relevant existing department tests, component interactions and production build. Inspect desktop/mobile using synthetic data only.
- [x] Run the design detector once over changed UI. Obtain one independent final code review against spec and diff; fix material findings with regression coverage.
- [x] Check git diff --check and summarize changes, validation and production limitations. Leave the implementation reviewable on codex/add-client-pathway; do not deploy or reconcile real duplicates.
