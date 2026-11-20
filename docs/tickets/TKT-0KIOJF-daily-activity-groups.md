# TKT-0KIOJF: Daily activity groups

## Findings
Reviewed origin/main 0549d9cc. The Education personal dated activity list was a flat table, paginated at 30 rows. Daily collapse and daily totals were not implemented in that list. This is a display enhancement, not evidence of lost activity records.

## Resolution
Group the existing list into initially collapsed days, newest first. Each heading shows the date and number of recorded activities matching the selected activity filter. A server-side aggregate uses the existing owner, date, soft-deletion and activity filters before pagination, so a day spanning pages has an accurate total. A partial-day notice explains when more rows are on another page. Repeated activities for one client remain separate.

Existing create/edit/remove, filters, report totals and pagination remain available. Preserve open groups during refresh and return keyboard focus to the day heading if an edit is cancelled after collapsing that day. No database migration, permission change, other-portal code or activity write logic is changed. These are recorded activity counts, not Task Board completion counts.

## Verification
Local: 32 backend tests, 415 assertions passed across EducationPersonalReportTest and EducationReportPeriodTest. Actual React component interaction tests passed: education-activity-days, education-personal-report and education-report. Coverage includes owner isolation, date/kind filters, future/deleted exclusion, all-page totals, moves/removal, collapsed groups, refresh, edit focus, repeated rows and pagination. New interaction suite is included in CI.

## Release and acceptance
Keep In review until reviewed, merged and deployed. On staging, use a designated Education test account with synthetic activities on two dates, including repeat activities and a day spanning pages. Verify headings/counts, filter changes, expand/collapse, edit/remove and refresh. Confirm another user's activities remain excluded. Emma should confirm the deployed presentation matches her request. No live records were changed and production behaviour has not been verified.
