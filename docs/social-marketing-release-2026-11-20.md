# Social & Marketing release — 20 November 2026

Flow: Brief → Drafts → Approval ↔ Revisions → For posting → Social Drive. Content Planner can attach a private Social Drive asset to a fresh draft requiring its own approval. Graphics, captions and silent animated vertical MP4 generation remain draft tools.

Active Admins (including Eireen) and Super Admins approve social posts and request revisions. The separate `social_publishing` module grant permits sending the exact current approved version to GHL; it requires Social & Marketing access. Grant it only to Sharry's verified existing account for this release, preserving her other grants and role. Ordinary Admins do not receive publishing automatically. GHL identity management and article approval/publishing remain Super Admin only.

The sending endpoint rejects drafts, review/revision states, stale versions, altered content/media, expired times and repeat sends before any GHL write. An uncertain provider result requires reconciliation rather than retry. Changes invalidate approval. No real post is sent during release verification.

## Deployment

1. Merge PR154 after the final regression gate and run the existing production deployment workflow. It installs versioned workspace assets, applies the additive brief/Social Drive migration and refreshes caches.
2. Confirm the deployed commit and migration. Add Sharry's explicit `social_publishing` grant in a locked transaction with an audit entry, checking the verified account ID, email, name, role and active state. Do not replace unrelated grants.
3. Verify role capabilities, private Drive access, the live Compose/Content Planner pages and the existing GHL connection readiness using reads. Confirm the anonymous Drive API remains inaccessible.

The production site's existing `public/.user.ini` allows 128M uploads/512M requests and Nginx allows 512M for production. Social validation remains images below 10MB and MP4 below 50MB. The separate staging virtual host has a 20M Nginx limit; it is unchanged.

## Rollback

Revert the squash merge and redeploy the preceding production code. Retain the additive `brief` column, `social_assets` table and private files; the migration down method intentionally preserves them. Remove only the explicit publishing grant if revoking Sharry's new permission. Never roll back or delete unrelated account grants, posts or files.

## Dependency audit

No lockfile or dependency changes. The 20 November audit reports seven high and one moderate package findings, all already in the current production lockfile. No affected execution path is introduced here: follow-redirects is the Node adapter (the app uses browser Axios and PHP GHL HTTP); Vite/editor and source-map-js operate on trusted build input with no production dev server; sharp is a development dependency unused by the deployed Social runtime; Flowbite/deepmerge receive developer-authored component configuration, not recursive attacker object graphs; xlsx has no application imports/bundled parser path. No new public Node server, spreadsheet parser or image decoder is deployed. Defer these existing package upgrades to a separate dependency review by 27 November 2026; do not run a forced bulk downgrade/major upgrade in this release.
