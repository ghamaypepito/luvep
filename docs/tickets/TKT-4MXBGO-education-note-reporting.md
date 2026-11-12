# TKT-4MXBGO — Automatic Education internal-note reporting

Priority: High. Status: In review; this document describes a proposed implementation, not a production resolution.

## Confirmed requirement

Kent confirmed that every successfully posted note should be recorded automatically, with an explicit Follow-up option when writing it. Do not infer a completed call or follow-up from words in a note: “I have not called” must not become a completed call.

## Findings

Internal notes are saved in LeadNote with a client ID, author and creation time. Existing Education Reports reads stage/status history, not note-post activity. The earlier TKT-UY9UDR branch provides manual dated accomplishments and personal weekly notes; it does not automatically turn a saved internal note into a report entry.

There is no reliable department/activity label on historical notes. The shared note controller is also used by other portals. Automatic historical classification would require assumptions about where a note was posted and what work it represents.

## Proposed implementation

Branch `codex/4mxbgo-education-note-reporting` is based on main `fc55051b6a2fb23b6c3937d28d5ad6a7708af2e0`. It is independent of the manual-accomplishments branch.

- Education's existing note composer offers Client update (default) and Follow-up. Existing General / Pre-screening / Goal-setting note kinds stay separate and unchanged.
- Education submissions use the existing Education notes endpoint. Older Education-role clients using the shared Admin note endpoint also record default Client update activity. Other portal submissions ignore the Education-only reporting field and retain their existing route/payload behavior.
- The note and a single reporting event are saved in one database transaction. The event records the server-authenticated actor, client, posting timestamp, explicit activity and whether it is a reply. A unique note ID prevents multiple reporting events for one persisted note.
- Each saved note counts separately. Ten follow-ups for one client show ten activities and one distinct client. Another staff member's posts do not appear in Emma's personal counts. Support impersonation credits the actual actor rather than the impersonated account.
- Reports adds My automatic note activity, with client name/link, author, NZ date/time, activity totals and pagination. The existing date filters and live summary refresh apply. Stage totals and manual accomplishments remain separate; note events are not inserted into either total.
- Edits, pin changes and report refreshes do not create new posting events. If a note is deleted, its posting event remains, labelled Original note removed; note body/attachments are never copied into the reporting ledger.
- Reporting is restricted to the actor's own events for records currently available to Education's discussion scope. Archived/deleted/out-of-scope clients are not exposed. Existing note and report access controls remain in force.
- This adds one new reporting table/migration. It does not update client stages, send messages, change role grants or change another portal's reporting controller. Run the migration before activating the new code.

## Historical and counting boundaries

Tracking starts when the feature is deployed; no automatic backfill is included. Existing historical notes are not guessed into a department or Follow-up category. An approved historical import, if required, is separate work.

The report counts persisted note posts, not successful calls, messages, school enrolments or status changes. If a user actually saves two separate duplicate notes, those are two persisted posts; the event ledger does not silently deduplicate their content. Follow-up is an explicit staff classification, not proof of client contact.

## Validation and staging acceptance

Local checks passed: a 41-test regression run with 817 assertions (the new note-reporting tests, Education profile-note access, Education report/period behavior and shared weekly-report tests), plus one focused support-impersonation test with 40 assertions. Total across those runs: **42 tests, 857 assertions**. The frontend test `node tests/Frontend/education-note-activity.cjs` passed explicit classification, portal-safe submission, repeated rows, author/client display, pagination and escaping checks. The full asset build was still running at this validation snapshot; its completion, CI and staging results must be checked before merge.

No live records were used in these checks. Before merge/deployment, verify:

1. Post Client update and Follow-up notes from both an Education lead and student profile. Each appears once, with the correct client, actor and NZ timestamp.
2. Post ten follow-ups for one client and verify ten rows/activities and one distinct client across the selected dates. Verify Nova's entries do not appear in Emma's account.
3. Test This week, Last week, Today, custom ranges, NZ midnight boundaries and pagination. Report refreshes must not duplicate events or lose the selected range.
4. Edit/pin a note, then delete a synthetic note: counts must not inflate, and the deleted original is explicitly marked. Confirm note bodies and attachments are not exposed in the report.
5. Use another portal and an external/unauthorised account. Existing note behavior must remain intact; unrelated records/reports remain denied.
6. If also merging TKT-UY9UDR, preserve both independent report panels and both CI tests, run both migrations and repeat combined regression tests. Do not add automatic note events to manually entered accomplishments.
7. Emma confirms that the reporting and Follow-up selector meet her needs. Mark Done only after approved deployment and verification.

## Resolution report draft

**Issue summary:** Education internal-note posts do not appear automatically as named weekly client updates/follow-ups.

**Root cause:** Notes and stage history are separate data; Reports aggregates stages without a note-post reporting feed or explicit reporting classification.

**Resolution:** Proposed branch records each Education note post atomically with an author/client/date event, adds an explicit Follow-up option and displays a separate personal activity report. Pending review, build/staging checks and deployment.

**Verification:** Use the local results and staging checklist above. No production notes, stages or reports were modified by preparing this branch.

**References:** TKT-4MXBGO; branch `codex/4mxbgo-education-note-reporting`; related manual reporting ticket TKT-UY9UDR.

**Follow-up:** Sir Ghamay reviews the migration and code; Emma validates the workflow on staging. Confirm separately whether an approved historical import is required.

**Documentation summary:** Automatically count saved Education note posts with explicit activity labels; preserve actor attribution and repeated events without altering stage counts or guessing historical activity.
