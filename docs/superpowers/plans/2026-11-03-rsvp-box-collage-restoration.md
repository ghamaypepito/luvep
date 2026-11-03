# Restore approved RSVP boxed layout and collage

User reported RSVP had returned to full width and lost its collage backdrop, and requested publication to live.

Evidence: production deployment metadata d1704cf6635e898e010b75de5f1045157db5bdfb; successful Actions run 41564604642. Live stylesheet, RSVP template and content-service hashes all matched historical 2420161. Collage asset was missing. Production workflow uses rsync --delete; feature branch still contains approved styles/assets absent from main.

Published only the approved RSVP stylesheet and existing collage asset, after dependency and target hash checks. Asset installed first, CSS second. Backup /home/deploy/education-box-collage-backups/20261103-155515. Retained current page text, uploaded banner, event records, and main deployment metadata.

The same full deployment had reverted chronological ordering. Reapplied the earlier two-expression controller patch against identical verified baseline; backup /home/deploy/education-date-order-backups/20261103-155642. No new controller behavior introduced beyond that already tested ordering fix.

Fresh live browser verified max-width1080px, actual width1080px in1280px viewport,20px corners, computed collage URL with existing dark-mode wash. Collage public HTTP200,image/webp,57826bytes. Screenshot shows restored page, at education-rsvp-preview/boxed-collage-restored-live-2026-11-03.png in workspace. Reload also verified event dates4,6,7,8,9,10Nov ascending. No form submitted or notifications sent.

The feature source remains on draft PR15. Future full main deployments can overwrite this targeted restoration until source integration; no merge or broad deployment performed.
