# Education Report Period Insights Implementation Plan

> **For agentic workers:** Use test-driven development. Backend and frontend may run independently against the contract below, followed by one fresh whole-branch review.

**Goal:** Deploy the user-approved Education report with six filters and reliable event-based insights while keeping its visual structure.

**Architecture:** ReportPeriod resolves NZ calendar windows; EducationReportService aggregates existing Lead stage history and lead status audit events. EducationController keeps authorization/error handling and returns Inertia props. React renders server-computed data, without prototype data or routers.

**Tech Stack:** Laravel 12/PHP, React 19/Inertia, existing Tailwind/Urbanist/Lucide, PHPUnit/JSDOM.

**Spec:** `docs/superpowers/specs/2026-11-02-education-report-design.md`

## Global Constraints

- Preserve the approved original report structure, blue/purple theme and 12px filter labels.
- Dated metrics and their lists must share events, scope and dates. All retains undated current records.
- No schema/data migration, fabricated historical dates, external messages or added dependencies.

## Review Focus

- NZ midnight/DST and Monday/year boundaries, offset timestamps and equal-calendar-length comparisons.
- Student progression, repeat entries, department cross-contamination, malformed and missing events.
- External portal grants, inactive accounts, pure immigration records and unknown current stages.
- Filter changes and live refresh preserve valid UI selection/drafts without mixing stale data.
- Empty periods and unavailable program/audit history must not masquerade as current or zero-change totals.

## Backend/frontend contract

Retain `range,pipeline,register,totalRegister,movements,departments,programs,conclusion,generated_at,generated_by` props.
`range`: preset/from/to/days/label/timezone/all/compare/bucket_days/bucket_label/previous{from,to}.
`departments[key]`: total, register_total, activity_total, event_count, scope(`current`|`activity`), stages[{stage,count,moved,clients}], insights.
`clients`: id,name,ref,school,date (ISO/null),moved; no raw history.
`insights`: metrics[{key(stage string),label,count,previous_count,points[{from,to,previous_from,previous_to,count,previous_count}]}], largest{stage,count,share}|null, active_students, register_total, missing_stage, missing_history.
`programs`: total/published/draft (numbers only under All; null otherwise), history_available(false), scope.
`pipeline_history`: source/notice. `conclusion`: auto/note/note_key/stats and labels for the four existing mini-stats.

## Tasks

- [x] Write backend regressions first: date presets and NZ boundaries, history counting/dedup/progression, All unknowns, lists/charts equality, audit-derived pipeline, legacy URLs, strict custom validation and report/note access. Observe failures.
- [x] Implement `app/Services/Reports/ReportPeriod.php`, `EducationReportService.php` and thin controller integration; run focused tests. Preserve earlier current-register tests by explicitly requesting All.
- [x] Extend `resources/js/pages/portal/education/Reports.jsx` and add `EducationReportInsights.jsx`; write component interaction regressions before implementation, using synthetic transports only. Preserve date selections on polling and current layout.
- [x] Run full PHP suite, all frontend checks and production build. Perform bounded browser verification and fresh code review. Resolve material findings.
- [ ] Commit/push PR, attach PR, wait for GitHub validation, merge exact tested SHA, monitor production deployment and verify real live filters/props without writing client records or sending notifications.

## Rulings

- This live release targets the approved Education Reports screen, including its three department tabs. Other department dashboard redesigns are outside this approved preview.
- Existing pipeline has no dedicated sales stage-history entries; use recorded `lead.updated` status diffs from ActivityLog, not updated_at. Initial stage dates are not inferred.
- User's explicit approval and deployment request authorizes execution and release; no repeated plan/merge approval is required.

## Release evidence

- Full local PHP suite: 1,574 tests, 9,218 assertions; final targeted report suite: 21 tests, 179 assertions after refresh-recovery regression. Existing PHP/PHPUnit deprecation notices remain.
- All frontend checks and 19 JavaScript tests passed; production build and scoped Pint passed. Existing unrelated duplicate-key/chunk-size build warnings remain.
- Actual report components checked through CUA at desktop and 390px width with explicitly synthetic fixtures. Six presets, dated insight labels, All snapshot/program scope and responsive chart layout verified.
- Fresh whole-branch review and focused re-review resolved both refresh failure/recovery findings.
- Production release and read-only live verification follow the PR validation gate; no client data writes or backfills are required.
