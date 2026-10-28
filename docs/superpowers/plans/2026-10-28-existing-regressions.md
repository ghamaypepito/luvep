# Existing regression failures implementation plan

> **For agentic workers:** Use systematic debugging for diagnosis and test-driven checks for any production fix. Independent groups use the parallel-agent workflow; the coordinator owns final integration and review.

**Goal:** Resolve all 54 reported failures while preserving current portal behavior and guarding the full suite in CI.

**Architecture:** Keep the current application contracts. Correct stale fixtures, routes and expectations with precise assertions; make a production change only if a reproduced defect violates the current contract. Use the existing isolated worktree and branch `codex/fix-existing-regressions`.

**Tech Stack:** Laravel 12, PHP 8.3 CI and PHP 8.5 local runtime, PHPUnit 11, SQLite in memory, React 19 and Vite 7.

**Spec:** [Current regression contracts](../../regression-contracts-2026-10-28.md).

## Global constraints

- Preserve current authentication, private storage, row ownership and advice approval controls.
- Keep automatic client welcome emails disabled and preserve explicit tracker sending and reviewed engagement delivery.
- Do not skip tests, mark failures incomplete, loosen global test configuration or remove meaningful assertions.
- Keep unrelated changes in the original checkout untouched. Use synthetic test records only.
- All fixtures and expected values must follow the documented current contract, independently of the implementation under test.

## Review focus

- Shared email addresses must not cross-link applicants or leak intake data.
- Shared staff template access must not admit external client accounts; per-lead sending remains scoped.
- Public case codes and forged query/body IDs must not expose or modify another client's case.
- The required information form must not shift status assertions onto an unrelated checklist item.
- Manual welcome and document-review actions must verify recipients, message content and delivery count.

## Tasks

- [x] Reproduce the communication group; update the six affected communication/welcome test files; preserve explicit send and denial coverage. Verify the focused Communication and TrackerEmail suites.
- [x] Reproduce checklist/case-data failures; update CaseChecklistServiceTest, CaseDocumentsTabTest and CaseProfileDataLoadingTest. Verify form order, key-based status/progress, intake identity and ownership fixtures.
- [x] Reproduce workflow failures; update EducationReportTest, AdviceBearingLicenceTest, AssessmentPipelineTest and DepartmentStageTest. Keep runtime permissions unchanged and correct contradictory role documentation.
- [x] Reproduce client tracker failures; update UploadChecklistTest, TrackerDocumentTest, AgreementSigningTest and AttentionTest to use current private/authenticated surfaces. Add public-case denial and cross-client access checks.
- [x] Confirm the privacy/storage/scope tests catch deliberately introduced local regressions, then restore production sources byte-for-byte.
- [x] Run the full PHP suite, all frontend scripts, PDF worker tests and production build. Record exact outcomes.
- [x] Replace the selected CI PHP file list with the full suite. Update stale development instructions.
- [x] Obtain an independent review of assertion strength and contract evidence. Resolve material findings, rerun affected checks and full integration as needed.
- [ ] Commit the test/documentation/CI correction, create a reviewable PR and verify GitHub's full-suite gate. Integrate using the existing authorized release workflow once checks pass.
