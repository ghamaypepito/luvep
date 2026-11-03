# Restore full-banner editor option

User reported full-banner option missing. The live content service and public template matched2420161, and live manager template hash7fde30 matched local historical source. Older service omitted banner_layout from schema/defaults; manager had no select renderer; public template always rendered split banner.

Restored only three files: content service's tested banner-layout support, select rendering in existing manager template, conditional full/split rendering in existing public template. Kept all other live template content unchanged. Existing restored CSS already contained full-banner width rule. Exact scoped patch alongside this note.

Seven content-editor tests against exact staged files passed (54assertions, existing PHP8.5 deprecations); all staged PHP syntax checks and whitespace checks passed. Backup /home/deploy/education-banner-option-backups/20261103-160446. Compiled views cleared.

Saved hero already had banner_layout=full. Saved hero SHA256 was identical before/after:bd46ec08159d985baf17d6e4bb2807435436ea875365759ac6aa518f2a42d607. No settings rewritten, photos uploaded, form submitted, or notifications sent.

Live browser verified manager Page content→Event page→Banner layout contains both choices and selects Full banner. Public data-banner-layout=full; active image and banner both992px; side panel absent. Current uploaded Philippines banner retained. Boxed1080px surface and collage retained; event dates remain4,6,7,8,9,10Nov. Screenshots:education-rsvp-preview/full-banner-option-restored-live.png and full-banner-restored-live.png.

Feature source remains on draftPR15 pending source integration; future full main deployment can revert targeted restoration. No merge or broad deployment performed.
