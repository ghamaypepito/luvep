# TKT-IKIBDU: Education to Immigration then back to Education

Review date: 16 November 2026. Source baseline: main 0549d9cc.
Status: In review. The intended original workflow and the example record remain unconfirmed. Kent will confirm with the current team tomorrow.

## Requester attribution

Per Kent's clarification, Neil originally submitted this ticket using an account now used by Sharry Ricafort. The current account display name must not be treated as evidence that Sharry submitted the request or approved a reversal. No account, ticket or historical audit identity was changed in this investigation.

## Findings

Normal dual-department work already exists:
- Education handoff preserves is_student, allowing the same Lead ID to remain in Students.
- Students.jsx includes an explicit student in the Education tab even when also in Immigration.
- Education, English and Immigration have separate stage fields. A normal Education stage change preserves an explicitly flagged Immigration case and its outcome/visa data.
- Lead::immigrationCase recognises an explicit case flag OR an Education handoff stage. Some older cases therefore qualify without the flag.

Reproduced gap: for an older row recognised as a case only by its handoff stage, returning to School Enrolment removes the stage-based qualification while leaving is_immigration_case=false. The record then drops out of the canonical Cases query. Before this fix, the model's preservation hook covered only Qualified but No Funds and Archive, not ordinary Education work such as School Enrolment, Course Ongoing or Completed.

A synthetic inline School Enrolment test failed on main at the post-edit case-membership check. This proves a generic code gap, not the cause of the uninspected original Leyven example.

The generic LeadController::revertCase operation is distinct: it clears the case flag and conversion timestamps. A remaining handoff Education stage can still qualify the record as a case. Do not use it as a substitute for resuming Education work or declare reversal implemented according to unagreed business rules.

## Separate proposed fix

Extend the existing Lead updating safeguard from the two disposition labels to Education stage changes in general, only where the original Education stage already established case membership. Respect a deliberately changed case flag.

This retains the same existing case when Education resumes work. It does not promote a pure Education student to a new case, alter an unrelated edit, invent a legacy conversion timestamp/actor, reset the Immigration stage or resend the handoff automation. Existing stage history and normal auditing continue.

Scope: a small Lead model-hook change plus regression tests. No migration, historical backfill, UI/route/role change, reversal button or destructive operation. No real client record was read or modified.

This complements the separate first-handoff consistency proposal in PR79; it does not modify that branch or depend on merging it. Do not duplicate PR79's workflow changes into this fix.

## Validation

Local focused run: 27 backend tests / 670 assertions passed across ReturnToEducationTest, existing disposition stages, On Hold/resume and legacy case-profile membership tests. Existing Education disposition frontend interactions passed.

Nine return scenarios cover Students inline, profile-header stage and edit-form paths, each to School Enrolment, Course Ongoing and Completed. They verify the same ID, Education student visibility, Immigration profile access under the Immigration role, preserved visa/stages/assignee and conversion fields, retained file/path/content, notes and prior sent-message record, preserved old timeline entry, new Education stage history and no handoff automation/mail/notifications. Negative checks cover pure Education changes, unrelated edits and external-user denial. Explicit case-flag writes remain separate.

All records, files and message history were synthetic. Mail and notifications were faked; unexpected HTTP was blocked. No live conversion, notification or owner document was used. Full CI results are recorded in the PR once complete. Staging and original-record acceptance remain outstanding.

## Resolution report

Partially implemented in main for ordinary flagged cases; a reproduced legacy-case return gap has a tested proposal. Do not mark the original ticket Done yet. Neither the specific original case nor the intended meaning of back to Education was confirmed.

Confirm with the current Education/Immigration owner whether they want:
1. Continued Education work with Immigration case retained. The proposed fix addresses a reproduced legacy gap in this path.
2. Undoing a mistaken conversion. Retention, access, billing, documents, visa outcome and audit/notification rules need an explicit decision before implementing a reversal. This proposal does not implement reversal.

## Staging / closure

Sir reviews and stages the branch. Use approved synthetic Education and Immigration accounts/records to reproduce all three return paths, verify both departments and private file access, preserve visa outcome and conversion history, and confirm no duplicate handoff notice. Test a legacy stage-only case as well as an explicitly flagged case. Do not broaden access or reset the visa outcome as a workaround.

For the original report, obtain the exact restricted record link and agreed steps from the current business owner. After the appropriate release and acceptance, record which meaning was satisfied. If the original request is no longer required, close with an explicit business cancellation reason rather than claiming it was technically fixed.

## Suggested update for Sir Ghamay

I reviewed TKT-IKIBDU. Kent clarified that Neil submitted it using the account now named Sharry, so Sharry should not be treated as the original requester. Normal dual Education/Immigration work is already supported. I reproduced a legacy case dropping out of Cases when its Education stage returns to normal Education work, because the retention guard covered only Archive/Qualified but No Funds. A separate small model-hook fix retains existing case membership without resetting Immigration data or sending handoff notices. Local regression checks passed. Kent will confirm the original intent tomorrow; the proposal does not implement conversion reversal or prove the original example is resolved.
