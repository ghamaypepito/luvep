# TKT-UY9UDR — Education personal accomplishments

## Assessment

Priority: Urgent (deadline-driven; corrected by Kent on 12 November 2026). Emma needs the report by Friday, 13 November, or Monday, 16 November; the exact deadline remains unconfirmed. Status: In review; proposed fix requires review, staging acceptance and deployment. This is a reporting accuracy/enhancement request with a time-sensitive reporting deadline; enrolment processing itself is not established as blocked.

Reviewed against main commit `9b797d271e44a1a4277a92e704d536f0da0c0a7f`. No production data, permissions, stages or existing documents were changed.

### Findings

- Education Reports inserted Contact Attempted/Contacted cards into the student section using the shared department lead register. Those figures did not identify the staff member who performed the contact action, explaining why another staff member's actions appeared in Emma's report.
- Student stage cards count distinct students entering a stage. They cannot represent multiple enrolments, offers or proposals for one student, or all daily calls/messages.
- Existing weekly notes are shared by portal. The request needs personal weekly accomplishments, other activities and next week's tasks without overwriting those shared notes.

## Proposed implementation

- Add a dedicated Education personal report panel. Contact Attempted/Contacted use the authenticated actor's recorded audit events and only records currently in List of Leads; student records are excluded. Each contact card counts distinct leads, with the latest qualifying action date in the chosen range. Historical events without an actor are not attributed by guesswork.
- Remove shared contact cards from Students and the Education report's shared pipeline chart. Remaining stage/pipeline figures stay departmental and are labelled accordingly. Other portals' reporting code is unchanged.
- Record dated calls, messages, enrolments/additional enrolments, offers and proposals/additional proposals. Show both activity count and distinct client count, with names, dates and details. Ten entries for one client are ten activities and one client.
- Keep School Enrolment as the existing stage. Record another school/programme as another accomplishment; no Re-enroll stage or repeat stage automation is needed. Accomplishments do not create an enrolment, send a proposal, change a stage or send emails.
- Require context for enrolment, offer and proposal entries. Each authenticated staff member can remove only their own entries; removal preserves a soft-deleted record. UUID submission keys prevent retries from adding the same entry twice.
- Store the three personal weekly text fields by user and Monday, separate from existing shared weekly notes. This week/Last week select the corresponding notes; other date filters explicitly display current-week notes. Unsaved drafts remain tied to their original week through live refreshes.
- Add a separate activities table and additive migration. No historical file or lead migration is included. Existing calls/messages/offers are not automatically inferred from internal-note keywords. Staff explicitly record accomplishments.

## Validation

- Backend: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Education tests/Feature/Reports/WeeklyReportsTest.php` — **57 tests, 814 assertions passed**.
- Frontend: `node tests/Frontend/education-personal-report.cjs` and `node tests/Frontend/education-report.cjs` passed.
- Production asset build passed. Existing duplicate-key warnings in untouched EducationEnrolmentPage.jsx remain outside this ticket.
- PHP syntax checks and `git diff --check` passed.
- Local headless Chrome exercised real login, staff attribution, two enrolments for one student, unchanged student stage, saved notes after reload, cross-account isolation, Finance Reports/access denial, mobile viewport and absence of browser runtime errors. All passed against the isolated loopback application with synthetic fixtures. Evidence is in local `artifacts/uy9udr/browser-results.json` and desktop/mobile screenshots; these are not production screenshots.
- Test databases contain synthetic fixtures only; mail/notification/queue operations are isolated. No live client records were used.

Staging validation remains required; local tests cannot establish the current production state or guarantee absence of every possible regression.

## Staging acceptance before closing

1. Deploy the reviewed branch to the confirmed staging environment, install locked dependencies, run the additive migration and build assets through the normal deployment process.
2. With separate Education test accounts, verify Nova's Contact Attempted does not appear in Emma's personal contact counts, students are excluded and unrelated users cannot mutate another user's activities.
3. Record two enrolments for the same synthetic student with different school/programme details. Confirm two activities, one client and unchanged School Enrolment stage. Repeat with offers/proposals, calls and messages across different dates.
4. Verify This week/Last week/custom date filtering, personal weekly note persistence, pagination/removal and mobile layout. Confirm existing shared notes and departmental stage cards remain correct.
5. Check another portal's reports and permissions. Ask Emma to approve the report layout and counting rules.
6. After approved deployment, verify with the requester, then complete the resolution report and mark Done. Until then, retain In review.

## Ticket documentation draft

**Issue summary:** Education Reports mixed shared lead contact figures into Students and did not capture repeated accomplishments or personal weekly planning.

**Root cause:** Department-level stage/register aggregation lacked actor attribution; stage transitions are not repeatable accomplishment records.

**Resolution:** Proposed branch adds personal contact attribution, dated repeatable accomplishment records and personal weekly notes. Existing student stages and shared portal notes are preserved. Pending review, staging acceptance and deployment.

**Verification:** Local backend and frontend checks/build passed as listed above. Staging and requester acceptance remain outstanding.

**References:** TKT-UY9UDR; branch `codex/uy9udr-education-accomplishments`; implementation and regression tests in this changeset.

**Follow-up:** Sir Ghamay reviews the changes and arranges staging deployment; Emma confirms reporting meets her requirements before closure.

**Documentation summary:** Personal activities count each recorded occurrence; stage cards continue to represent student milestones. Additional enrolments are activities, not a new stage.

## Suggested update to Sir Ghamay

Sir, TKT-UY9UDR is urgent because Emma needs it for her upcoming report, potentially Friday, 13 November, or Monday, 16 November; I am confirming the exact deadline. Contact Attempted was using shared lead figures, so Nova's actions could also appear in Emma's report. Stage cards also count students, so repeated enrolments/offers/proposals cannot be represented accurately there. I prepared an Education-only branch with staff-specific lead contact counts, dated accomplishment entries and personal weekly notes. Additional enrolments retain the existing School Enrolment stage. Local regression tests, browser checks and the asset build passed. Publishing is currently blocked by Git credentials/integration access, so the branch is local and a portable patch is available. Please prioritise review and staging validation before merge/deployment; the ticket remains In review, Urgent priority.
