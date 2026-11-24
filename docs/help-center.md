# Dashboard Help Center

The Help Center is a staff-only guide directory at `/help-center`. It uses Luvep's existing role layout and is linked beside Settings in the shared sidebar, from the Admin Settings page, and from HR navigation.

## Behavior and access

- Search guide titles, summaries, department labels, topics and keywords. Multiple words are matched together, ignoring case and extra spaces.
- Department and search filters intersect. Clear filters restores the catalogue and returns keyboard focus to Search.
- Guides open their stable Google Docs URL in a new tab. Google Drive remains the content source; editing the document updates what users read without a Luvep deployment.
- All active internal roles can open the directory. External clients, affiliates and sub-agents cannot, including when they hold an extra department portal grant.
- Administration, technical operations and documentation-owner guides are omitted from non-admin responses. Drive permissions separately control access to the actual documents; the dashboard does not proxy or bypass them.
- No database migration, provider call, paid service, new dependency or background sync is involved.

## Maintain the catalogue

Edit `resources/help/guides.php` when adding/removing a guide or changing its title, topics, department classification, description or URL. Guide-body edits and screenshots are maintained in Drive. Search covers this catalogue, not the complete live Google Docs body.

Stable index: https://docs.google.com/document/d/1RlLl3HpywJY0--Pq5dDYY_iPGG-XkxUr9vh94u7Xby4/edit

Knowledge-base owner records changes and pending verification in KB 14. Do not copy credentials, client documents or employee records into catalogue text. Sharing the directory does not grant staff Google Drive access.

## Design and scope

The user's live Taste Vault was queried for knowledge-base/help-center/dashboard references on 19 November 2026; three dashboard screenshots were viewed. Adopted decisions: quiet neutral surfaces, persistent sidebar orientation, compact green selected states, readable list hierarchy and mobile-wrapping filters. Existing Luvep components and spacing own the final appearance.

The user selected support@luvep.com for reader access to the existing guide and screenshot files. Existing ownership and other permissions are preserved. No public link-sharing grant is added.

## Verification

Backend: staff-role matrix, guest/external/inactive denial, server-side admin-guide filtering and valid guide/department references.
Frontend: case/space-insensitive multiword search, department/query intersection, keyword matching, empty state, reset/focus and safe external links.
Browser: Settings entry, actual Help Center interactions, mobile/keyboard layout and read-only external-link behavior. Record current results in the PR; do not infer deployment from local checks.

Rollback is a code revert; there are no data migrations. Keep the Drive guides and their URLs.
