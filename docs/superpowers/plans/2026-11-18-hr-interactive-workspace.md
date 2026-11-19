# Interactive HR workspace Implementation Plan

> Use superpowers:subagent-driven-development for isolated implementation tasks and independent final review.

**Goal:** Implement the approved interactive HR preview against existing production HR records.
**Architecture:** Extend HR controllers/services and the existing HrLayout. Reuse DTR validation/audits, clock states and permission gates. Add a small versioned acknowledgement record for report content, separate from attendance review.
**Tech Stack:** Laravel, Inertia React, existing CSS, SQLite feature tests, Vite.
**Spec:** docs/superpowers/specs/2026-11-18-hr-interactive-workspace.md

## Global Constraints
HR only; no client/finance writes, no permission grants, no synthetic production records, no outward messages. User authorized implementation/deployment in this chat. Preserve clock and review invariants, concurrency protection, read-only preview, and role scopes. Taste Vault searched before implementation: no matching reference; use approved preview.

## Review Focus
Unauthorized own/team access; stale/duplicate saves and acknowledgements; clock corrections accidentally editable; removed employees in roster; mobile overflow and grouped navigation reachability.

## Tasks
- [x] Backend: versioned report-content edits + acknowledgements, per-owner/Head access tests, current-version conflict tests. Contract: GET /dtr/changes?scope=mine returns hr/DtrChanges with mode=mine, entries (DtrService rows), departmentHead {id,name}|null, acknowledgements [{id,dtr_entry_id,report_version,status,reason,changes,submitted_at,decided_at,comment,head_name,employee_name}]. Default existing team route returns records plus acknowledgements scoped to assigned Head/admin. POST /dtr/{entry}/report saves {expected_version,work_date,work_location,tasks,meetings,people_assisted,blockers,tomorrow,remarks}; POST /dtr/{entry}/acknowledgements submits {version,reason}; POST /dtr/acknowledgements/{acknowledgement}/decision {version,decision:acknowledged|clarification,comment}. JSON or redirect as appropriate.
- [x] Shell/roster: grouped personal/team/resources/settings navigation, preview Garden styling/density, staff status page with scoped server data, personal-only overview excluding Admin Task Board; feature/visual checks. Contract route /team renders hr/Team with members [{id,name,department,status:working|on_break|off_shift,started_at,break_started_at,timezone}], asOf. shared hr.can_view_staff_status signals link eligibility. Preserve existing role controls and HR tool routes.
- [x] DTR UI: reuse report fields editor; live before/after sidebar, explicit immediate Save, reason/Head submission, fixed submission version/decision history. User-facing pending acknowledgement wording separate from daily time review.
- [ ] Verify relevant HR regression tests and production build; independent whole change review; merge/deploy existing workflow; observe live HR pages/read-only data and logs. Never test clock transitions or real HR writes on staff accounts.

### Shell/roster validation
- PHP 8.3 feature run: HrDashboardTest, HrTeamTest and HrWorkInsightsTest passed, 177 assertions (15 existing missing-.env warnings in this isolated checkout).
- `node tests/Frontend/hr-team.cjs` passed: clock-state/search filters, visible-only bounded refresh, no overlapping requests, network-failure retention, and polling cleanup.
- Personal dashboard no longer requests or serializes Admin Task Board work insights or team availability/review counts; team tools are grouped under Team workspace.
- Shared navigation regression fixed by reusing the fresh permission/employee snapshot in staff-status eligibility; HrNavigationPerformanceTest plus HrTeamTest passed with the original 6-query bound, 106 assertions. Permission revocation remains covered.

## Verification before merge
- 188 HR and legacy DTR feature tests completed, 1,306 assertions, no failures (isolated checkout warns about missing .env). PHP 8.3.
- Frontend roster and report workflow interaction checks pass; production Vite build passes.
- Browser synthetic flow: employee edits location/notes, sees before/after, saves, submits reason to assigned Head, Head acknowledges; saved report and attendance status remain separate.
- Staff roster mobile viewport 390px has no document overflow; desktop Garden and Split density verified.
- Independent broad review identified historical-task preservation, save-in-flight input loss, reason reset, and attendance-only supersession issues; all fixed and rechecked. Historical task details remain read-only in this content editor; other report fields remain editable.
- Ruling: Department Head inbox is the submission surface; no outward messages or automatic permission grants.
- Ruling: Garden is default, local Split density preserves the alternative approved preview without affecting other portals.
