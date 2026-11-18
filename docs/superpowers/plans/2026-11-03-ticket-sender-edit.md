# Ticket Sender Editing Implementation Plan

**Goal:** Let the original ticket sender update their request and add private evidence on personal and admin ticket screens, including Done/Declined as explicitly requested.

**Architecture:** Shared owner/active/non-archived permission and request-content version on SystemTicket. A dedicated authenticated POST endpoint edits only request fields and appends attachments under a row lock. Existing support-owned fields and completed resolution remain intact. SystemTicketEvent retains request changes; a small edited timestamp signals updates. Shared React editor serves both boards.

**Tech Stack:** Existing Laravel/Inertia/React/Tailwind, PHPUnit and JSDOM; no migrations/dependencies.

## Contract
- POST /system-tickets/{ticket}/request: title (max160), description (max5000), category, priority, request_version (64-char opaque string), optional attachments[].
- Serialize can_edit_request (owner only, active non-external and non-archived), request_version (only when editable), request_edited_at ISO/null.
- Completed tickets editable; status, assignment, sender/department, resolution and original attachments unchanged. Append evidence only, combined legacy images + existing/new attachments <=10.
- Owner check and version comparison under transaction lock. Stale request edits yield a validation error under request_version; support triage changes alone do not cause a content conflict. Same submission without changes produces no duplicate event.
- request_updated event metadata: changes {field:{from,to}}, attachments_added count; actor and time from authenticated server context. No raw file paths in serialized output.
- UI shared TicketRequestEditor, only when can_edit_request. Existing details stay readable. Form offers save/cancel, failure retains draft/files, upload progress and inline errors. A completed-ticket note explains saving preserves status. Personal list and admin drawer render refreshed fields after save.

## Tasks
- [x] Backend RED regressions: owners across roles, non-owner/admin denial, external/inactive/archive denial, Done/Declined, allow-list, validation and concurrency, private append/limits/rollback, serialization and audit.
- [x] Backend owner/version helper, route and thin update integration; focused tests GREEN.
- [x] Shared editor and both board integrations with interaction regressions RED/GREEN. Frontend/backend may proceed independently against this contract.
- [x] Focused ticket suites, all frontend checks/build, fresh review, bounded desktop/mobile UI verification.
- [ ] PR validation, exact-head merge, production deployment and read-only verification. Do not edit a real ticket merely to test.

## Review and verification
- User explicitly chose editing completed tickets. Archive read-only behavior retained.
- Backend initial RED: 12 failures for absent route/props. Focused ticket suite GREEN: 59 tests, 460 assertions. Final sender-edit cases including filtered-search recovery: 16 tests, 214 assertions.
- Frontend regression observed RED then GREEN; all frontend CJS checks, 26 JavaScript tests and production build passed.
- Fresh review found no remaining material findings after the filtered-search drawer refresh edge was fixed.
- CUA inspected actual components with explicitly synthetic fixtures at desktop and 390px mobile; mock save preserved Done status and updated request fields/timestamp. No real ticket was edited for testing.
- On redirected board responses, updated_request is reauthorized by the current actor and ownership; it keeps drawers current when a rename leaves filtered results.
- Full local PHP suite passed: 1,637 tests and 9,760 assertions; final targeted sender-edit suite passed after the last delta (16 tests, 214 assertions). Existing dependency deprecations and large-chunk build warning remain.
