# Education event management access and navigation

User requests access for Emma and Eireen to the existing RSVP manager, easy portal navigation and guidance for future events. Production account verification identifies Emma Ceballo (ID 2, active Education role) and Eireen Serrano (ID 64, active Admin role). Both already pass the current portal middleware and render the schedule manager with status 200. No role, credential, module or portal grants are necessary. Emma retains normal event/content/attendance management; Slack credentials remain administrator-only. No messages to staff requested.

Add Events & RSVP in the Education Work menu after Bookings and Education Events & RSVP next to Events in the Admin menu. Keep the existing manager route and Inertia location redirect. Clarify its heading and Events & schedules section, add future-event guidance and use School / event name for general city events and meet & greets. Preserve legacy anchors, filters, records and forms. Future Education events belong here; programme/course catalogues remain under Programs.

Validation: 31 existing RSVP, managed schedule and content tests completed with 203 assertions and no failures (existing PHP 8.5 deprecations). Impeccable detector reported no findings. A read-only production harness verified both exact accounts against the real portal middleware/controller and rendered the manager; an initial CLI-only render lacked the session errors share, corrected in the harness before rerunning successfully. No account permissions were modified.

Automatic approval review rejected exporting a broad frontend snapshot to the workstation. Safer implementation builds on the server from its existing frontend source and lockfile, with targeted source replacements, original source hashes, private backups and old compiled assets retained. No broad source download. Current Admin layout and schedule-fields template differ from this branch, so apply only exact scoped replacements to their live versions.

## Live verification

Server production build passed using the live lockfile. Existing duplicate-key warnings in EducationEnrolmentPage remain outside scope. Deployment source checks matched; four scoped source/template edits plus compiled dependency assets and manifest were hash-verified (394 changed files). Previous compiled assets retained. Private backup `/home/deploy/education-events-nav-backups/20261030-132144`. Main deployment source metadata unchanged.

After deployment, the real middleware/controller rendering check again returned200 for Emma ID2 and Eireen ID64, with administrator-only Slack settings preserved. No account grants or role changes. Browser confirms Events & RSVP in Education Work menu and Education Events & RSVP in Admin menu; both links navigate successfully through Inertia to the manager. Add schedule expands the existing form with clearer event-name copy,7PM default and publication controls. No schedule or attendee changes submitted.

Privacy-safe screenshots in parent workspace education-rsvp-preview: education-events-menu-live.png, admin-education-events-menu-live.png, future-education-events-guide-live.png.
