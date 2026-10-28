# User View and Approval Access Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Give authorized reviewers visible approval navigation and Super Admin an audited real-action user switch.
**Architecture:** Reuse existing approval controllers and module grants. A session-bound target and per-view token select a request-scoped effective user while keeping the authenticated Super Admin unchanged. Global UI provides switching/exit; audit metadata retains both identities.
**Tech Stack:** Laravel, Inertia, React, PHPUnit.
**Spec:** docs/superpowers/specs/2026-10-28-user-view-switch-design.md

## Global Constraints
- Preserve Dinah's user-made Admin role change and all existing grants.
- Never approve or email real client records during verification.
- Original authenticated identity remains the Super Admin; target grants control real actions.
- No request bodies, tokens, or credentials in audit records.

## Review Focus
- Session identity/password hash must survive a switched request and logout.
- A stale tab must not write using a changed target (test token mismatch after switch/exit).
- Inactive/deleted target or revoked Super Admin must end switching (test revalidation).
- A denied page must still offer exit (test error page and control route access).
- Selected review records outside the first queue page must be accessible (test deep links).

### Task 1: Visible approval navigation
**Files:** verification controllers/pages, shared ProposalsAgreements and LeadDetails, new ApprovalReviewLink component, tests/Feature/VerificationDeepLinkTest.php.
**Interfaces:** optional lead_id query selects one accessible queue record, selectedLeadId prop selects panel; ApprovalReviewLink uses auth.modules.
- [x] Add tests for authorized exact-record links and ungranted denial; observe failure.
- [x] Add validated lead_id handling, selected panel, visible grant-aware Review & approve links; retain existing approval handlers.
- [x] Run focused verification tests and build.

### Task 2: Audited switch and UI
**Files:** new UserViewController, ApplyUserView middleware, UserView service, UserViewControls component, userView client helper; bootstrap/app.php, routes/web.php, HandleInertiaRequests, ActivityLog, app.jsx/layouts; tests/Feature/UserViewTest.php.
**Interfaces:** GET /admin/user-view/users; POST /admin/user-view/{user}; POST /admin/user-view/exit. Shared userView {available, active, target, actor, token}. Request attribute user_view_actor. X-Luvep-User-View header matches session token on unsafe requests; token `none` is normal context.
- [x] Add regression tests for grants, audit attribution, unchanged session, stale writes, logout, revocation, errors and exit; observe failure.
- [x] Implement service, routes and middleware; log writes before dispatch and update status. Preserve original guard in finally; block nested/authentication changes.
- [x] Add searchable picker, live-action banner and exit; install same-origin request context headers for Inertia, Axios, fetch and forms.
- [x] Run new tests, existing auth/HR/approval tests, build and browser checks.

### Task 3: Review and release
- [x] Independent whole-branch review; fix important findings and rerun affected checks.
- [ ] Commit, PR, CI and source-drift check; deploy through existing GitHub Actions under existing authorization.
- [ ] Verify live switch/exit and approval visibility without making client changes; report cause, paths and limits.

## Execution evidence
Implemented in the existing isolated branch. Ruling: native execution retained because the user approved implementation and the changes share authorization plumbing; one independent reviewer checked the whole diff. All reported findings resolved. 90 focused tests / 655 assertions and browser fake approval passed; subsequent unlinked-client regression passed. Local PHP8.5 emits four dependency deprecations; production/CI uses PHP8.3. No new database migration.
