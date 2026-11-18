# HR main-site rollout

Approved scope: replace the subdomain entry point with /portal/hr/dashboard, add a scoped month/week/day calendar, deploy, and keep employee access pending owner review.

1. Add main-site routes, navigation and pending screen; preserve login return and private permissions.
2. Add bounded calendar queries for authorized leave, own schedules/holidays and permitted deadlines; exclude reasons and private notes.
3. Verify HR regressions, build and real-browser main-origin journeys.
4. Fresh production backup, merge through the fork deployment workflow, activate main-site mode without shared cookies; verify live routes, worker, HR-only scheduler, notification transport and unactivated roster.
5. Record deployment SHA, checks and rollback.

Ruling: Main-site mode supersedes the earlier HR subdomain launch plan; no additional subdomain TLS setup is needed for this rollout.
