# System Ticket tabs, quick actions and two-month archive

User requirements: exact status tabs, Open by default, All last, Done only in Done (excluded from All), archived rows only in Archive. Admin row menu offers Decline, Done and Archive. Archive Done tickets two calendar months after resolved_at, inclusively; keep files, conversations and reports.

- [ ] Backend regression tests for each status, All/archive isolation, manual archive permissions/stale status/idempotence, two-month calendar boundaries and audit history.
- [ ] Normalize board filters on the server; hide archived tickets from unresolved counters and non-Archive lists. Preserve historical analytic aggregates while removing archived ticket detail rows.
- [ ] Add a transactional archive service, admin archive route and audited/idempotent scheduled archiving with dry-run support. Preserve unknown completion dates instead of guessing.
- [ ] Add a menu that escapes the table overflow, deliberate action confirmation, and preserve the existing completed-resolution requirement for Done. Move All last; hide status tabs in Archive.
- [ ] Test interactions, focused ticket regression checks, build and independent review; run full CI, deploy, verify scheduler and apply the retention rule once.

Review focus: archived/Done records cannot reappear through search/status combinations; actions retain owner/files/report; stale rows cannot overwrite current status; archives are read-only; auto-archive rechecks eligibility under lock; month-end dates, null resolution dates and repeat executions; menu keyboard/overflow behavior; production verification must not change live ticket statuses for tests.
