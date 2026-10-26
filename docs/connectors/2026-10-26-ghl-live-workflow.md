# EP GHL live workflow

User selected full saved approvals and GHL scheduling on 2026-10-26. Existing EP token/location is fixed to EPwLVKAvmJdF8IvwBLwO. The selected posting destination is Luv, Ep (Facebook), account 6acefdbae422a0c5111f01d3_EPwLVKAvmJdF8IvwBLwO_105394802181267_page. ThumbAI must remain unselected. Read accounts/posts/statistics are verified; statistics requires locationId as query parameter.

Approach: preserve the current Luvep shell, Claude tools and old integrations; add a separate persisted marketing workflow and use GHL directly. Browser-only approvals can never schedule. Admin portal users may create/edit/submit drafts; Super Admin approves, requests changes, and schedules. Every edit resets approval. Approval covers exact content, media hash, destination and proposed NZ date/time. Scheduling accepts only an existing approved ID/version; no client-supplied content can override it. Concurrent changes are rejected. Ambiguous remote outcomes cannot be retried blindly.

Contract (all under auth + portal:admin, except signed media):
- GET /social/marketing/posts -> {posts:[shape],can_approve:boolean,ghl_user_configured:boolean}
- POST /social/marketing/posts multipart fields campaign,service,caption,schedule_date (optional YYYY-MM-DD),schedule_time(optional HH:mm),media(optional image), plus optional id/version for update -> {post:shape}
- POST /social/marketing/posts/{id}/submit body {version}
- POST .../{id}/approve {version}; POST .../{id}/changes {version,note}
- POST .../{id}/schedule {version}; does not accept content/media/date overrides
- GET .../{id}/media (authenticated); named route marketing.media.shared GET /social/marketing/media/{id} signed URL, approved/scheduled matching current media only.
shape: id(string/uuid),version(int),campaign,service,caption,status(draft/in_review/changes_requested/approved/scheduling/scheduled/published/needs_reconciliation),schedule_date,schedule_time,scheduled_at(ISO UTC|null),image_url(auth URL|null),review_note,ghl_post_id|null,updated_at,approved_by|null. All scoped to fixed EP location; no credentials or media paths returned.

Media stays private until a signed URL for approved content is supplied to GHL. Upload PNG/JPEG/WebP under 10MB and bounded image dimensions; scheduling may reject unsupported MIME when necessary. Public URL must remain valid through scheduled time. Live tests may read existing records and save/review a local test draft, but must never schedule or publish a real test post without specific human approval of that exact content/time/destination.

GHL create endpoint requires type, accountIds and userId. Scheduling identity must be configured/verified separately; never select an arbitrary GHL user. All post creation uses status=scheduled, a future approved date, Version:v3 and the saved credential. Scheduling state must be persisted before the network request, and remote response location/status validated. No automatic retry on timeout/ambiguous response.

Read calendar uses live GHL data, NZ time, month/week/list, explicit pagination, 60-second visible-tab refresh. Insights show actual account totals and per-post metrics; campaign attribution is unavailable until locally saved records can be matched to GHL post IDs. Missing metrics stay unavailable.

## Implemented and deployed

Deployed 2026-10-26. Live account/post/statistics reads use the saved encrypted credential; the corrected statistics URL includes the fixed location query. Sharry Ricafort was explicitly chosen and verified as the scheduling creator. The user did not need to rotate the token. Live checks read the pre-existing Luv, Ep scheduled post and account metrics; ThumbAI stays unselected.

The private additive marketing_posts table stores exact versions, media hashes, approval identity/fingerprint and GHL result IDs. Signed approved media remains available for immutable needs_reconciliation records, so an accepted-but-timed-out GHL request can still fetch its approved artwork. No automatic retry is allowed. Legacy quick-post, approve-variant and reschedule paths are blocked after workflow activation. Existing Inbox/Ads and credential management remain available.

Versioned client runtime is resources/social-workspace; deployment copies it to the protected storage runtime. No Vite manifest was replaced. Source templates remain separately labelled; unsaved uploads are browser-only, while saved campaign artwork and captions persist with the campaign. Archiving library selections is still local to the browser.

Verification: 133 focused PHP tests /556 assertions passed, with existing PHP8.5 dependency deprecations. Client regressions cover deferred saving with changing media and month/week/list date navigation. Review found and fixed signed-media access after ambiguous outcomes and a client save/media race. A live private test draft was saved with artwork, survived reload, was submitted, received reviewer feedback, was revised, and was resubmitted as version 5. It remains in_review, unapproved, with no schedule time or GHL post ID. Anonymous private-media request returned401; unsigned shared-media request returned403. No live scheduling or publishing request was performed. Real external creation still occurs only when a Super Admin approves an actual saved version and confirms Schedule in GHL.

Calendar reads are live with a 60-second visible-view refresh; saved queue refreshes every30 seconds. GHL insights currently show actual account totals and available per-post metrics. Campaign attribution, leads and ad spend are explicitly unavailable rather than estimated. Currently only Luv, Ep Facebook is a configured posting destination; other social formats remain available as design previews.

Rollback artifacts: /home/deploy/luvep-backups/ghl-workflow-20261026/before.tar.gz, activation-state.json, new-files.json, polish-before.tar.gz and calendar-polish-before.tar.gz. The migration was run by its explicit path only. Preserve the new table and uploaded media when rolling back UI code; do not drop user data. If approved GHL posts exist, preserve their signed media route/controller until they are safely reconciled or cancelled. Disabling future writes must not revoke media needed by posts already accepted by GHL.

### Connected post media preview fix

The live scheduled GHL record contained an MP4. The old reader exposed its URL as an image, and the browser removed the failed `<img>`, leaving only the caption. The reader now returns typed, HTTPS-only media attachments and a separate poster for calendar cards. The post popup renders native video controls or the complete image gallery alongside the full caption, with visible failure messages and original-media links.

Verified: reader regressions cover video posters, playable media, multiple images and rejected unsafe URLs. The Marketing, Social and AI feature suites completed with 131 tests / 559 assertions (PHP 8.5 dependency deprecation notices). Calendar, deferred-save and new media-rendering Node regressions pass. Live inspection confirmed the existing 25-second GHL video, poster and complete caption in the popup. No GHL record was edited, scheduled or published.

Deployed the reader plus private workspace JS/HTML with hash guards and backups at `/home/deploy/luvep-backups/ghl-media-preview-20261026` and `/home/deploy/luvep-backups/ghl-media-preview-layout-20261026`. Runtime asset version: `ghl-live.js?v=5`. Visual proof: `social-review-preview/live-ghl-post-media.png` in the parent workspace.
