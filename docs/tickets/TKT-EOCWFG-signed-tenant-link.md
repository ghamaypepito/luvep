# TKT-EOCWFG — Signed Accommodation agreement tenant linkage

## Findings
The current version-2 agreement workflow snapshots the approved Exalt signatory, waits for every issued client signer, renders a private signed PDF, creates/reuses an explicitly linked pending-payment tenancy and queues the signed copy. Historical agreements intentionally use the earlier signing path, which neither creates the tenant nor queues that client copy. An old Signed badge does not prove a tenant link or delivery. Tenant search also previously checked first and family fields independently, so full names spanning both fields could fail.

## Change
- Add a staff-only `Link tenant` review page for Signed rows and `Open tenant` for linked rows. Staff reviews the immutable signed PDF, explicit pre-tenancy source, property and exact existing record, confirms all required signatures and supplies an audit reason.
- Reuse a deliberately selected existing tenancy without changing identity, status, documents or paid amounts. Create a missing tenant only from validated original terms, in `pending_payment` status. Do not match or merge by name/email.
- Serialize by source, then agreement/property/tenant. Reject stale source/agreement/tenant data, inconsistent existing links, duplicate creation, wrong property, unsigned/missing evidence and archived targets. Link and actor audit commit together. No historical signing dates/PDFs, signature images or issued terms are rewritten.
- Permit the existing explicit standalone payment/move-in review for an audited historical link; onboarding remains on its existing controlled route. Linking itself never confirms payment, creates receipts or activates occupancy.
- Show linked pre-tenancy form/upload locations alongside signed agreements and tenant-owned uploads. Original private files remain in place.
- Full-name search spans first/family words and the linked signed agreement's client label, with property/status/archive filters retained.

## Boundaries and release
No migration, automatic production backfill, client signature, historical countersignature, bulk email, Finance mutation, payment-permission grant or unrelated portal change. Current version-2 signing/mail stays unchanged. Historical Signed evidence must be reviewed; a missing authorised signature is not manufactured and needs a properly reissued agreement. Historical email delivery is not inferred or automatically retriggered by linking.

The six requester examples still require private identification by exact agreement/source/property/tenant IDs, selecting the Signed entry rather than a separate Viewed entry. The browser service could not be reached during this review; no live record was inspected or repaired. Do not mark Done based on synthetic tests or a screenshot alone. After review/deployment, reconcile each exact row, confirm private document access and pending-payment search, verify mail delivery for new version-2 completion and obtain requester acceptance.

## Validation
Focused backend and actual React checks cover creation/reuse, original evidence preservation, pending-payment search, duplicate/conflict/stale forms, source routing, role/preview isolation, transaction rollback, full-name filters and existing agreement/payment/public flows. Full required CI is tracked on the PR; its exact revision and result belong in the release report.
