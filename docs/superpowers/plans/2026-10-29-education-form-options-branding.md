# Education form locations, time zones and EP banner

User browser comments request more event time zones, School/Business/Venue naming, and the EP mark instead of the ePathways wordmark inside the team image. Preserve hour-only manual time and current automatic light/dark appearance.

Use a shared native PHP IANA catalogue for public and schedule-editor choices/validation. Keep Philippines and New Zealand first, include UTC and group the remaining zones by region with city/identifier labels. No hardcoded current UTC offsets or timezone dependency. Broaden labels/placeholders and Other option while retaining field/API keys for compatibility. New form requests identify the field as event location; keep it in registration snapshots and notes and avoid inferring an applicant’s educational institution from a business or venue. Existing callers without the marker retain previous behavior and exact replay fingerprint compatibility. Existing education records remain intact.

Use the image-editing tool with the original banner and EP mark references; replace only its large wordmark, preserve people/layout/skyline. Copy approved generated asset into a sibling versioned project file and update only the registration team figure, with EP alt text. Preserve original asset for rollback.

Verification: RED→GREEN new timezone acceptance/invalid-zone rejection, broader-location metadata without fabricated school history, managed schedule timezone matching. Existing registration/RSVP/attendance suites; browser manual step and team image inspection; independent review of backend implications; targeted hash-checked backup/deploy and rollback-only synthetic live tests, no real registration/outreach sent.

## Verification ledger
- Initial three added tests failed for unsupported global zones. After implementation,63 focused tests /418 assertions pass (four existing PHP8.5 deprecations). New cases cover Dubai/New York plus invalid-zone rejection, London venue registration retaining existing real school history, and Sydney managed schedule authority. Existing legacy replay/education-history contracts pass.
- Independent code review found no correctness/data-regression blockers and verified all419 selectable IDs match validation with no duplicates/omissions. The generated banner’s asset weight was noted; packaged its unchanged pixels as lossless WebP (983KB versus1.5MB PNG) and kept lazy loading. Original JPG and generated master preserved.
- Local browser picked Other school/business/venue, entered Preview Conference Centre, chose London and9AM, and reached the contact step without submitting. EP banner loaded at2170x725 and visually inspected in context. No contact details or real registrations submitted. Mechanical template scan[], diff check passed.
