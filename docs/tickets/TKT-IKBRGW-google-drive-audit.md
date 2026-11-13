# TKT-IKBRGW — Google Drive document integration audit and record isolation

Status: **In review**. Existing implementation is present in main, but live configuration and acceptance have not been verified. Do not mark Done from source code alone.

Branch: `codex/ikbrgw-drive-record-isolation`, based on main `eb8802a2`. This is a safety/reliability fix to the existing integration, not a new Google Drive product or a production migration.

## What is already implemented

- GoogleDriveService uses a service account and configured Workspace Shared Drive. Approved LeadDocument files are copied from existing local/public storage into a client folder. Original portal files remain in place.
- Approval paths in LeadDocumentController, DocumentQueueController and ImmigrationAdviserController dispatch PushApprovedDocumentToDrive when configured. The job rechecks approval and has retries/backoff.
- The app stores gdrive_folder_id, gdrive_file_id and gdrive_synced_at. It fills student_gdrive_link only when there is no manually entered link. Shared Students views already expose Drive links.
- gdrive:sync-approved is an existing backfill command. Configuration that was absent when a job ran requires a later controlled backfill; dormant jobs do not automatically replay when configuration appears.
- The portal's separate Media Drive is private application storage, not evidence of Google Drive connectivity.

This is one-way approved-document copying. It does not establish two-way file synchronisation, a whole-database export, automatic migration of every historical document, or automatic revocation/deletion of remote copies when portal access/document status changes. Confirm which scope Fhilip intended.

## Concrete code gaps found

1. Unmapped client folders were located by name alone across the Shared Drive. Two distinct people with the same name could resolve to one folder. This is a potential cross-record document exposure risk, not evidence that a particular live incident occurred.
2. Concurrent/retried work had no local record lock; a remote upload could succeed while its ID was not committed locally. Retry recovery depended only on the saved file ID.
3. Folder mapping was saved before optional sharing succeeded; a retry could skip the failed share because the cached mapping returned immediately.
4. A missing source file returned without uploading, but the backfill incremented Synced. The existing command lacked a single-document verification scope.

## Proposed fix

- Find/create new client folders using private Drive appProperties with the Lead ID and the configured Shared Drive root. Append [Lead ID] to new folder names. Never adopt a legacy folder by a similar name.
- Serialize local sync work using parent/document database locks; recheck current approval and record membership. Use document IDs in new remote metadata to recover interrupted creates. Existing mapped files update in place.
- Verify remote folder/file location and available record metadata before writes. Reject conflicting local mappings (including archived clients), mismatched metadata, wrong-parent files, wrong drives and ambiguous identity matches for authorised review.
- Complete initial sharing before persisting the new folder mapping; check existing permissions on retries to avoid duplicate grants. Existing mapped folders do not automatically re-grant a reader after owner revocation. Legacy failed shares/configuration changes require owner review rather than silent new access.
- Preserve existing mappings and manual links where valid; do not rename/move/delete historical folders or files. Untagged legacy mappings are retained when not detectably conflicting; this is not a retrospective verification of their contents or ACLs.
- Return actual sync outcomes. Backfill reports Synced/Skipped/Failed and exits unsuccessfully for skipped/failed work. Add --document=<id> for a controlled approved sample; --all can be combined with that scope to recheck an already-mapped sample. No bulk command was run on live data.

No schema migration, new credentials or portal permission grants. Existing Drive configuration remains required. The shared approved-document sync service is affected; other portal upload/download handlers are not modified.

## Verification

All Google API operations in local tests are mocked; storage and database work use synthetic fixtures. No live Drive access, upload, sharing change or production file operation was performed.

The broader document/media regression run passed **60 tests, 785 assertions**, including the new Drive tests, MediaDrive suites, PlannedDocumentTicketsTest, LeadPortalSharedDocumentsTest and DocumentQueueTest. The final targeted rerun passed **12 tests, 156 assertions**. Full GitHub build/regression evidence is recorded on the PR.

Tests cover same-name records, no name-only legacy adoption, repeated sync/file reuse, recovery after remote creation with a lost response, sharing retry, conflicting folder maps including archived clients, wrong-parent file protection, revoked service access, missing source file reporting, disabled setup, unapproved notes/documents, sample-only backfill and respecting a revoked reader. Local tests do not establish real Drive ACLs or prove production connectivity/concurrency.

Drive record metadata follows Google's documented appProperties query mechanism: https://developers.google.com/workspace/drive/api/guides/search-files . Remote search recovery is not an absolute exactly-once guarantee; validate concurrent workers and transient failures against the designated test Drive.

## Configuration and closure checklist

Sir Ghamay / the authorised Drive owner must confirm:

1. Department/document scope and that one-way approved-document copying satisfies this request. Identify the owning Workspace Shared Drive and permitted staff.
2. GOOGLE_DRIVE_KEY_FILE points to a readable service-account file; GOOGLE_DRIVE_SHARED_DRIVE_ID is correct; the API, account and Shared Drive permissions work. isConfigured() only checks a file/config value exists, not that authentication or permissions are valid. Keep credentials out of tickets, source and shared documentation.
3. Review optional GOOGLE_DRIVE_SHARE_WITH and GOOGLE_DRIVE_FOLDER_PREFIX. The recipient is a global reader for newly mapped client folders, not a per-client portal permission rule. Drive membership/ACLs must independently exclude unrelated users.
4. Verify the deployment contains this code/migration history, cached config is current and queued jobs execute under the intended worker/runtime. Do not infer this from GitHub merge status.
5. Audit existing folder/file mappings for name collisions and inappropriate contents/sharing, including archived records. Compare any manual student_gdrive_link with the actual mapped folder. Any repair/move must match the verified record and be explicitly approved; this branch performs none automatically.
6. On isolated staging with a dedicated test Shared Drive, approve one synthetic sample, then use `php artisan gdrive:sync-approved --document=123` with the actual approved sample ID. Add --all only to deliberately re-sync that selected sample. Never use the bulk command as an exploratory live test.
7. Check bytes/file opens for the authorised owner, same-name records remain separate, unrelated Google accounts are denied, permission revocation remains effective and interrupted sync retries correctly. Test all intended approval routes. Portal access and Drive access are separate boundaries.
8. Record the deployment/test result and get Fhilip's confirmation that he can find the correct file in the agreed location. Then complete the resolution report and mark Done.

## Resolution report draft

Issue summary: Request to integrate portal documents with Google Drive.

Root cause / findings: One-way approved-document copying already exists, but live configuration, required scope and requester acceptance are unverified. The audit also found name-only folder matching and misleading skipped-file success counts.

Resolution: Existing service retained; proposed branch adds record-scoped matching, mapped-file guards, retry recovery and accurate/sample-scoped backfill. Pending review, setup verification, staging and live acceptance.

Verification: Synthetic automated results above; no live sample or external permission check completed.

References: TKT-IKBRGW; branch codex/ikbrgw-drive-record-isolation; GoogleDriveService, PushApprovedDocumentToDrive and SyncApprovedDocumentsToDrive.

Follow-up: Sir Ghamay/Drive owner confirms scope/setup and approves mapping review; test an authorised sample with Fhilip before closing. No production content was moved or shared during this work.

Documentation summary: Existing integration versus operational verification, record isolation fix, safe sample command, legacy mapping limitations and closure criteria.
