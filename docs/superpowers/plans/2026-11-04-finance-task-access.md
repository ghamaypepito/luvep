# Finance assigned-task access — TKT-YOV3CM

Confirmed production evidence: Finance submitter is active; recent TaskAssigned notifications point to /admin/tasks or /admin/leads despite Finance-only portal access. One notified task is a secondary assignment, which the Finance Mine query currently excludes. Screenshot shows an Inertia 403 error over the Invoice screen.

Fix task destinations rather than widening account access. New and existing in-app assignment notifications must lead to the recipient's own task board with the target task selected. The shared board opens the existing TaskDetailModal using only tasks returned by its authorized controller. Every newly targeted department Mine board must include primary and secondary assignments through one shared scope and reject arbitrary scope bypasses. Finance notifications must use its own layout. Existing task records, assignments, completion state and notification read state are preserved.

1. Regression tests for role-safe new/legacy notification links, Finance own task board access, co-assignee inclusion and unrelated-task exclusion.
2. Centralize recipient task-board destinations for assignment notifications and their serializer; normalize legacy links on read without database backfills or resending emails. Existing task-assignment emails already use role-safe board URLs.
3. Add scoped task focus to the shared board and verify it opens, closes and refuses unknown task IDs. Restore Finance notification layout.
4. Run focused/full checks, code review, CI and production deployment. Read-only verify Lucksia's actual tasks and normalized links; update ticket resolution for requester confirmation. Do not edit a real task during verification.
