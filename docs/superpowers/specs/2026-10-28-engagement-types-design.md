# Engagement types and reviewed email

TKT-HTPSGC. The user confirmed three engagement types, automatic case details, and a separate Generate / Save as Draft / Verify & Email flow. The dropdown goes immediately before Signing adviser in the existing modal. Preserve its existing style and Immigration Advice Services documents, pricing and family controls.

Initial Consultation uses the supplied two-page agreement: USD 250, client/consultant names, consultation date, time, timezone, Google Meet link, original scope and terms. OIA uses the supplied authorisation: selected licensed adviser, client name, DOB, passport, address, email and phone; NZD 500 plus 15% GST. Missing required details are reported before generation. Signatures remain unsigned until the actual signing action.

Generate renders private temporary PDFs for inspection without saving client records or sending any notification. Save as Draft persists that exact pack and its settings. Verify & Email requires a saved, unchanged pack and opens a popup with all recipients, subject, actual rendered email and the exact PDF attachments. Only Send Email sends. Cancellation never sends; stale reviews must be repeated. Existing drafts also use this review gate.

## Approach

Extend the existing Laravel generators and React modal. Use private cached preparation/review tokens bound to the authenticated staff member and case, immutable PDF hashes and a send lock. Store pack identity/settings in existing document JSON metadata, retaining historical packs. Reuse the existing editable migration email template where configured, with actual PDF attachments and a signing link limited to this pack. An alternative of continuing to generate-and-email is excluded by the explicit requirement. A separate document application would duplicate client records and signing.

No live client messages during testing. This work does not alter external reference files or sign on anyone's behalf.
