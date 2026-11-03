# Registration dates follow RSVP schedule

User requested dates follow November4–10. RSVP was already chronological, but live registration controller (hash matching2420161) sorted schools alphabetically and included hidden historical visits.

Applied only the registration catalogue query: honor existing is_hidden flag and sort mapped published dates ascending, with unknown dates last. No schedule dates, event records, form layout, or other intake behavior changed. Scoped patch stored alongside this record; branch source already has equivalent publiclyVisible()+date sorting.

New regression failed without sorting, passed with current branch sorting. Added hidden historical event coverage. Combined registration/page/catalogue ordering checks:3tests,6assertions, no failures; existing PHP8.5 deprecations. Staged controller PHP syntax and whitespace checks passed.

Deployment backup:/home/deploy/education-registration-date-order-backups/20261103-160115. Fresh live DOM verified registration data exactly:Nov4 Metro Manila,Nov6 Davao,Nov7 Digos,Nov8 Davao,Nov9 Tagum,Nov10 Clark. Fresh RSVP reload confirms same. No published Nov5 event; hidden historical St.Mary's Nov5 visit remains hidden. No dates changed or attendance submitted. Screenshot:education-rsvp-preview/registration-rsvp-date-order-live.png.

Main source integration remains pending draftPR15. No merge or broad deployment performed.
