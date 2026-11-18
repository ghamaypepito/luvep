# Accommodation ticket resolutions — 21 October 2026

## #170 — Property manager payment schedules

The shared payment schedule options now include Every Monday through Every Sunday and Fortnightly. Existing Fortnight Friday, Auto Monday, and Manual values remain supported. The property form and server validation both consume this list. A regression test saves every option through the real property endpoint.

## #169 — Tenant documents

The tenant Documents tab now supports categorised uploads, a file list, and authenticated downloads. Categories are Passport / ID, Tenancy agreement, Inspection report, and Other. Accepted files are PDF, JPEG, PNG, DOC, and DOCX, with a 10 MB limit.

Files are stored on the non-public `tenant_private` disk; database metadata links each document to its tenancy. Downloads resolve through that tenancy and reject mismatched IDs. Accommodation staff and administrators can use the document-bearing detail page and document endpoints. External users and unrelated roles are denied even if assigned an accommodation portal grant. Upload failures during metadata persistence remove the stored file.

Existing Google Drive checklist flags remain separate; no Drive files are imported and no file availability is inferred from those flags. This resolution supplies uploads and downloads only; deleting files and migrating historical Drive content are outside these tickets.

Deployment must run migration `2026_10_21_100000_create_tenant_documents_table.php`, rebuild frontend assets, refresh cached configuration, and keep `storage/app/private/tenant-documents` writable and persistent with application storage.

Verification: focused feature tests cover all schedule values, private upload/list/download, tenant ownership, external users with portal grants, administrators, unauthenticated downloads, invalid file types/categories, and oversized uploads. Existing tenant/property controller tests are included in the regression run. Two pre-existing property image tests required their fixture to supply the currently required rental_mode field.
