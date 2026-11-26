# TKT-HOKBXI Purchase invoices and supporting receipts

Janille requested invoice generation/upload, private supporting receipts, tenant/property links and transaction history. PRs #69 and #83 introduced this Accommodation-only workflow, with staff-entered invoice numbers and GST and email-verified viewer sharing.

The 20 November 2026 correction includes receipts nested under an invoice's folders in tenant and property history. Invoice lists batch the relevant root trees; downloads resolve files only inside the selected invoice's subtree, preserving isolation from sibling invoices and other tenant folders. Existing files and folder organization are unchanged.

The regression creates two invoices for a synthetic tenant, uploads a receipt two folders deep, verifies both histories and its download, and rejects download through the other invoice. Existing generation, upload, private sharing, rollback and historical-property tests remain applicable. All mail is faked; no client invoices, receipts, sharing links or emails are created during release verification.
