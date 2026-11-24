# TKT-WDZE1V — Education lead filter headings

Requester: Emma Ceballo. Confirmed scope: option A, remove Consultancy Agreement and Visa Process from Education's filter headings only.

## Findings and resolution

The shared lead page hard-coded six quick-filter headings for every portal. Education now displays All, New Leads, Contact Attempted, Qualified and Booked Consultation. Other portals retain the original headings. This changes only the heading row: existing records, saved statuses, stage pickers, advanced filters, Kanban, agreements and Immigration workflows are not modified. Records with the removed headings' statuses remain visible under All, subject to the existing pipeline and access rules.

No database migration, data update, backend permission change or historical-record correction is included. Earlier exploratory work to change stage availability/filter matching is deliberately excluded; it remains separately stashed pending no further action.

## Verification

The actual React lead page regression checks Education's two removed headings, all remaining quick filters, records with both existing statuses under All, switching Qualified/All, unchanged headings in Sales/Admin/Immigration/Agent, unchanged input records and zero submitted requests. The test is registered in full handover CI. Existing backend lead visibility and Education profile-note tests are also run using synthetic SQLite records. Final CI evidence is recorded in the PR and resolution report.

## Release and closure

Review and merge the isolated branch, deploy the frontend assets, then ask Emma to confirm Education > List of Leads no longer displays the two headings. Verify All and the remaining quick filters and one other portal. Keep the ticket open until that deployed check is confirmed. No migration required.

## Message for Sir Ghamay

Sir, Emma confirmed option A for TKT-WDZE1V: remove Consultancy Agreement and Visa Process from Education's filter headings. The branch implements only that UI change. Records and saved statuses remain intact, and other portals retain their headings. Actual React regression and existing backend checks are included; see the PR for final CI. Please review, merge and deploy, then Emma can confirm before Done.
