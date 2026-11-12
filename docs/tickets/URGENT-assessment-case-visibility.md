# Urgent — Visa Assessment says converted but case cannot be found

Priority: Urgent. Reported impact: Immigration staff cannot locate the applicant's case, blocking the intended invoicing workflow. Status: In review, not resolved in production. Ticket ID not yet provided.

## Evidence and limits

Kent supplied the requester conversation and screenshots of a case-list search returning no visible match. The private assessment-profile URL was subsequently supplied; keep that URL in the restricted ticket rather than this repository. The exact conversion message, underlying record IDs and live database/logs have not been supplied. No live record was modified, conversion retried or invoice generated during this investigation.

Follow-up screenshots show the reported Visitor assessment as Completed with no Convert action, while another applicant sharing the same email retains Convert. This confirms the visible symptom, not the underlying case state. Completed is derived from workflow status in the UI. Shared email alone must not be used to select or repair a case. The pre-screening's separate `converted_lead_id` outcome pointer is now also resolved and checked for conflicts, in addition to `assessment_id` and the working-lead link.

The later assessment-profile screenshot shows pre-screening status New and next action Send agreement, while the list screenshot shows Completed. These are separate workflow fields; their labels alone neither prove nor disprove that a case was created. The precise linked case IDs still require authorised inspection before identifying the live cause or applying a record repair.

Current GitHub main was checked at `9b797d271e44a1a4277a92e704d536f0da0c0a7f`. A source-code defect is reproducible with synthetic records, but it is not yet established that this defect caused this particular live incident. Code availability on GitHub does not establish which revision is deployed.

## Findings

1. The assessment list treated intake status `Engaged` as proof of conversion even if no live case existed. That also suppressed the Convert action. The assessment profile separately presented a converted pre-screening status as proof of a case.
2. Neither screen provided a direct link to the case that supposedly existed. Staff had to search the case list separately.
3. Conversion did not first reuse an exact existing `assessment_id` case link. A retry after the case's name/email changed could fail the name matching and create another case. Resident intake-only conversion also ignored the existing pre-screening working lead.
4. Current main already loads up to 2,000 Immigration cases, warns when truncated, and searches loaded case fields. The former 200-case truncation bug has a regression test and is already fixed in this source revision. The screenshots do not establish the live deployment revision, cap, active filters, or whether a case row actually exists.

## Proposed branch

Branch: `codex/fix-assessment-case-visibility`, based directly on the verified main SHA. The earlier Education Reports changes are on their separate branch.

- Resolve a case using an exact assessment link, recorded pre-screening conversion target, working-lead link, or the existing email-plus-DOB identity rule. Never use email alone. Conflicting explicit conversion links require support review. Case resolution uses the same Immigration queue scope as the case list.
- Only show a converted journey when a live case can be resolved. Return an explicit warning for inconsistent status, deleted links or ambiguous matches.
- Add Open case to assessment rows, the intake modal and the assessment profile so staff can open the resolved record directly without searching by its possibly changed name.
- For a stale status with no conflicting/deleted case, expose the existing conversion action for an explicit staff retry. Reuse the existing working lead and original case ID where available. There is no automatic bulk repair.
- Block retries that would recreate a deleted linked case or choose between ambiguous case identities. Support must verify those records first. No deleted case is restored automatically.
- Keep original conversion timestamps and existing one-time email-automation behavior on repeat conversions. Upload migration continues through the existing conversion path.
- No database/schema migration, invoice logic, role grants, Education code or production configuration changes are included. Shared Immigration assessment surfaces receive the same corrected linking behavior; existing permissions remain in force.

## Local validation

Backend command:

```text
php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Immigration/AssessmentCaseVisibilityTest.php tests/Feature/Immigration/AssessmentPipelineTest.php tests/Feature/Immigration/FreeAssessmentConvertTest.php tests/Feature/Immigration/CaseConvertedFiresAutomationTest.php tests/Feature/Immigration/IntakeSyncsToCaseTest.php tests/Feature/Immigration/CasesListTruncationTest.php
```

Result: **41 tests, 255 assertions passed**. Covers stale Engaged/pre-screen state, reuse of the working lead, case-list visibility after retry, exact assessment linkage after name changes, recorded conversion targets without working-lead links, original conversion timestamp, deleted/ambiguous case protection, shared-email relatives, Resident conversion without an assessment, Finance denial, intake syncing, free-assessment conversion and automation behavior.

Frontend: `node tests/Frontend/assessment-case-link.cjs` passed; `node --test tests/Frontend/assessment-notes.cjs` passed all 9 tests. PHP syntax and `git diff --check` passed. The full Vite build transformed 4,959 modules but was stopped after prolonged execution before bundling finished: **full asset build is not a pass and must be completed in CI/staging before merge**. Existing duplicate-key warnings originated in untouched EducationEnrolmentPage.jsx. Tests used isolated in-memory SQLite and synthetic applicants with mail/notifications faked. No live client data was used. This is not staging or production acceptance.

Delivery updated 12 November 2026: after Kent authenticated GitHub CLI as Kynbeau, Git credentials were configured and repository push access was confirmed. Branch `codex/fix-assessment-case-visibility` was successfully published to `ghamaypepito/luvep` for review. Earlier connector HTTP 403 and Git authentication failures were publishing blockers, not evidence that Kent lacked collaborator access. No PR, merge or production deployment has occurred. Full asset-build completion, staging and live-record verification remain outstanding.

## Immediate authorised investigation on the live incident

1. Obtain the exact assessment/profile link and conversion message privately. Record intake type/ID, assessment ID and pre-screening lead ID.
2. Check the exact assessment-linked and pre-screening-linked lead IDs, `is_immigration_case`, conversion timestamp and soft-deleted state. Verify the applicant identity before any repair.
3. If a live authorised case exists, provide that verified case-profile link to Ei/Nova and check that it is the correct record before using it for invoicing. Do not guess an ID, create a second client or change names/permissions to bypass the issue.
4. If the case exists but is absent from the list, clear stage/ownership/tab filters, check the loaded/total warning, compare the deployed revision with main and inspect the actual case payload under the reporting account.
5. If the status is stale and no case exists, reproduce that state on staging and review the branch. Retry conversion only on the verified original submission after the fix is approved/deployed. If deleted or ambiguous, use a separate approved record-repair decision; this branch deliberately blocks automatic recreation.
6. Confirm the original case opens, appears in the expected list, retains its documents and supports the intended invoice workflow. Requester confirmation is required before Done.

## Staging acceptance

Use designated Immigration manager and staff test accounts, not shared production credentials. Test stale conversion recovery, an already converted applicant, family members sharing an email, edited client identity, deleted original case, Resident intake-only conversion and unauthorised Finance access. Check direct Open case links, document continuity, one case only, preserved conversion date and no repeat first-conversion notice. Confirm other portal regression checks/CI before merge. No production merge/deployment is authorised by this investigation.

## Resolution-report draft — pending deployment

**Issue summary:** Visa Assessment reports conversion, but staff cannot locate the case, blocking invoicing.

**Root cause:** Source inspection identified workflow labels being used as conversion proof and missing direct case navigation. Exact live-record cause remains unconfirmed pending record-link/database verification.

**Resolution:** Proposed branch verifies live case links, provides Open case navigation, allows controlled stale-status recovery using the existing record, and prevents deleted/ambiguous case recreation. Local fix only; review, staging and deployment are outstanding.

**Verification:** Local tests listed above passed. The original applicant's case and invoice workflow have not been verified in production.

**References:** Branch and test files in this changeset; requester screenshots and exact case evidence should remain in the restricted ticket.

**Follow-up:** Sir Ghamay reviews/publishes the branch and verifies the exact live record. Ei/Nova confirm case visibility and invoicing after the approved intervention.

**Documentation summary:** A Converted label alone is not evidence of a usable case. Use the verified case ID and direct case link; retain existing records and avoid duplicate clients.

## Suggested message to Sir Ghamay

Sir, urgent: Nova and Ei report that Jezzie's Visa Assessment says converted, but they cannot find her in Cases, so invoicing is blocked. I found and tested a code path where Engaged/pre-screening status can show conversion without a live case. Published branch `codex/fix-assessment-case-visibility` adds verified case links, Open case navigation and controlled retry using the existing working lead, with deleted/ambiguous-case safeguards. Local regression tests passed. This is a proposed fix; Jezzie's exact live cause still needs her assessment and linked case IDs checked. Please prioritise that record check, full asset-build completion and staging review before merge/deployment. No production changes were made.
