Ticket support upgrade — approved 2026-10-21
1. Add messages, event history, assignment and report fields without inventing historical transitions. Test ownership, active access and append-only replies.
2. Extend existing ticket board for assignments, waiting reasons, response targets, archive and enforced resolution report on Done. Preserve legacy replies. Record events atomically.
3. Queue new-ticket email to support@luvep.com with sender, urgency, assignee and ticket link. Existing database notifications remain.
4. Auto-archive only Done tickets resolved at least three calendar months ago; retain messages and reports. Isolated daily cron avoids enabling unrelated automation.
5. Analytics: filtered creation cohort with explicit date definition, all-history weekly throughput, response/assignment/resolution, tracked status/wait time, reopen events, documentation, topic and workload. Missing historical data null.
6. Integrate shared conversation, report editor/download and explicit reviewed documentation publishing. Verify tests, frontend build, independent review, production backup and deploy; inspect read-only live responses without sending synthetic production emails.
