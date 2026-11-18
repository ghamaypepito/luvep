# Ticket attachment implementation plan

**Goal:** Staff can attach screenshots, screen recordings and multiple files to new tickets and conversation replies, then preview or download them on both My Tickets and the Admin ticket board.

**Approach:** Extend the existing private-disk ticket workflow with additive JSON attachment metadata on tickets and messages. Keep legacy screenshot paths and URLs working. Reuse the incumbent ticket forms, ownership rules and archived read-only behavior. No third-party storage or media service.

**Implementation choices:** Up to 10 attachments per submission, 50 MiB each, matching the existing documented server upload limits. Accept common image/video/document/archive types with server content validation. Images preview inline; MP4/WebM/MOV use native video controls (with download fallback for codec support); other files download. Attachments-only replies are allowed. Existing screenshots can coexist with new attachments.

**Public attachment contract:** `{name, mime, size, url, download_url}`; no stored path is exposed. Ticket URLs are `/system-tickets/{ticket}/attachments/{index}` and message URLs `/system-tickets/{ticket}/messages/{message}/attachments/{index}`. `url` uses `?inline=1` for image/video previews. Download URLs have no inline parameter. Owner/active support access only, including range requests; unrelated staff and client roles remain denied.

- [x] Add failing backend tests for mixed/multiple files, attachment-only replies, limits/type validation, private streaming, ownership, cross-ticket IDs, archived tickets and rollback cleanup.
- [x] Add nullable attachment JSON columns, private metadata/store/serialize/stream helper, and ticket/message endpoint integration. Preserve legacy screenshot behavior.
- [x] Add shared picker and attachment display components; update creation, replies, Admin and My Tickets. Support incremental selection, removal, clear errors, upload progress and draft retention.
- [x] Run focused UI tests with real component interactions and synthetic transports, then the full PHP suite and production frontend build. Review the change.
- [ ] Merge the validated PR, run GitHub deployment, and verify the live schema/routes/UI without creating tickets or uploading client data.
