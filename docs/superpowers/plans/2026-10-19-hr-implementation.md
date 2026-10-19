# Luvep HR Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver the approved Luvep employee and HR portals, including shared sign-in, private employee records, live attendance, leave, Manager/Admin notifications and read-only employee preview.

**Architecture:** Extend the existing Laravel/Inertia application and production database. Implement three separately testable releases on a feature branch; retain legacy DTR records and main-site behavior. Permissions accompany every feature rather than being deferred until the administration screen exists.

**Tech Stack:** PHP 8.3, Laravel 12, React 19, Inertia 2, existing Tailwind/Vite, MySQL production and SQLite test database, existing Redis queue and Laravel notifications.

**Spec:** [Approved HR design](../specs/2026-10-19-hr-portal-design.md)

## Global Constraints

- Repository: `ghamaypepito/luvep`; do not modify the unrelated `epathways-pxlcode` project.
- Department Head decides; HR oversees. No second HR approval is required.
- SSO between `luvep.com` and `hr.luvep.com`; unchanged main APP_URL and separate staging sessions.
- One active shift and at most one open break per employee; paused working time during breaks; manual clock-out.
- Reminder at scheduled finish minus 15 minutes; no guessed end time for unscheduled flexi staff.
- Employees see their own authorized records; confidential files default private. Admin employee preview is read-only and audited.
- Notify the assigned Manager and active Luvep Admins through both in-app notifications and email. Use minimal content and authorization-checked links.
- Additive migrations; preserve historical IDs, signatures, tasks and schedules. No production data in local tests.
- Reuse Luvep branding and existing components. No nonfunctional production placeholders.
- Feature branch -> staging verification -> production. Development/staging mail goes to a log/test sink, not staff.

## Review Focus

- Concurrent actions from two devices must not create two open shifts, duplicate breaks, repeated approvals or duplicate application-level notifications: tests in Release 1 tasks 4, 6 and 7.
- Cookie migration, remember-me and logout must remain correct across both Luvep hosts without changing other domains: Release 1 task 3.
- A revoked or archived account, or an admin in employee preview, must not bypass restrictions through old routes, exports or a queued job: Release 1 task 2 and Release 2 task 1 / Release 3 task 3.
- NZ daylight-saving shifts, PH schedules, Saturday work, overnight breaks and unscheduled flexi staff must use real instants and configured workdays: Release 1 tasks 4–6.
- Existing autosave forms, legacy signatures and reports must remain readable without overwriting live-clock records or inventing historic sign-offs: Release 1 task 5.

## Releases and execution order

1. [Employee portal, attendance, leave and notifications](2026-10-19-hr-release-1.md). This includes the admin avatar menu and read-only employee preview.
2. [Private documents, policies and HR requests](2026-10-19-hr-release-2.md).
3. [People operations, reports and access administration](2026-10-19-hr-release-3.md).

Do not publish incomplete navigation. Each release turns on only the completed, verified modules and retains the shared clock/leave/privacy foundations. Release acceptance does not mean the overall task is complete; all three releases implement the approved scope.

## Notification contract

| Event | Manager | Luvep Admins | Employee |
| --- | --- | --- | --- |
| Leave submitted or resubmitted | Assigned Department Head | Active admin/super_admin accounts | In-app confirmation |
| Leave cancelled or decision recorded | Assigned Department Head, excluding actor | Active admin/super_admin accounts, excluding actor | Decision/cancellation confirmation |
| DTR daily/weekly submitted or resubmitted | Assigned Manager and Operations reviewer | Active admin/super_admin accounts | In-app confirmation |
| DTR returned for correction or reviewed | Assigned Manager, excluding actor | Active admin/super_admin accounts, excluding actor | Correction/review result |
| Private HR request opened/updated | Only if assigned and authorized for the case | Generic triage notice; detail only for authorized HR | Own request updates |
| Onboarding/exit task assigned or overdue | Assigned permitted owner/Manager | Generic actionable summary | Own assigned tasks |

All Manager/Admin deliveries use database and mail channels. Deduplicate recipients when Manager, reviewer and Admin refer to the same account. Exclude inactive/deleted accounts and avoid self-notification. Missing or invalid email must not prevent an in-app notification or roll back the employee's submitted record. Missing manager assignment is visible to HR and falls back to Admin triage, not a guessed recipient.

Use generic titles, reference IDs and authenticated links; omit reasons, health information, emergency contacts, attachments, compensation and private case content. An alert never grants permission to its target. Routine clock/break actions do not email Managers or Admins. The employee's 15-minute reminder stays in-app.

The user explicitly approved both notification channels. No actual staff mail is sent during development or test verification. Production delivery begins on real authorized events after release.

## Checkout and runtime preparation

- [x] Complete the independent local clone at `/Users/ghameey/Documents/ChatGPT/luvep` and create `codex/hr-portal`; verify origin and clean status before editing.
- [x] Store the approved spec and plans on the feature branch. Never push planning commits to `main` because it deploys automatically.
- [x] Provisioned local PHP 8.3 and Composer 2. Verification uses the PHP runtime directly.
- [x] Install locked Composer/npm dependencies. Use a disposable local `.env` from `.env.example`, SQLite and log/array mail only; never copy the production `.env`.
- [x] Ran the full PHPUnit suite and saved the named baseline failures and exit status. Baseline: 1,036 tests, 57 failures, 10 errors; final: 1,164 tests with the identical 67 failing/error test names.
- [x] Ran focused HR/auth/profile verification and `npm run build`; full-suite comparison is recorded separately.

## Common test/commit cycle

Each task below follows: write the specified failing behavior tests, run them and confirm the intended failure, implement the named interfaces, rerun the owning tests, then commit the task's scoped files. Run Pint on changed PHP files. Use small commits with descriptive messages; never stage unrelated files or local secrets.

## Overall completion evidence

- All new HR tests pass; full PHP suite compared with baseline and all regressions resolved.
- Frontend production build and browser flows pass at desktop/mobile widths, including SSO and clock/break behavior.
- Permission tests cover direct URLs, altered IDs, previews, files, notifications, exports and both hostnames.
- Fresh production backup, migrations, deployment run, TLS/DNS, queue health and live smoke checks recorded.
- Main website and existing departmental portal checks pass after deployment.
- Actual employee roster, Manager/Operations assignments and restricted-document grants are confirmed before corresponding modules expose data.

## Plan review

The user approved Native execution in this chat with one independent final review. All three releases are implemented on `codex/hr-portal`. The independent review is complete and its findings have regression-tested fixes; see [review evidence](../../hr/review-2026-10-19.md). Production rollout remains pending DNS/TLS, roster/permission confirmation and the live deployment checks in [operations](../../hr/operations.md).

## Self-review record

Reviewed against the approved design on 19 October 2026. Release 1 covers host routing, account/profile fields, employee eligibility, avatar preview, SSO, leave/emergency decisions, attendance/corrections, DTR/sign-offs, calendar, notifications and interface states. Release 2 covers document classification/version/release, policies/acknowledgements and private HR cases. Release 3 covers onboarding/exits, equipment, milestones, scoped exports, permissions and audit. Each release includes direct-access tests and staged verification.

Reconciled actor/subject preview semantics and shared HrAccess/HrEvents interfaces across all three plans. Corrected the shared-session middleware filename and added explicit correction/emergency interfaces. Verified existing DTR view/test paths, Laravel/React dependencies and Playwright test directory against the cloned repository. Planning checks and local implementation verification are complete. Deployment verification is tracked separately and is not yet claimed.
