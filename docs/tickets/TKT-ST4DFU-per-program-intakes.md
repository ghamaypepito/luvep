# TKT-ST4DFU — A planned intake for each selected program

## Findings

Current main supports multiple selected programs and an index-aligned school list, but only one `preferred_intake` string on the study plan. The student edit modal exposes only that shared date. No separate program-intake implementation was found in current main/open PRs on 19 November 2026.

## Resolution

Add a planned-intake date input beside each program's school. Store explicit program-title/date pairs in nullable JSON `lead_study_plans.program_intakes`. Round-trip the values through the student listing, edit/new form, existing-person linking, Students intake cells and expanded student details. School fields and actual course start retain their existing behavior.

Keep dates associated by exact program title rather than changing selection indexes. New additional programs start without a date; no first-program date is copied into them. Reordering known pairs keeps their original dates. Removing a selected program removes its entry when the form is saved. New-student drafts include per-program dates; validation errors keep entered values. The first selected program's intake is mirrored to the existing `preferred_intake` field for backwards-compatible primary-intake consumers. Clearing that first date does not erase another program's date.

Existing rows are not backfilled. A legacy shared intake is shown for the first program only; later dates remain unknown. Preserve an unchanged historical free-text label (e.g. month/year) for the same original program, visibly show it, and let staff replace it with an exact date or explicitly clear it. New values require valid ISO dates. Never invent a day or move a legacy label to a different program.

Validate list membership, required per-entry fields, duplicates, size limits and dates before saving a Lead, so invalid inputs cannot fire save observers. Retain transaction rollback and existing access/handoff/name protections. Older callers omitting the new field continue to work and preserve other named dates; an explicit legacy primary-intake update synchronizes only the first program's date when metadata exists.

## Scope and limits

No new URLs, permissions, status transitions, notification behavior or automatic changes to actual course start. The shared Student controller/model accepts the additive metadata, with legacy requests unchanged. The visible inputs/read display are in Education Students. No migration or changes are made to client documents or Immigration cases.

Intake Monitoring and existing primary-intake consumers still use the first program's `preferred_intake`: this ticket does not create multiple monthly cards or inflate student counts. Program titles are the existing selection identity; renaming a program is a new selection, not an automatic identity match. Secondary independent study-plan rows are retained.

## Deployment and acceptance

Run `2026_11_19_020000_add_program_intakes_to_lead_study_plans` before serving the updated form. It adds one nullable JSON column on the study-plan table, not the large leads table, and does not rewrite old intake values. No configuration/provider is needed. Back up the new column before rollback after real use; dropping it loses secondary dates while the primary legacy field remains.

On staging, use a synthetic Education student with two programs at the same school and different dates. Save, reopen and verify both dates in the form/list/details. Change/clear the second date, remove the first program, and confirm the remaining date follows its program. Check an existing free-text intake and a new-student draft, plus existing-person linking. Verify school selections, actual start, stages, other study plans and existing access rules. Obtain Emma acceptance after Sir's review/merge/deployment before Done.

## Verification

Backend coverage includes create/list round-trip, secondary edit/clear, reordering, legacy omitted requests, free-text preservation, invalid/unrelated/duplicate/incomplete payload rollback, linking and denied roles. Related multi-school and course-start tests are included. Actual React modal/read-display checks cover independent fields, first-only legacy fallback, failed-save/reopen, program removal/addition, blank dates, explicit legacy clearing, new-draft restoration, schools and actual-start isolation. Referral/status UI regressions are included; final build/full-suite results are recorded in the PR handover.
