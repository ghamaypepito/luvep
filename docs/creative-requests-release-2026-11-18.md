# Creative Requests

System Tickets now has System and Digital destinations. Digital contains creative briefs with the six supplied sections, a separate caption, and private reference attachments. The existing picker supports ten files, up to 50 MB each. Existing System ticket data, conversations, reports and archives retain their behavior.

Each request creates exactly one shared task assigned to the active account configured by `CREATIVE_ASSIGNEE_EMAIL`. Configure the verified account and alert addresses on the server. Queue statuses are labels for the task's current status: Submitted, In design, For review, Delivered. The original brief stays intact; current project title and deadline follow task edits. Creator-only access does not allow changing creative-task progress through the shared Task API.

The assignee receives a database notification. An email alert goes to `CREATIVE_NOTIFICATION_EMAIL` with project, deadline and a sign-in link. Brief details and files remain behind authentication. Mail failure retains the saved request and displays a delivery warning. Missing or invalid alert configuration blocks submission rather than silently dropping an alert.

Both HR and legacy DTR show the employee's open assigned creative requests. The panel links to the brief without adding hours or changing attendance. Employee preview is read-only. Task Board details link to the full caption and references. The task editor now preserves unchanged deadlines and converts edited local times to ISO instants.

## Deployment

Run the normal staging-first release workflow and additive migration `2026_11_18_120000_create_creative_requests_table`. Confirm the configured assignee is an active existing internal account and the existing mail transport is configured for delivery. Do not use the local preview's `.env` or dummy credentials on a shared environment.

On rollback, revert the feature code while retaining the additive table and its data. Export briefs and private files before any destructive migration rollback. The SQLite branch also matches the existing production nullable lead_id contract for unlinked tasks.

## Proof

- 54 focused PHP tests / 395 assertions across creative requests, System tickets, task comments and HR DTR.
- Actual browser creation with caption and private image reference, queue/task status, DTR, existing System tab, and widths 1440/768/390/320 with no runtime errors.
- Committed local E2E includes the Manila unchanged-deadline regression, full task-editor Save and DTR visibility.
- Frontend build, Pint and diff whitespace check pass.
- Full PHP suite: 2,027 tests, 16,148 assertions, no failures; 2 skipped tests and 3 existing PHPUnit metadata deprecations.
- Review screenshots are in the parent `creative-requests-review-2026-11-18` directory. All are local synthetic records; outbound email used the log transport. Production rollout and real SMTP delivery have not occurred.
