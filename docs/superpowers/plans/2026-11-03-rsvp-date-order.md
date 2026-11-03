# RSVP chronological event order

User requested the public event list run November 4 through November 10 from top to bottom.

The live controller hash matched historical 2420161 and returned database creation order; a fresh browser reproduced 10, 6, 7, 9, 4, 8 November. Live main metadata was f20a01b67d66c9a67d1c35e9d1f25a2a8429fd4f. Existing feature-branch controller already contained date ordering, but the live version had reverted.

Applied only a stable ascending sort by the published ISO date in the live page and catalogue methods. Unknown dates sort last. All other controller behavior and all event data retained. Exact scoped release patch is alongside this note; its source baseline was identified from local git history via SHA-256, with no production source export.

Verification:
- Two new ordering regressions failed against the live baseline (4 assertions).
- Both passed against the exact scoped release (4 assertions, existing PHP8.5 deprecations).
- Both plus public-page smoke passed on feature-branch code (3 tests, 8 assertions, same existing deprecations).
- PHP syntax and whitespace checks passed.
- One-controller hash-guarded deployment; backup /home/deploy/education-date-order-backups/20261103-002037.
- Fresh live browser confirmed: Nov4 Metro Manila, Nov6 Davao, Nov7 Digos, Nov8 Davao, Nov9 Tagum, Nov10 Clark. There is currently no publicly listed Nov5 event. No schedule records changed, RSVP submitted, or notifications sent.
- Screenshot workspace artifact: education-rsvp-preview/date-order-live-2026-11-03.png.

The feature-branch chronological ordering needs source integration to survive future full main deployments. Main deployment metadata was left unchanged.
