# TKT-WZUEDW — Education affiliate lead profiles and update dates

Requester: Emma Ceballo. Priority: urgent. Status: implemented in review branch; deployment and requester acceptance pending.

## Findings

The Education affiliate drill-down reused the shared AgentLeads table. Client names were plain text and only the creation date (Added) was shown. The existing backend already supplies the latest activity timestamp, author and description, falling back to the record's updated timestamp for legacy records. Existing Education profile routes work. No new route, permission, migration or identity-data correction is needed.

## Resolution

The Education wrapper enables clickable client names and an Updated column with date, time, author and activity description where recorded. Added remains the original creation date. Education reads refreshed server props after a stage update rather than retaining its initial row snapshot. Expansion, stage actions and existing access checks are preserved. The shared component's new option defaults off, preserving other portals' current display.

## Validation

- New backend regression: 2 tests, 70 assertions passed using synthetic SQLite records. Covers correct referral feed, existing profile route, stage refresh, legacy timestamp fallback, missing records and existing portal/agent access denial.
- Existing EducationProfileNotesTest: 7 tests, 71 assertions passed in the initial combined run; the only errors in that run were the new fixtures, corrected before the passing dedicated rerun.
- React/JSDOM test passed: actual Education wrapper and shared table, correct navigation without a write, separate Added/Updated dates, server-prop refresh, expanded/empty column spans and unchanged Sales/Admin/Sub-agent columns.
- New React test included in handover CI. Full clean-install CI result is recorded on the PR. Local production build is not evidence because this checkout has a pre-existing Swiper dependency mismatch.

No production client data was edited and no emails were sent. Existing timestamps are displayed, not reconstructed; missing historical metadata remains unavailable. Dates use the existing table formatting helpers and browser timezone behavior.

## Release / acceptance

Sir Ghamay reviews and merges, then deploys assets. No migration required. In staging, open Education > List of Leads > Affiliate Partners > View leads; click a client's name and confirm its profile opens. Return, inspect Added versus Updated, change a synthetic lead's stage and confirm updated metadata refreshes. Confirm existing expansion/search and other portal views remain usable. Emma confirms the deployed view before marking Done.

## Message for Sir Ghamay

Sir, TKT-WZUEDW (Emma, Education affiliates) is ready for review. Names were plain text and the list only displayed Added. The fix makes Education client names open their existing profiles and adds Updated date/time, author and activity details using existing backend data. Updated metadata also refreshes after a saved stage change. Other portals keep the current view; no backend permission changes or migration. Focused PHP and React checks passed; see the PR for full CI. Please review, merge and deploy, then we can ask Emma to confirm before closing.
