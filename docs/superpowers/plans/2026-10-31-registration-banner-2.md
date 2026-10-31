# Supplied registration banner replacement

Requested: replace only the selected team banner on /education/register with Banner-2.jpg.

- Copied the supplied JPEG byte-for-byte to public/education-landing/education-team-banner-2.jpg.
- Updated the single landing-form image reference and intrinsic dimensions to 1800 × 600.
- PHP syntax and git diff whitespace checks passed.
- Published only the new image and landing-form template after validating live source hashes. Existing assets retained. Backup: /home/deploy/education-banner-2-backups/20261031-085950.
- Fresh live browser verified the new URL, complete image load, natural dimensions 1800 × 600, displayed dimensions 700.9609375 × 233.6484375 (same 3:1 ratio), and visually confirmed the supplied artwork.
- Screenshot: ../education-rsvp-preview/banner-2-registration-live.png (workspace artifact).
- No form submitted and no notification sent.

Feature remains on draft PR15; a future full main deployment can overwrite the template until integrated.
