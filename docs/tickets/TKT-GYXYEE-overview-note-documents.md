# TKT-GYXYEE - Education Overview attachments in Documents

## Findings
Reviewed origin/main 0549d9cc and open PR titles. No equivalent change was found. Overview uploads are stored as LeadNote attachments on private storage, or references to Media Drive assets. The Documents panel reads checklist LeadDocuments and other unmatched LeadDocuments; it does not render note attachments. The screenshot is consistent with this separation, rather than proof of a missing file. The specific live upload was not opened or changed.

## Resolution
Add an Education-only Other Documents - Internal note attachments section to the existing profile Documents tab. Reuse the profile's existing note attachment metadata and the existing authorized note attachment view/download endpoints. Earlier notes and replies are included, and equal filenames remain distinct. No copy, migration, backfill, LeadDocument promotion, checklist status or approval change is needed. Note text is not repeated in the new section. The source remains the internal note, and deleted notes disappear after profile refresh.

The section is enabled in /portal/education profiles and the existing /admin profile route for Education-role users. Other portal profiles and admin-role profiles keep their existing presentation. This is a staff view, not a client-facing document request. If staff want to publish an attachment to a client or satisfy a checklist requirement, use the established document workflow deliberately.

## Verification and limitations
Tests cover direct uploads, older/reply metadata, duplicate filenames, private view/download links, missing file behaviour, wrong-record requests, client/Finance/Agent denial, deleted notes, Media Drive references, no duplicate LeadDocuments and other portal UI isolation. No application backend, routes, permissions, upload/delete logic or storage configuration was changed. Exact verification results and CI are recorded in the PR and handover report.

A missing physical file or a revoked Media Drive permission is not repaired by a display change; existing endpoint checks still apply. Checklist ZIP downloads and checklist completion totals continue to cover checklist/document records, while note attachments have individual downloads. No live client documents were inspected, restored or migrated.

## Release acceptance
Keep In review until Sir Ghamay reviews, merges and deploys. On staging with synthetic records and a designated Education test account: upload a PDF/image in Overview, post the note, open Documents and verify its name, author and view/download links. Repeat with an older note, a reply and a Media Drive attachment. Confirm existing checklist and Other uploads actions still work. Remove a test note and refresh; confirm its entry disappears. Verify unauthorized access is denied and client-facing Documents does not expose internal notes. Emma should confirm the deployed behaviour before Done.
