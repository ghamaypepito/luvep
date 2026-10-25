# Attendance filters, exports and saved lists

User requests school filtering, exports and folders for attendance lists. They confirmed lists update automatically. Build in the existing protected Education manager, retaining the current page/editor/schedules. Folders/lists are shared by Education/admin staff.

## Design
Use one attendance query service for screen, export and saved filters. Criteria: school (all matching visits), event, exact email, attendance status and email verification, plus legacy kind compatibility. Saved lists store only validated criteria and names in folders; they query current RSVP records each time. Opening a list presets filters. Applying edited filters does not change the saved list until explicitly updated. New folders and named lists have unique names within their scope; lists can be renamed/moved/updated through the save form. No attendee duplication, deletion or public list links.

Add one migration for education_rsvp_folders and education_rsvp_saved_lists, with creator references and a default Attendance lists folder. Add school/event dependent controls, matched count, CSV/PDF actions and a compact Saved lists panel within Attendance. Retain pagination with attendance anchors. Separate forms for folder creation and list save; plain labels and feedback.

CSV streams all matches with UTF8 BOM and formula-safe cells. PDF uses existing Dompdf with a branded landscape layout and all matching rows up to500; larger PDFs return a clear error directing staff to filter or use CSV (never truncate). Exports respect exactly the displayed criteria, show TBC for unpublished schedules, and include attendee/contact/school/event/attendance/verification fields. Role guard, CSRF, private/no-store headers and audit apply. Exports are downloaded, never written to public storage. Saving filters/folders sends no messages and creates no leads.

## Implementation / verification
1. Failing feature tests for school across visits, combined filters, exports beyond current50-row page, formula safety, filtered PDF, role restrictions, folder/list save/open/update and new matching RSVP reflected dynamically.
2. Add migration/models, shared query service, guarded folder/list/export controller routes; refactor index to consume the same filter context. CSV/PDF renderers and audit.
3. Attendance partial and scoped style/JS updates, preserving the manager’s other sections. Check local browser create-folder/save-list/open/filtered export flow and inspect a synthetic PDF.
4. Focused regressions, syntax/Pint/mechanical checks, independent review if needed by scope, targeted backed-up migration/deployment and rollback-only live checks with synthetic records. Update existing PR15 and provide links/proof. Preserve existing contacts, event schedules, settings and crons.

## Verification ledger
- RED: initial five tests failed for missing school filtering and missing endpoints.
- GREEN: seven new tests plus existing seven focused feature suites: 53 tests,326 assertions pass (four existing PHP8.5 PDO deprecations). School across visits, status/verification, all56 matching CSV rows beyond page50, formula-safe cells, actual filtered PDF, folder/list persistence/move/rename, dynamic new arrivals, clearing filters, authorization and PDF501-row rejection covered.
- Additional RED→GREEN: folder creation originally lost current school selection; now preserves effective filters. Strict validation precedes unique-list ID handling for malformed metadata.
- Independent final review found no Critical/Important issues; its stale-school zero-value display finding was treated as a filter-consistency defect and fixed. Scoped dropdown assertion failed before replacing empty() with isset()/containsStrict(); focused suite passed afterward. No deferred findings.
- Browser: local school filter scoped the event menu; creating School follow ups retained the school; saving Tagum attendance reopened a live list in that folder with its original school criterion. Dark automatic theme inspected. CSS/JS mechanical detector returned[]; JS syntax and git diff checks passed.
- Synthetic32-person PDF rendered to four landscapeA4 pages. First-page typography, EP logo, table and footer inspected; text extractor verifies filtered attendance. No actual contacts exported for QA. PDF fixture remains temporary, not a deliverable.
- Full repository suite is not claimed green; prior release's54 unrelated baseline failures are documented in the existing editor verification record. Current validation is focused on the changed workflow.
- Live release9344340 deployed with backup `/home/deploy/education-attendance-backups/20261025-081044`; live file preflight matched12 scoped files and existing route customizations were preserved. Only migration080000 ran.
- Rollback-only live MySQL check passed: folder/list creation, new RSVP arrivals updating the list, complete CSV, actual parsed PDF, private headers and zero outreach. All synthetic contacts/folders/lists were rolled back. Default Attendance lists folder is intentional.
- Live authenticated browser showed school/status/verification filters, CSV/PDF, Save list and shared Attendance lists folder. Privacy-safe proof screenshot excludes attendee records: `education-rsvp-preview/live-attendance-lists.png` in the parent workspace. First and last pages of synthetic PDF visually verified; repeated headings and page footer render correctly.
