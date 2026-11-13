# TKT-RBGCOS — Education stage handoff to Immigration Cases

Requester supplied in the current ticket: Sharry Ricafort (Admin). Status: **In review**, pending code review, staging/release and confirmation of the original video report.

Branch: `codex/rbgcos-consistent-education-handoff`, based on main `eb8802a2`.

## Audit conclusion

The workflow is partly implemented, but is not consistent across entry points. Do not mark the complete ticket Done based on the existing Students-list path alone.

The Loom URL could not be inspected: the web reader reported it inaccessible, and browser opening timed out twice. No video content or client identity was inferred. The ticket guide states related handoff work exists, but still explicitly requires sender confirmation for any video-only remainder. GitHub/source presence is not live deployment evidence.

### Existing behavior and reproduced gaps

- Students-list dashboard stage update: correctly promotes to an Immigration case, keeps is_student, defaults an empty visa to Student Visa, preserves an existing visa choice and fires the configured handoff event on first conversion.
- Profile-header Education stage update: sets case flags/timestamps but omits the Student Visa default and handoff event.
- Create/edit/link-existing student forms: accept the Education handoff stage but omit the explicit case promotion/default/event behavior. The Cases query can still include those records by Education stage, so these omissions are not proof that a particular live record was absent.
- Immigration Cases already qualifies records by explicit case flag OR an Education handoff stage. Students uses its existing membership scope. The intended handoff uses one Lead ID in both views, not a copied client account.

Before the fix, the new consistency regression produced **5 failing checks out of 6**: the inline path passed, while profile default/notification and form-based promotion checks failed. These are reproduced code gaps, not a diagnosis of the unseen recording.

## Proposed fix

Use EducationImmigrationHandoff for explicitly submitted Education handoff stages from the inline list, profile header and create/edit/link student forms:

- Set case flag, conversion time and acting user only on first promotion.
- Default an empty visa choice to Student Visa; retain a populated visa choice.
- Keep student membership, the record ID and existing parallel stages unless another submitted field intentionally changes them.
- Fire existing configurable client/internal handoff automation on first promotion. Re-saving or moving away/back must not repeat the first-conversion notices in the tested sequential flows.
- Do not promote on unrelated edits or Sales/English/Immigration stage updates. Do not reset an existing conversion timestamp.
- Keep explicit Add pathway and generic Convert to case actions separate and unchanged; those actions do not necessarily infer a visa type from an Education stage.

No schema migration, historical backfill, role grant, template/recipient change or production action. First-time handoffs through formerly incomplete form/profile paths will now fire configured notifications; validate this on staging with test recipients. Existing disabled/missing automation stays disabled/no-op. This does not replay previously missed notifications.

## Validation

The broader handoff/student regression run passed **47 tests, 637 assertions** across the new consistency test, EducationHandoffEmailTest, StudentStaysVisibleUnderImmigrationTest, DepartmentStageTest, ClientPathwayTest, StudentOnHoldTest, StudentMultiSchoolTest and StudentCourseStartTest.

The final focused rerun passed **8 tests, 224 assertions**. The complete GitHub build/regression outcome is recorded in the PR. Synthetic records and captured/fake mail were used. No live client was converted or notified.

The tests cover the same record returned by both actual list routes, empty/existing visas, first notices, repeat saves, stage away/back, timestamps, unrelated edits, explicit pathway behavior and existing external-user denial. Staging should additionally verify concurrent submissions, queue execution and the deployed email configuration; individual tests are not an absolute delivery guarantee.

## Staging / closure checklist

1. Deploy the reviewed proposal to confirmed staging using synthetic records and test email recipients. No new migration is required by this change.
2. Select Endorsed to Immigration from Students, then separately from the profile header and edit form. Test new-student and link-existing forms with that stage as well.
3. Compare Lead IDs in Education Students and Immigration Cases. Ensure both views open the same record and its documents/tasks, with no duplicate client.
4. Verify empty visa defaults to Student Visa and an existing visa selection remains unchanged. Confirm actor/time, student membership and intended parallel stages.
5. Check one configured client notice and the intended internal notice on first promotion; repeated saves/stage changes do not resend. Test concurrency/queue retry behavior before release. Do not repeat a live handoff simply to force an email.
6. Ask Sharry to confirm whether the original Loom report is fully covered, or provide the exact remaining step/current link/error in the restricted ticket.
7. After approved release, verify the original workflow with the reporting user. Only then mark Done. If the remaining issue concerns data/list filtering, trace that exact record rather than copying a similarly named client.

## Resolution report draft

Issue summary: Student Endorsed to Immigration should become the same Immigration case while staying in Education, with the correct visa default and first-handoff notifications.

Root cause: Handoff behavior was duplicated across controllers; only the inline Students update implemented the complete stage-handoff behavior. Profile and form paths omitted parts of it.

Resolution: Proposed shared routine makes explicit Education-stage handoffs consistent across five entry points. Existing record membership, visa choices and configured automation are preserved. Pending staging, release and original-report confirmation.

Verification: Reproduced failures before the fix; automated results above and in the PR. Original Loom and production workflow have not been verified.

References: TKT-RBGCOS; branch codex/rbgcos-consistent-education-handoff; original Loom URL https://www.loom.com/share/7a15318f48c3433784555716fbc64e79.

Follow-up: Sir Ghamay reviews/releases after staging; Sharry confirms the remaining video-only report. Keep In review until those checks are complete.

Documentation summary: Existing inline handoff versus inconsistent entry paths, shared stage-handoff fix, same-record verification, configured notices and closure evidence.

