# HR Overview team work insights

Goal: Extend the existing green Overview with current work in progress, completions, task updates and per-member progress. Keep Task Board work separate from self-reported DTR tasks.

Scope and meaning:
- Today / This week / This month control completion and update windows, with an explicit timezone and refresh time. Open workload and overdue counts are current snapshots.
- Completion counts use completed_at, not updated_at. Shared tasks count once overall and once per assigned person. Unknown completion dates are identified rather than guessed.
- Admins see active internal staff, heads/reviewers their assigned HR team plus self, other employees self. HR preview uses only the subject. Raw task titles and links remain limited to admin, creator or assignee; no client data or comment text added to HR summaries.
- DTR task counts use original work dates; recent report changes use real recorded timestamps. No report contents or raw revision values in Overview.

Implementation/checks:
- [x] Failing backend tests for accurate periods, shared assignments, task comments, staff scope, preview and sensitive-field exclusion.
- [x] Add Hr WorkInsights service and validated dashboard filters; no writes or schema changes. Aggregate full scoped datasets, paginate the member display, limit recent feeds only.
- [x] Add a branded Work insights section to the incumbent Overview with counts, team table and recent updates; refresh on demand and while visible.
- [ ] Frontend interaction tests, query-budget check, build, independent review and full CI.
- [ ] Merge/deploy through existing Actions and verify live read-only; preserve user records.

Review focus: period timezone boundaries, current workload versus period completions, duplicate co-assignees, team reassignment and archived staff, admin preview scope, restricted task titles/URLs, large task datasets, and direct-head access to daily-report metadata.

Validation so far: 158 HR tests pass; new Overview frontend interactions and existing DTR interactions pass; asset build passes. Independent review identified refresh transport failures bypassing onError. Added marked-request handlers for Inertia exception and HTTP 5xx invalid events, preserving normal access-denied handling; realistic Axios-header event tests reproduced failure then passed. Desktop sample inspection confirms the existing branding and functioning period controls.
