# TKT-YOV3CM — Finance task notification access

## Problem and change

TaskAssigned stored Admin destinations for every recipient. Finance staff
following the bell could therefore reach a portal they cannot access.

Finance recipients now receive a Finance task-board URL with the task ID.
The notification read path also resolves old TaskAssigned rows to that URL
without modifying saved notification history. Finance's full notification
inbox uses the Finance layout. Other roles' notification destinations remain
unchanged.

The Finance My tasks query includes primary and additional assignees. A
notification opens the existing task detail modal only when the requested
task belongs to the current user's assignment queue, including tasks older
than the board's 1000-row display limit. Existing task API permissions remain
in force. No migration or production data rewrite is required.

## Automated validation

Regression coverage: FinanceTaskNotificationTest checks linked/unlinked
task destinations, opening the assigned task, historical bell/inbox URLs,
additional-assignee visibility, unrelated-user exclusion, task comment/update
denial, and missing task IDs. Existing Admin destinations are also checked.

Local PHP and Composer are unavailable. Backend tests must pass in GitHub
Actions before this proposal is treated as verified. A passing build is not
requester acceptance.

## Staging acceptance — pending

Use synthetic records and designated test accounts. Do not use production
credentials or send real assignment emails during this test.

1. As Admin, assign a linked task to a Finance test account. Click its bell
   notification as Finance; confirm the task modal opens in the Finance portal.
2. Repeat with an unlinked task and a task where Finance is an additional
   assignee. Confirm each appears in My tasks.
3. Verify a historical TaskAssigned notification carrying an Admin link works
   from both the bell and the full inbox without editing the stored row.
4. Open comments and save an authorised task update. Confirm it persists.
5. As an unrelated Finance test user, request the same task URL; confirm no
   selected task is returned and comment/update requests remain denied.
6. Check an Admin assignment notification retains its existing destination.
7. Record the staging commit, tester, date, outcomes and any remaining errors.

## Release and closure

Keep the ticket In review until automated checks and staging acceptance pass.
Sir Ghamay reviews the PR and controls the production merge/deployment.
After deployment, ask the requester to confirm the original assigned task
opens and can be actioned. Only then complete the resolution report and
mark Done. This document records a proposed fix, not a completed resolution.
