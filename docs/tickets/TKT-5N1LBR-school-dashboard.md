# TKT-5N1LBR — School management dashboard

## Findings

Reviewed current main on 24 November 2026. Schools has contact/portal information and an admin-gated agreement file. Programs already owns course titles, school IDs, levels, duration, tuition, entry/English requirements, campus, catalogue status and free-text intake months. No matching ticket implementation was found. Study plans are preferences, not proof of enrolment; month-only intake text cannot establish application deadlines or graduation dates.

## Implementation

Education school profiles now open the dashboard with existing school information retained in a separate expandable section. Four sections provide:

- Explicit student/programme enrolments: intake label, optional verified start/completion dates and the five requested statuses; programme/intake/status filters and existing client-profile links. Each summary counts distinct students, not programme rows. Enrolled means Enrolled or Currently Studying; completed/studying have separate counts. Upcoming completions use recorded dates. Primary-school associations are shown separately for review, not automatically counted. No existing students, study plans, Education stages or visa outcomes are changed.
- Dated Email/Meeting/Phone Call/Other correspondence: contact, responsible active Education/Admin staff, summary, follow-up action/date, requested statuses and retained private attachments. Newest communication date first; pending follow-ups show overdue dates. This records correspondence; it does not connect a mailbox or send email.
- Courses and fees reuse Programs by immutable school ID. Existing course CRUD/tuition/requirements/archiving stays in Programs; the school view links to it. Optional school-specific application fees/currency, other-fee notes, Active/Inactive/Archived status and private brochures are stored separately. Catalogue tuition is displayed as recorded without inferring a currency or converting prices.
- Multiple exact-date programme intakes: application opening/closing, commencement/end, campus and the five requested statuses. Upcoming open intakes and deadlines appear in the overview. Programs offers direct school-intake links. No guessed dates, automatic student intake rewrites or outbound reminders.

Education/Admin ordinary active sessions can use the workspace. Other roles (including extra portal grants), inactive users and View-as previews cannot write/read its private records/files. Existing non-workspace school-profile behavior is preserved for other callers. Files are private, MIME/size/count validated and downloaded through school/record/file-scoped checks. The workspace response excludes portal passwords/private storage paths.

Edits require a matching record version; stale saves retain current data and return form errors. A school-level transaction lock serializes workspace creation; duplicate school-course supplements and duplicate student/programme/intake enrolments are rejected. Records and actor-attributed audit entries commit together; rejected/failed writes clean up new private files. Previous attachments remain retained on edits.

School/programme deletion is refused when school-workspace history exists under the same school-first lock used by workspace writes. Existing files are deleted only after the catalogue deletion commits. Use inactive/archive instead. Existing programme-school association changes are refused when management history exists; ordinary updates/archiving remain available. Unreferenced catalogue behavior is unchanged.

## Release and verification

One additive migration creates `school_workspace_records` and `school_workspace_files`. Run it before serving the dashboard. No historical backfill, role grant, pricing rewrite, client correction, Finance/Immigration operation or live mail is included. Migration rollback deliberately retains entered records and private-file metadata; reapplying the migration reuses retained tables. Do not drop these tables or delete private uploads on code rollback.

The deployment workflow copies code before migrations. Until both new tables exist, school profiles retain their existing information view and ordinary catalogue updates/deletes retain their previous behavior. The new workspace reports a controlled installation-unavailable response. A regression explicitly removes/reinstalls the new schema and verifies old Schools/Programs behavior in that interval.

Synthetic backend tests cover dashboard isolation, catalogue reuse, enrolment CRUD/no identity or stage changes, dates, cross-school/programme/record/file denial, statuses, stale saves, duplicate prevention, private upload/download retention, audit rollback/cleanup, role/preview/inactive denial, and archive/reassignment guards. Actual React checks cover counts, filters, chronology, links, versioned saves and error retention. Synthetic Chrome desktop/mobile rendering checks cover the page and intake form. Full exact-head CI is required before release.

The user authorized testing, review, merge/deployment and technical closure on 26 November. Keep In Review until exact-revision CI and post-release verification succeed. Staff must record actual enrolments and verified missing dates before those facts appear in summaries; this feature does not backfill enrolments from school associations. No production school/student record was modified during implementation/testing.

26 November review reproduced and fixed failed attachment writes returning success/path 0, programme reassignment interleaved with new history, retained-table rollback/reapply failures, and file loss during failed catalogue deletion. Regression tests cover rejection, transaction rollback and existing-file retention. Independent testing passed on `ebb37ff8`: Schools tests and actual React save/filter/error checks, as part of 117 tests / 1,902 assertions plus six React harnesses. Separate Standards and Spec reviewers found no actionable issues. SQLite observer interleaving is deterministic coverage; it is not a multi-process production database load test.
