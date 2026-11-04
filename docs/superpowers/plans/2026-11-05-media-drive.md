# Media Drive Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans to implement this approved plan task-by-task. User approval already covers the preview and rollout; proceed without another design approval.

**Goal:** Ship the approved private Media Drive and client attachment picker across Luvep portals.
**Architecture:** Laravel folder/file/share/challenge/activity records, private immutable assets, existing client record access, Inertia staff/client screens and a reusable React picker. New LeadDocument/TenantDocument asset references preserve file identity and existing review behavior.
**Tech Stack:** Laravel 12, PHP 8.3, Inertia React, Tailwind, existing mail transport and private local storage.
**Spec:** docs/superpowers/specs/2026-11-05-media-drive-design.md

## Global constraints
- Preserve existing client profile layouts and legacy file paths/workflows.
- Invited-email OTP required; Viewer view/download, Editor additionally upload.
- Client uploads awaiting review; never grant approval from sharing/upload.
- New folder content private until explicitly shared; no automatic historical-file migration.
- Never broaden existing record scopes or expose HR employee files.
- Multiple selections: ten files, 100 MiB each, individual queued upload requests.

## Review focus
- Cross-client/folder/file IDs, recruiting and explicit portal grants: authorized query and attachment tests.
- Expired/revoked/role-changed links, guessed/replayed/resend OTP and session isolation: shared access tests.
- Failed upload/DB write, repeat request key, duplicate target attach and document delete/replace: immutable asset/lifecycle tests.
- Picker filtering, queued upload errors/retry, cancellation and stale fetch: frontend interaction checks.
- Legacy document rendering/review and all portal navigation: regression suite and live browser checks.

### 1. Private folder/file foundation
Files: migration create_media_drive_tables; Models/Media{Folder,File,Share,Challenge,Event}; Services/MediaDrive/{Access,Library,Uploads}; Http/Controllers/MediaDriveController; routes/media-drive.php.
Interfaces: Access::leads(User): Builder, Access::canUse(User, root): bool; Library folder tree and serialized private files; Uploads::store(folder,file,actor/share,uploadKey): MediaFile. File paths never leave server.
- [x] Write and run failing tests for record scopes, nested folders, duplicate roots/names, file MIME/size/private streaming, upload retry/cleanup, and inaccessible client IDs.
- [x] Implement additive schema, access, private file/folder endpoints and audit records; run foundation tests and baseline ticket checks.
- [x] Commit foundation.

### 2. Verified client sharing
Files: Services/MediaDrive/Sharing; Http/Controllers/MediaShareController; Mail/MediaDriveCode; views/emails/media-drive-code.blade.php; public share routes and tests.
Interfaces: Sharing manages hashed/encrypted links, challenge/code delivery, session grants and per-request subtree checks; staff share create/update/revoke endpoints; client GET/request-code/verify/upload/download endpoints.
- [x] Write and run failing tests for Viewer/Editor boundaries, no unverified metadata, expiration/revocation/versioning, five attempts, session binding/replay/resend, email delivery failure and ancestor escapes.
- [x] Implement verified sharing and branded transactional code email; run sharing/foundation tests.
- [x] Commit verified sharing.

### 3. Client attachment integration
Files: Services/MediaDrive/Attachments; LeadDocument/TenantDocument model references; MediaDriveController attachment endpoint; LeadNoteController; LeadDocumentController lifecycle protections; TenantDocumentController download; tests.
Interfaces: Attachments::toLead(files,lead,key,actor) / toTenant and note reference resolution, all reauthorize client/file IDs; repeated same-target references retain their review status and new targets start Submitted. Repeated same-target attachments are idempotent. Existing delete/replace does not remove Drive bytes.
- [x] Write and run failing cross-client, idempotence, review-preservation, note reference and delete/replace tests.
- [x] Implement reference attachments with no blob copies, compatible existing document serialization/downloads; run focused legacy document/note tests.
- [x] Commit integration.

### 4. Approved UI and portal entry points
Files: pages/media/{Drive,Shared,ClientLinks}; components/media/{MediaDialog,FileBrowser,UploadQueue,ShareDialog,MediaPicker,MediaAttachButton}; scoped media CSS; layouts/app resolver; shared LeadDetails/DocumentsTab/notes and TenantDetail.
- [x] Add frontend tests that fail for scoped picking, queue retries/progress/errors, sharing controls/OTP and permission-specific controls.
- [x] Build the approved EP green/neutral file library, real client/folder forms, navigation/search/list-grid, preview/activity/share/revoke flows and public email-code screen.
- [x] Add Media Drive navigation to each staff/recruiting portal; existing client portal links only to its own invited shares; HR link to main client library. Add picker beside Upload in shared client profiles/checklists/notes and tenant documents.
- [x] Run frontend tests/build, inspect desktop and mobile together, apply one bounded polish pass.
- [x] Commit UI.

### 5. Verification and deployment
- [ ] Independent whole-branch review; fix meaningful findings with regression tests.
- [ ] Full regression CI and build; migrations checked against current schema; confirm production upload limits.
- [ ] Merge reviewed head through GitHub Actions, verify deployment/migrations and live portal/share gating without real-client writes or outbound client emails.
- [ ] Record verification and provide live links/screenshots and any real credential/delivery limitation.

## Execution ledger
- Base: latest main 0dff2f399b63325a12050162d4c792fe63f244a4, isolated linked worktree, branch codex/media-drive. Clean existing baseline: prior full CI 1,680 tests / 10,965 assertions + frontend/build. Reuse installed dependencies.
- Ruling: use current portal access and include standalone tenants; retain HR employee-document separation. External agents remain own-referral-only and cannot share externally. Existing profile design and prototype stay intact.

- Ruling (builder check): review decisions stay on contextual LeadDocument references; never propagate approvals across targets. Attachment identity includes checklist/request/dependent/category context. Public access rechecks soft-deleted owners, share version and subtree on every request and at upload commit. Asset storage is immutable; only new uploads become MediaFiles, not generated legacy files.

- Foundation/verified-sharing verification: 18 feature tests / 225 assertions plus 7 existing ticket tests (combined 25 / 421) pass. Watched missing-route RED runs, then GREEN. Tests preserve browser session cookies for real OTP/session-binding behavior. Shared-route throttle prefixes are separate so nested limits do not share counters.

- Ruling: avoid holding a folder lock before a share lock during uploads; recheck the locked share at commit, enforce unique payload-bound upload keys, then touch folders. This avoids lock-order cycles with verification audit foreign keys.
- Production limits read-only check: nginx 512M, FPM override upload_max_filesize 50M/post_max_size 512M, free storage 74.3 GiB. Add a Luvep-public-directory .user.ini at 128M/512M; app still rejects >100 MiB. Verify effective FPM limit after deployment.

- Integration/UI verification: 34 Media Drive feature tests / 525 assertions pass, plus scoped picker, deferred upload busy/target protection, attachment save/retry feedback, Drive share/revoke confirmation, OTP and Viewer/Editor frontend tests. Production SMTP confirmed; prerequisite tables present; additive migration completed in isolated SQLite from current schema.
- Independent review identified cross-session code replacement, upload tab cancellation deadlock/destination drift, explicit finance grants and access audit coverage. Fixed with regression coverage; verification session grants now commit only after a successful audit transaction. Download/preview and folder-open events include staff or share identity, note reference events are atomic.
- Browser local QA uses /private/tmp/luvep-media-qa.sqlite, synthetic Avery Sample client and log-only email. Verified library navigation, file preview/details/review retention, share controls, successful email-code flow, editor folder view and mobile 390px layout. No production client data or emails used.
