# Education school logos

User request: display a school logo before each school name on the public event list and allow staff to update it from Manage schedules and attendance.

Preserve the current page and automatic appearance. Use a consistently sized circular mark before the school label, contain each logo without cropping, retain the school-name badge when missing/unavailable, and allow long labels to wrap on mobile. Reuse the existing logo in the selected-event form.

Add an independent multipart logo upload form inside each school's schedule panel, with current preview and an Update logo button. Education/admin roles may upload PNG, JPG or WebP up to 2MB/4096px per dimension. Store randomized files on the existing public disk. Update only logo_path and audit the change; do not change schedule revision or send attendee notices. Keep prior assets for rollback and clean up a new file if its database update fails. No migration or new storage integration required.

Verification: first observe failing upload/publish/permissions/validation tests, then implement. Run RSVP and existing registration regression tests, PHP/JS syntax checks and a bounded browser visual pass. Targeted backup/deploy only the new logo route/controller/view/assets; preserve live customizations. Verify public images and protected upload route without creating applicant data. Update existing PR15 and report live links.

## Verification
- Upload/publish, unauthorized access and invalid/oversized/SVG tests failed against the missing route, then passed after implementation.
- A real local browser upload replaced Brokenshire's logo and the event-list image loaded from public storage. All seven rows had no horizontal overflow; missing logos retained name badges. Mechanical design scan returned no findings.
- Also corrected the standalone manager's Inertia handoff after sign-in. A current-asset request reproduced the invalid HTML response (200), then returned the required full-page location response (409) after the fix.
- Focused regressions: 37 tests / 208 assertions passed, four existing PHP8.5 PDO constant deprecations. JavaScript syntax, Pint and whitespace checks passed. The broader suite's previously documented baseline failures are unchanged; this scoped patch did not rerun the full suite.

## Live release
Deployed code `8c45600` with a preflight check against the existing live files, seven scoped file updates and preserved route customizations. Backup: `/home/deploy/education-school-logo-backups/20261025-065346`. No migration, event seed, notification setting or cron changes.

A rolled-back production upload verified the public image response (200), logo in public markup and upload controls for all schools. Schedule revision/outreach count stayed unchanged. Original school data restored and temporary upload removed. Dedicated public upload directory is writable by the existing www-data worker group. Live browser: seven school marks and no row overflow. Proof saved in education-rsvp-preview/live-school-logos.png.
