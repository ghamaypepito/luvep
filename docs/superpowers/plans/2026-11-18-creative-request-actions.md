# Digital request actions

User requested Mark delivered and Delete on the live Digital queue and explicitly chose recoverable deletion. Do not delete or complete any existing live request during this work. Do not send alerts.

## Ownership and behavior

- Reuse the existing task status endpoint for Mark delivered; only assignees/admins may use it.
- Admin-only Delete requires confirmation and moves both the creative request and linked task out of active views. Preserve brief, files, comments and original task status.
- Use Laravel soft deletion on both models. Keep ordinary lead-task deletion's existing behavior explicitly in its current controller; check all raw task queries and shared task APIs.
- Add an admin-only Deleted requests view with Restore. Restore both rows together. Hide deleted briefs/files from normal record routes and reject shared task mutation while deleted.
- Retain expected-status checks and row locks. Do not change attendance, complete deleted work, or notify recipients.

## Work

- [x] Add failing feature tests for Delivered, permissions, deletion/restore, file retention, DTR/task removal and ordinary-task compatibility.
- [x] Add soft-delete schema/model behavior and admin delete/restore actions.
- [x] Add row action controls and deleted view with confirmation, error and duplicate-submit handling.
- [ ] Add and run frontend interaction tests, relevant PHP regressions and build; complete required CI.
- [ ] Release through staging then production, preserving concurrent changes. Verify live controls read-only; leave existing requests untouched.

## Verification so far

- 19 Creative Request tests / 205 assertions passed, including file/comment retention, Delivered, restore, stale updates, permissions, ordinary task deletion and Finance file access.
- 143 focused ticket/task/Media Drive/HR regressions passed before the shared-task race fix; 68 creative/task/notification regressions passed after it.
- All 38 required frontend commands passed; the new interaction test checks confirmation, Cancel/Escape, safe focus, duplicate prevention, server errors and Restore.
- Production Vite build and Pint pass. Local browser flow passes at 1440, 768, 390 and 320px, including returning focus after Escape, Delivered removing DTR work, deletion hiding the record and restore retaining caption/reference/status.
- Inverting the deleted-view permission guard makes its regression test fail.
- Review found an in-flight shared task-edit race. Task updates now lock the active row inside a transaction before checking authorization; creative identity includes deleted rows; assignment alerts run after commit. Review confirmed the fix. Legacy lead task actions cannot mutate or permanently delete a linked creative request.
- Complete CI and staging/production verification remain release gates. Production checks will be read-only.
