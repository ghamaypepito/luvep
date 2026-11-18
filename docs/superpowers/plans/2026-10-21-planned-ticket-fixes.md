# Planned-ticket fixes: first batch

Authorized by the user: begin fixing planned tickets, reply and notify their senders, keep uncertain items in review, and preserve confidentiality across accounts.

## Confirmed work

- #10: Immigration Add Lead posts to an absent endpoint and has no staff/program dropdown data. Add the department-gated create route and reuse the existing validated private-document workflow.
- #174 (partial): Optional screening/goal-setting notes can cause undefined-key failures in staff creation. Default omitted notes safely. Public registration succeeds in the current minimal test; keep the overall ticket in review pending the failing link/error.
- #177: Production access logs show repeated HTTP 419 on task-comment POSTs. Use current cookie-based CSRF handling in both comment composers. Restrict comment read/write to active internal task creator, primary/additional assignee, or admin. Return 403/404 before catch-all handlers. Test another account cannot read or write.

## Review retained

- #172: Education proposal reviewer permission is present; approval succeeds in characterization tests. Need the exact failing proposal and error. Do not expand grants.
- #69: Current case-profile request returns 200 under the reporting account. Need current link/error before claiming closure.
- #103: Need student-status wording and transition rules.
- #147: Need cross-department transition and record-retention rules.
- #175: Need eligible requirements and roles permitted to mark Not Required.

## Communication and release

First-batch status updates and replies are authored by support@luvep.com through existing ticket-controller behavior; each sender receives an in-app update. Reply text excludes unrelated client details. Existing review tickets remain untouched; clarified items above are put In review. New fixes receive focused regression and negative-authorization tests, browser checks, a production build, and independent review before release. Mark Done only after deployment verification and a completed resolution report. Partial/unreproduced tickets stay In review with a clear follow-up request.

## Verification record

- Combined affected PHP tests: 49 passing, 334 assertions; 3 existing PHPUnit deprecations.
- JavaScript request tests: 2 passing.
- Browser: both comment composers save with deliberately stale meta CSRF and persist after reload.
- Production frontend build succeeds; existing chunk warnings remain.
- Independent review found no confidentiality blockers; missing manager/super-admin dropdown options were fixed with regressions.
- Broader suite is not green: the full 1,198-test run was stopped after unrelated failures and slow progress. The unchanged CaseChecklistServiceTest has 10 failures around mandatory `svf` checklist expectations. ProposalHistoryTest has 5 fixture errors (`Bachelors` is rejected by the category constraint). BulkEmailTest also failed in the interrupted run. These are not claimed resolved by this release.
