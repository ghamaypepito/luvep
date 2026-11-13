# TKT-1C0OB4 — Education report names, counts and repeated activities

Priority: High. Status: In review; proposed implementation awaits review, staging acceptance and deployment.

Branch: `codex/1c0ob4-education-report-name-lists`, based on main `eb8802a2`.

## Findings and relationship to earlier tickets

Main has departmental stage aggregation and shared weekly commentary. Those cannot record a person who has no portal record, or represent ten follow-ups/additional school events while retaining one current student stage. This is a reporting enhancement and accuracy requirement, not evidence that a client's stage should be repeatedly changed to produce a count.

The previous TKT-UY9UDR branch already proposed dated, personal accomplishments, calls/messages, additional enrolments/offers/proposals, actor-specific contact counts and weekly planning. It remains unmerged. This branch incorporates that foundation and extends it for this ticket; use this PR as the combined proposal instead of separately merging the older accomplishments branch. The older branch is not edited.

TKT-4MXBGO / PR #73 separately proposes automatic reporting of internal notes and an explicit Follow-up option. This manual report does not ingest or sum that ledger, and it does not infer actions from note keywords. UI guidance instructs staff not to manually re-enter an action already captured automatically. PR #75 separately supports multiple shared weekly notes; its workflow/storage is separate from this personal report. Review overlapping Education Reports changes when integrating proposals.

## Proposed behavior

- Record one dated entry for each person and activity. Select an authorised Education portal client, or choose Name only — not in portal and enter their name. Name-only entries do not create leads, accounts, invoices, enrolments or emails.
- Add New leads recorded, Follow-ups recorded manually, and Other status changes to the previous calls, messages, additional enrolments, offers and proposals.
- Each category shows total activities and distinct clients/names. Ten follow-up entries for one person count as ten activities and one distinct client/name. Every row retains its date, name and details, with server-side category filtering and pagination so names beyond the first page are not silently omitted.
- For name-only records, distinct counts normalise case/whitespace. These are spelling-based names, not verified identities; same-name people can be conflated and spelling variants can count separately. A name-only entry and a later linked portal record are not automatically reconciled. Activity totals remain the number of recorded occurrences. Do not present these as a deduplicated CRM population.
- Other status changes require a canonical Education status and context. They record what happened (for example a second offer or enrolment) without changing current stage, history, documents or triggering stage emails. Prefer the specific offer/enrolment/proposal activity when it already describes the event; do not log both for the same action merely to increase totals.
- Reports are personal to the authenticated staff member, with existing Education authorisation. Users cannot submit another actor ID or remove another user's entry. Removals are soft-deleted for audit. UUID retry keys prevent duplicate submissions; changed reuse is rejected.
- Personal weekly accomplishments, Other activities and Next week's tasks remain available. Shared notes and other portals' report handlers remain unchanged. No automatic historical backfill is attempted.

## Validation

The Education/weekly-report backend regression run passed: **60 tests, 873 assertions** before the final additional name-validation edge checks. Both actual React component interaction suites passed: education-personal-report.cjs and education-report.cjs. Coverage includes repeated events, names/counts, actor isolation, unknown/future dates, private client search, retry keys, reported-status context, no client/stage/email mutation, report filters, pagination, notes/draft persistence and other-portal denial.

Final targeted validation also passed: **11 tests, 210 assertions**, including Unicode-whitespace-only names and invalid client IDs. Full GitHub build/regression results are recorded in the PR. Staging acceptance remains required; automated checks cannot guarantee every production condition.

## Deployment / staging checklist

Apply the additive Education activities table migration and the subsequent client_name/reported_status column migration before serving the updated code. If the earlier foundation table already exists through the same migration, its migration should be recorded normally; do not recreate or manually clear it. No existing data is renamed or moved. Preserve real activity data before any rollback that drops tables/columns.

1. Use synthetic Education test accounts. Enter two name-only new leads; confirm names and totals persist after reload, with no new Lead/User rows created.
2. Record multiple follow-ups on different dates for one client; verify each date/name row, total activities, distinct client/name count, date filters and category drilldown.
3. Record additional offers/enrolments/proposals and Other status changes with context; verify the student's existing stage, linked departments, documents and emails are unaffected.
4. Verify Emma's personal report excludes another actor's activities and removal attempts are denied. Check repeat-submit protection and input errors.
5. Verify names beyond page 1, category pagination, weekly notes, other activities and next-week planning; inspect desktop/mobile layout.
6. If deploying PR #73 too, verify automatic note reporting separately and train staff to avoid duplicate manual entries; there is no combined grand total.
7. Check shared Weekly Notes and another portal's reports. Emma approves the output against her sample. Only after review, deployment and requester verification mark Done.

## Resolution report draft

Issue summary: Education Reports needs named new leads outside the portal, follow-up counts and dates, and repeated status-related accomplishments not represented by the current stage.

Root cause: Departmental stage aggregation records milestones/current status; it is not a personal dated activity ledger and cannot identify external people without an entry mechanism.

Resolution: Proposed combined Education accomplishments branch adds name-only reporting, dated follow-ups, reported statuses, category totals/name lists, and retains calls/messages/repeated enrolments/offers/proposals and personal weekly planning. Actual client stages remain unchanged. Pending staging/review/deployment.

Verification: Automated tests and PR validation as described above. Live requester acceptance is pending.

References: TKT-1C0OB4; TKT-UY9UDR; related PR #73 and PR #75; branch `codex/1c0ob4-education-report-name-lists`.

Follow-up: Sir Ghamay reviews the combined proposal and migration order, arranges staging tests, and Emma confirms counts and layout. Keep High / In review until deployed and verified.

Documentation summary: Names and per-occurrence counts for personal Education accomplishments, distinct client/name explanation, unchanged student stages, protected actor ownership, and separate manual/automatic reporting sources.

## Suggested update for Sir Ghamay

Hi Sir, TKT-1C0OB4 is High priority. The current stage report cannot capture people outside the portal or repeated follow-ups/additional offers/enrolments. I prepared `codex/1c0ob4-education-report-name-lists`, incorporating the earlier unmerged UY9UDR accomplishments work and adding name-only entries, follow-up dates/counts and Other status changes with context. It preserves the actual student stage and keeps activity counts personal to the staff member. Please review this combined proposal rather than merging the older accomplishments branch separately. It needs additive migrations and staging acceptance with Emma. Automatic internal-note reports (PR #73) and multiple shared weekly notes (PR #75) remain separate proposals; avoid recording the same action manually and automatically.
