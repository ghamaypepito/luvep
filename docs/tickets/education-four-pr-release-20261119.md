# Education review and release — 19 November 2026

Combines Kent's PRs #140 (Bulk Email readiness), #147 (dated personal notes), #150 (intake stage ordering and monthly counts), and #151 (per-program intake dates). Original heads: ebd1f531, 949c998a, bd023956, aa315bd7. Integration starts from production main a4176063. The workflow conflict was additive; all three new frontend suites are retained.

## Review corrections

- Existing lead/profile and event-registration writers update the shared study-plan intake fields. Reconcile mapped dates at the LeadStudyPlan saving boundary so those changes cannot be hidden or reverted by the Education form. Course removal drops its date; reordering preserves dates by title; a re-added course starts undated.
- The existing-person link endpoint merges populated fields. Its new per-program handling now preserves same-title dates left blank, including legacy free text, while ordinary Education edits can explicitly clear them.
- Students search and its department-selection logic include every current program's raw and formatted intake date through one matcher.
- An uncertain dated-note submission followed by an edited retry now returns an actionable Inertia validation error. JSON callers retain conflict status 409; existing saved/deleted entries are not overwritten or duplicated.

No required defects were found in the Bulk Email guidance or monthly stage-count implementation. Counts remain filtered students in four specified stages, not completed tasks or a second card for each program. Intake Monitoring continues to use each student's primary program date.

## Verification and release contract

Regression cases reproduced the intake and retry defects before their fixes. Focused PHP and actual React component suites cover identity/date association, omitted legacy fields, link preservation, raw/display search, note ownership and revision/retry protection, filtered counts, and Education-only email guidance. Mutating the new stage alias and Education email guard independently causes their frontend checks to fail.

Browser checks use a separate synthetic SQLite database with array mail. No production client edits or campaigns are needed. The deployment adds the dated-note table and nullable program_intakes JSON column; no historical dates are guessed or backfilled. Preserve both new data stores on an application-code rollback after staff use them.

Run the complete PHP regression, locked-dependency production build, PDF-worker/dependency checks and frontend workflow checks on the release candidate. Deploy to staging before main and verify the deployed Education controls read-only. Do not select recipients, create a live campaign or send any email during verification. Record the candidate, CI and deployment runs in the release PR.
