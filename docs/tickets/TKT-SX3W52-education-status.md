# TKT-SX3W52 — Education disposition stages

Priority: High. Status: In review, pending validation, staging and Emma's acceptance. Branch: `codex/sx3w52-education-disposition-stages`, based on main `fc55051b6a2fb23b6c3937d28d5ad6a7708af2e0`.

## Findings and interpretation

The screenshot identifies the Education stage picker on an existing student profile. Neither Qualified but No Funds nor Archive is in Lead::EDUCATION_STAGES. Qualified but No Funds already exists in the separate Sales pipeline, so changing the global status would target the wrong workflow and could trigger Sales follow-up emails.

Archive is implemented here as a reversible Education stage, not soft-deletion, removal from all lists or cancellation of parallel work. This follows the request to add labels to the pictured stage dropdown. Emma must confirm that interpretation during staging; if she wants hidden archived records, that is a different workflow requiring clarification before deployment.

## Proposed change

- Add the exact labels Qualified but No Funds and Archive to Education's canonical stage list and the Students/add-edit dropdowns. Profile dropdowns and save validation consume the canonical server list.
- Retain both labels as non-milestone dispositions, like On Hold; show their current label without pretending they are academic milestones on the journey.
- Preserve English/Immigration stages and assignees when entering, editing or resuming an Education disposition from the student form.
- Use existing dated Education stage history and report aggregation; the new labels are countable by date and do not modify the global Sales status.
- Keep the student, documents and case links. Archive does not set deleted_at, remove the student flag or call the existing delete/archive action.
- Retain existing Immigration membership for older handoffs that relied on the former Education stage alone: on these two transitions only, preserve the existing case eligibility as an explicit flag. Do not alter the Immigration stage, invent conversion timestamps or fire a new handoff notification.
- The new labels are not added to Immigration or English stage lists, or the Education-to-Immigration handoff subset. No database migration or bulk record update is required.

## Validation and staging checklist

Local backend checks passed: **37 tests, 405 assertions** across StudentDispositionStagesTest, StudentOnHoldTest, DepartmentStageTest, EducationReportTest, EducationReportPeriodTest and StudentStaysVisibleUnderImmigrationTest. Tests cover both labels through the dashboard, profile stage endpoint and student-edit form, visibility, Reports, reversible moves, unchanged Sales/English/Immigration data, repeat saves and legacy handoff membership. The actual edit-modal test passed stage selection and omission of parallel department fields when entering, editing and resuming a disposition. Full build/CI and staging results remain to be checked before merge.

On staging, select both labels from the pictured profile dropdown and Students table, save, reload and filter for them. Verify the correct weekly report names/counts and that switching back works. Repeat with a student already active in English/Immigration, checking the existing case and documents remain accessible. Ask Emma to confirm Archive means a visible/reversible stage rather than deleting/hiding the record. Merge/deploy only after review and acceptance; no production record was modified by preparing the proposal.

## Resolution report draft

**Issue summary:** Education needs Qualified but No Funds and Archive in its student-stage dropdown.

**Root cause:** The requested labels are absent from the Education whitelist and mirrored frontend options. The similarly named Sales status is a different field/workflow.

**Resolution:** Proposed branch adds both Education stages, keeps them reversible and reportable, and preserves parallel department work. Archive is a stage, not deletion. Pending validation, review and deployment.

**Verification:** Use the automated results and staging checks above. No production change or requester acceptance has occurred yet.

**References:** TKT-SX3W52; branch `codex/sx3w52-education-disposition-stages`.

**Follow-up:** Sir Ghamay reviews the code and completes staging verification with Emma, especially the intended Archive behavior.

**Documentation summary:** Education-only dispositions, using existing stage history and reporting, with retained records and parallel workflows.
