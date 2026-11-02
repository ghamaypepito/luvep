# SDD ledger — plan: docs/superpowers/plans/2026-11-03-multiple-payment-proofs.md

- User approved the preview and instructed implementation and ticket completion. Reuse the existing isolated worktree on codex/multiple-payment-proofs, based on production 957b1f2e.
- Inline execution: approved preview is binding. Pre-flight: Task 2 consumes the service summaries/associations from Task 1; Task 3 consumes the same metadata and scoped review-options endpoint. Task 4 verifies and releases all three.
- Ruling: confirmations are restricted to active Admin/Super-admin/Finance. Existing Immigration staff can view evidence under current rules, but generic document approval must not bypass financial review.
- Ruling: legacy approvals remain historical facts; no new receipt amounts or currency conversions are generated from them.

- Builder check found Finance AR mirroring and installment/manual receipt writers must share currency and locking rules. Included FinanceSyncService currency filtering/reconciliation, explicit installment currency, and lead-first locking for receipt writes/deletion. No further user decision needed.
- Task 1 RED: existing handler marked a 400 proof as full payment of a 1,200 invoice; watched test fail on Approved versus StaffShared.
- Tasks 1–3 implemented together because the shared contract spans ledger, client token upload, Finance queue and case Documents. Focused suite: 31 tests / 199 assertions; frontend suite: 32 passing, including actual Inertia useForm submission, supporting receipt and attention reason.
- Isolated SQLite browser QA used three synthetic PDFs only. Actual client uploaded all three together; Finance confirmed 400, linked supporting file, confirmed 800. Client observed partial400/remaining800 then paid1200/remaining0. Database checked separately for exactly two receipt rows. No live client payment mutated.
- Independent review found currency metadata, generic document approval/note/share bypasses and family/tracker evidence deletion/replacement gaps. Fixed with preflight guards, lead-first locking and HTTP regressions. Rejected invoice excluded consistently from client settlement.
- Browser QA caught useForm.transform returning void; separated transform and post, added test against the real Inertia form implementation.
- Production source remains 957b1f2e (fetched before release). Team guide saved at docs/multiple-payment-proofs-team-guide.md. Existing theme/navigation retained.
- Historical invoices missing saved currency now require Finance to select the currency from the invoice; stale competing selections are rejected under the invoice lock. Focused verification: 32 tests / 210 assertions; seven new frontend interaction tests pass. Team guide includes this step.
- Concurrent local test processes interfered with shared Storage::fake paths (two missing-fixture failures). Affected existing suites pass serially: 38 tests / 756 assertions. The subsequent focused currency run used Laravel's TEST_TOKEN isolation to avoid sharing fake paths with the full serial regression run.
- Read-only confirmed modal and Escape/focus restoration verified in the browser. Responsive grid/card classes reviewed; browser viewport resizing is unavailable in this control surface.
