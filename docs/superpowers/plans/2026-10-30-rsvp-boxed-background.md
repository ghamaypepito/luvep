# Boxed RSVP with the supplied EP backdrop

User steering replaces the initially proposed texture with the exact background from https://go.epathways.co.nz/widget/survey/6TmcjW9yE05r2SOC8qrS?notrack=true and asks for a boxed layout. Read-only browser inspection identified the existing faded EP photo collage body background, downloaded its displayed WebP asset unchanged. No form submissions to the reference site. Unpublished paper-grain candidate discarded.

Reuse that image outside the current RSVP surface. Center existing #education-rsvp at max1080px with 24px desktop outside gutter, 12px on mobile, solid existing automatic light/dark panel colours, restrained radius/shadow. Dark mode adds a dark green wash to the backdrop. Keep existing typography, images, form fields, selections, dates, notifications, pipeline and behavior. Disable decorative backdrop for printing/forced colours. No new theme toggle, runtime dependencies or image edits.

Two-file reversible visual change (CSS + exact source asset), no new application logic/schema/tests for this low-impact refinement. Verify actual light/dark render, responsive box width, visible fields/selection and no horizontal overflow; local-only static theme previews are removed before committing. Compare existing live CSS hash, back up and deploy only these files, then check live Tagum page and save privacy-safe screenshot.

Verified: PostCSS parse and git diff --check passed. Impeccable detector completed with no output/findings. Local forced-light and forced-dark rendering inspected; desktop panel capped at1080px with no horizontal overflow. Temporary preview HTML removed.

Deployed source commit f849f5a on 2026-10-30 UTC; live original CSS hash matched HEAD before deployment. Two visual files hash-verified after atomic replacement. Backup: /home/deploy/education-rsvp-box-backups/20261030-125548. Existing deployment metadata preserved with boxed_background entry. No application/database/cache/notification changes.

Live Tagum page reloaded and visually inspected: supplied collage renders around white boxed panel, blank attendance fields, existing Tagum7PM/date and venueTBA retained. Live box width1001px at1049px viewport,24px outside gutters, no horizontal overflow. Automatic light/dark remains; no theme control added. Screenshot: education-rsvp-preview/rsvp-boxed-live.png in parent workspace.
