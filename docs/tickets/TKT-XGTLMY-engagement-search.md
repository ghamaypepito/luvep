# TKT-XGTLMY - Dedicated Engagement search

The Engagement list previously exposed only the global header search, which can navigate to Leads. Add a dedicated Search Engagement form for generated engagement records. It submits q to the current Engagement route, supports client name words, reference, email and phone, and provides Clear and an explicit no-results state.

The controller filters engagement documents by related client fields before the existing 300-document limit; this allows older matching packs to be found. Multiple name words can match separate name fields. Ordinary uploaded documents are not included. Existing route middleware, generation/signing workflow, activity log and global/Leads search are unchanged. The Immigration Adviser page reuses this component and controller.

Search remains limited to the existing newest 300 matching documents; this change does not introduce full pagination or alter pack grouping. Large matching result sets retain that existing limit. No database migration or permissions change.

Verification: actual React page test for submission/current URL, clearing, no-results and activity-log separation; backend tests for name/reference/email/phone, records beyond the unfiltered limit, exclusion of ordinary uploads, no matches, clear, validation and role denial. Existing EngagementWorkflowTest also exercised. Full regression/build validation runs on the PR. Release verification must confirm name search, no matches and Clear stay on the live Engagement route before closing the ticket.
