# Luvep HR operations

Status: local implementation in progress on `codex/hr-portal`. Nothing in this document claims a production deployment. HR defaults off. Do not enable real employee mail or upload real confidential files during local/staging verification.

## Configuration

- `HR_ENABLED=false` is the module kill switch.
- `HR_HOST=hr.luvep.com`, `HR_URL=https://hr.luvep.com`, `HR_MAIN_URL=https://luvep.com`.
- `HR_SHARED_SESSION=false` until approved hosts, sibling-domain control, TLS and browser tests are verified.
- Shared session hosts are exact names in `HR_SESSION_HOSTS`; cookie domain `HR_SESSION_DOMAIN=.luvep.com`. The dedicated secure `luvep_shared_session` cookie safely migrates a valid main-site legacy session. Logout invalidates the shared session and remember token.
- HR routes accept the configured HR host, main host and deployment `APP_URL` host. Main `/hr` page links navigate to the dedicated HR origin; clock JSON stays on the main origin. HR host does not expose departmental application routes.
- `HR_NOTIFICATIONS_ENABLED=false` by default. Disabled events are not backfilled later. Use `MAIL_MAILER=log` or a designated test transport in staging; never route tests to staff recipients.

## Employee enrollment and access

People → Enroll employee creates a candidate from an existing internal active account. Candidates do not gain employee portal access. Activation requires a completed schedule and non-self Department Head and Operations/DTR reviewer. Only active internal staff and administrators are eligible reviewers. Confirm the actual employee roster and mappings with authorized HR before enabling real access.

Ordinary Admin has employee record/setup and preview access, plus existing attendance-review access. Confidential documents, medical/compensation/case files, policy publishing, fallback/emergency leave decisions and access administration require exact grants. A parent `hr` grant does not imply sensitive permissions. Never use broad module inheritance to bypass these checks.

“Login as employee” is an audited read-only preview. Authenticated identity remains the Admin. A persistent banner identifies the selected employee; all writes except exit/logout are blocked on both sites. The notification inbox is unavailable in preview.

## Attendance and DTR

Clock actions use server UTC timestamps, employee-row locks, optimistic versions and unique request IDs. Real breaks pause work time; live records do not also deduct the legacy configured break. Schedule/time-zone snapshots stay with each shift. Overnight shifts retain the starting work date. Flexi schedules without an explicit finish do not invent a finish reminder.

Both portals poll while visible and refresh on focus. Stale displays freeze after 30 seconds and disable actions pending refresh. Network-uncertain retries reuse the request ID. The break dialog restores after reload; closing the dialog keeps the paused banner and does not resume work. Clock-out is always manual.

The shift reminder is claimed once across both portals/tabs in the final 15 minutes. A late wake-up uses the remaining minutes. No reminder after finish/clock-out; preview simulation never creates a claim.

Daily DTR: draft → submitted → reviewed or returned. Submission includes an employee declaration. Returned records can be corrected and resubmitted. Closed clock corrections require the explicit attendance-correction grant and reason; before/after intervals remain in immutable review history. Changed daily records invalidate weekly sign-off. Historical rows remain historical and retain existing legacy report formats.

The new daily/weekly print view is scoped to the employee or permitted reviewer and supports browser Print / Save as PDF. Legacy team PDF exports remain separate. Weekly sign-off excludes configured holidays and approved full-day leave without a DTR; half-day leave still needs a reviewed record for worked time. Holiday configuration is operational data in `config/dtr_holidays.php`; HR must maintain approved calendars as dates are proclaimed.

## Leave and notifications

Sick leave can be filed on the same day or retrospectively. Annual leave under 14 calendar days requires an explicit Management exception; the Department Head still makes the final decision. HR fallback needs an explicit grant. No self-approval. Duration comes from the recorded working schedule and configured holidays, and is stored as a snapshot. Pending/deferred requests may be cancelled. Legacy types, signatures and historic decisions are preserved.

HR events and notification deliveries commit with the record. Initial queue dispatch occurs after commit. `hr:dispatch-notifications` sweeps pending deliveries every minute. Run the existing queue worker and scheduler; inspect `hr_notification_deliveries` for pending/failed status. Each recipient/channel/event is unique; database notifications use stable IDs. Mail retries use exponential backoff capped at five attempts. SMTP acceptance timeouts can still duplicate an email; no exactly-once external delivery guarantee is made. Error records store exception class, not transport details or message content. Recipients are rechecked before delivery. Messages contain no leave reason, health status or confidential record content.

## Verification evidence so far

- Isolated PHP 8.3 / SQLite tests; log mail only.
- Real loopback HTTPS browser hosts test shared sign-in, legacy cookie migration, remember-me, CSRF, logout, clock/break/resume, cross-tab state, failed actions, mobile layout and Admin preview.
- Isolated MySQL socket tests verify simultaneous clock-in and duplicate-request races without touching production.
- Frozen preimplementation baseline: 1,036 tests, 57 failures and 10 errors. Final comparison must name regressions against this baseline rather than claim an all-green preexisting suite.
- Production frontend builds pass with preexisting large-chunk and duplicate-key warnings in the education enrollment page.

## Deployment checklist — still pending

1. Finish all approved modules and independent final review. Fix findings and run complete regression, browser and MySQL checks.
2. Deploy additive schema/assets to staging using synthetic data and a mail sink. Verify employee journey and direct authorization checks.
3. Confirm real roster, Department Heads, Operations reviewers and confidential grants. No guessed activation mappings.
4. Verify Hostinger DNS-zone access (VPS access alone is insufficient), sibling-domain control and intended `hr` record.
5. Take a fresh database/config/application backup; configure Nginx HR virtual host, DNS A record and TLS. Run `nginx -t`, renewal checks and host smoke tests.
6. Promote through the fork deployment workflow; record exact SHA/run, queue/scheduler health, SSO, main-site public/departmental checks and test mail results.
7. Enable real notifications only after designated test checks. Record completion below.

Rollback: disable HR and shared-session flags as needed, revert application code, and retain additive tables and employee submissions. Do not drop HR tables to roll back a release.

## Deployment record

Pending. No HR DNS, TLS, production migration or staff notification enablement has been performed by this implementation run.

## Private documents, policies and support

Personnel files use the dedicated `hr_private` disk at `storage/app/hr-private`, with no public URL or filesystem serving route. Keep it outside the public storage symlink and include it in encrypted backups. Only PDF/PNG/JPEG are accepted, with content MIME and filename agreement, a non-empty body and a 10 MiB limit. Downloads are authenticated attachments with `nosniff` and private/no-store headers. Versions retain original filename, hash and creator privately. A release applies to exactly one version; future uploads remain private. Revocation checks run on every download. Document archive removes self-service access while retaining authorized HR history.

Published policy text is immutable and rendered as text. Drafts are restricted to policy publishers. Each employee acknowledges the exact published version displayed; a newer version needs another acknowledgement. The supplied private memo is not automatically published or committed as policy content.

HR request subjects and message bodies are encrypted at rest. Employees see their own request and public replies, never internal notes, hidden-note activity counts or internal revisions. Ordinary administrators see generic triage metadata unless explicitly granted case access. `cases.manage` grants global HR case management; `cases.assigned` plus a current assignment grants only that case. Reassignment revokes the previous reviewer unless they independently hold global management. Internal notes do not send employee notifications. General line managers do not receive confidential-case content or notices unless assigned with the required permission. Request attachments are intentionally unavailable until associated through the private document workflow.
