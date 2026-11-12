# TKT-V4SCMN — Multiple Education Weekly Notes

Priority: High. Proposed status: In review, pending staging acceptance and deployment.
Branch: `codex/v4scmn-multiple-education-weekly-notes`, based on main `eb8802a2`.

## Findings

The screenshot matches the Education Reports Weekly notes card. ReportWeek currently stores a single string per department/week in app_settings; WeeklyReport offers Add note only when empty, then Edit note. Additional content replaces the same value. This is a feature limitation, not lost data or a failing upload. Notes are shared within the authorised Education portal, not personal daily tasks.

## Proposed solution

- Add separate Education weekly entries, each with a stable ID, text, author snapshot and creation timestamp. The card always offers Add note when no entry editor is open, displays each entry separately, and lets its author edit it.
- Preserve the existing single weekly note in place, visible as Previous weekly note with its existing edit action. Do not copy it into the new table or invent an original author/date.
- Preserve existing week selection and Pacific/Auckland boundaries. Notes remain available when this week becomes last week. This request does not extend weekly notes to monthly/custom reports.
- Retried additions reuse their UUID and do not create duplicates. Stale entry edits fail validation instead of overwriting newer text. Independent staff entries use separate rows. Invalid/blank/overlong input and invalid/future week starts are rejected.
- Education report authorisation remains required. Shared readers can see entries; only the entry author can edit the new entries. No delete capability added. The previous shared note retains its existing editing policy.
- Other portals continue their original single-note workflow; no task, client-note activity counting, email or stage logic changes.

## Validation

Local checks passed: 30 backend tests / 519 assertions across EducationWeeklyNotesTest, WeeklyReportsTest, EducationReportTest and EducationReportPeriodTest. These cover multiple entries, legacy preservation, duplicate retry, sharing versus edit ownership, stale edits, week rollover, invalid input, external denial and Finance isolation.

Real React component tests passed for adding multiple entries, retry payload IDs, inline error/draft preservation, author-only edit controls, expected-content checks, refresh draft retention, week switching, retained previous note and unchanged other-portal submission. Existing Education report interaction tests also passed. GitHub build/full regression results are recorded on the pull request; staging remains required.

## Deployment and staging

Requires the additive `education_weekly_notes` migration before serving the new report code. It does not modify existing settings. Do not roll the migration back after real entries exist without first preserving that data; its down migration drops the new table.

On staging with authorised test accounts:
1. Confirm an existing weekly note remains visible and editable.
2. Add two separate entries, reload, verify both texts, authors and NZ timestamps.
3. Verify another authorised Education user can read but cannot edit the author's new entries; verify their own additions stay separate.
4. Edit one entry without changing the others; retain a stale editor in a second tab and verify it cannot overwrite a later saved change.
5. Switch weeks and verify no leakage between them; confirm previous-week notes remain available.
6. Check Task Board counts and another portal's original Weekly Notes still work.
7. Emma confirms the intended workflow. Sir Ghamay reviews, merges and deploys; this branch has not changed production.

## Resolution report draft

Issue summary: Education Weekly Notes allowed only one shared note per week; Emma needs multiple separate entries.

Root cause: One department/week setting and a single-note editor, rather than individual note records.

Resolution: Proposed Education-only entry storage and Add note workflow with author/date display, own-entry editing, retry protection and stale-edit validation. Existing shared notes remain intact. Pending review, staging and deployment.

Verification: Automated tests described above passed. Live requester acceptance and production verification remain pending.

References: TKT-V4SCMN; branch `codex/v4scmn-multiple-education-weekly-notes`.

Follow-up: Apply the migration on staging, verify with Emma, then complete the approved release process. Keep In review until deployed and confirmed.

Documentation summary: Multiple Education weekly entries; preserved legacy notes; unchanged other-portal weekly workflows.

## Message for Sir Ghamay

Hi Sir, I prepared TKT-V4SCMN (High) on `codex/v4scmn-multiple-education-weekly-notes`. The current card stores one shared note per week, which explains why Emma can only edit the existing note. This proposal adds separate Education weekly entries with author/date and own-entry editing, preserves the previous note, and leaves other portals' note workflows unchanged. The local backend tests (30/519 assertions) and frontend report tests passed. Please review the PR and GitHub validation, apply its additive migration on staging, and have Emma verify multiple entries before merge/deployment.
