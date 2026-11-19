# TKT-MIGWUN — Dated personal weekly notes

## Findings

Emma requested dates and daily presentation for My Weekly Notes / Other Activities. Current main stores three undated personal weekly summary strings in `app_settings`, keyed by user and Monday. TKT-0KIOJF / PR97 groups the separate client activity list and does not address these notes. The shared department Weekly Notes card is also a separate feature.

## Resolution

Add personal dated Weekly accomplishment / Other activity entries to Education Reports. Each entry has a required actual activity date and text, supports edit/removal, and appears in collapsible day groups for the selected report period. Groups show note-entry counts, not completed-task or client-activity totals. Pagination is independent (`note_page`), with full per-day counts and explicit partial-page labels. Dates use the existing Pacific/Auckland report calendar. Future dates are rejected.

Preserve all existing weekly summaries and their existing save endpoint unchanged, including Next week's tasks. Keep these editable below the new dated section; no historical date is guessed, no setting is migrated, and old summary text is not duplicated into daily groups. Dated entries use a new table, not shared settings or client activity records. Creating/editing/removing notes leaves department notes, client counts, statuses, documents and other portals unchanged.

Only active internal users already authorized for Education Reports may write; every row is additionally scoped to the logged-in account, even for admins. No user ID is accepted from the request. Add retry keys, optimistic edit/removal revision checks with row locking, soft deletion, validation and escaped text rendering. Failed requests retain drafts; report filter changes keep the activity date. A new draft must be saved/discarded before another note can be edited or removed. Generic audit events omit note body and submission key. No mail, client records, provider calls or notification work is introduced.

## Deployment and acceptance

Run the additive `2026_11_19_010000_create_education_dated_report_notes_table` migration **before** serving the new report code. No new configuration or provider is needed. A rollback drops this new table; preserve its data before any rollback after live use. Existing app_settings notes remain intact.

On staging, use a synthetic Education user with an existing undated weekly summary. Add two notes on one date and one on another, check the weekly/day groups and counts, change the report period, edit a date into a different week, and verify another account cannot see/edit/remove the notes. Confirm old summaries and the existing client activity totals remain unchanged. Test stale edits/removal and retry after a failed request. After Sir's review/merge/deployment, obtain Emma's acceptance before Done.

Coordinate integration with PR97 because both touch PersonalReport.jsx, though the changes serve different sections. Re-run combined staging tests after merging.

## Verification

The accompanying backend tests cover preservation of personal/shared/other-department notes, date/category storage, filtering and NZ boundaries, repeated requests, spoofed actors, owner isolation (including admins), invalid/future dates, edits/stale revisions, soft removal and paginated counts. The actual React PersonalReport test covers daily groups, retained undated summaries, escaped text, independent forms, draft retry and date preservation, edit conflicts, explicit removal confirmation, pagination and empty state. Final results and commit/PR references are recorded in the PR handover.
