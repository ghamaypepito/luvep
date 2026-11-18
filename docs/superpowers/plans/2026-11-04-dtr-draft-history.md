# Draft daily reports and change history

Goal: Let employees edit work-report details on existing past drafts and submit against the original work date; let the direct head inspect an immutable change log.

Confirmed scope: Work-report fields only. Attendance corrections remain in the reviewed correction process. Submitted/reviewed/legacy records remain locked. Real edit/submission timestamps are retained.

Implementation:
- [x] Regression tests: past draft date preservation, actual submission timestamp, stale versions and attendance/ownership protection.
- [x] Immutable DTR revisions recorded transactionally for saved fields and status transitions; values kept out of the broader HR audit metadata.
- [x] Scoped history endpoint and DTR changes page: owner, current direct head, assigned reviewer and existing authorized HR reviewers only. Heads gain visibility without approval privileges. Pagination and no-store responses.
- [x] Employee Edit draft / Submit daily report flow, selected-date helper and shared before/after history. Add DTR changes navigation and history to time reviews.
- [ ] Frontend interaction coverage, HR regression suite, build, independent review, full CI, deploy and read-only production verification.

Review focus: unauthorized employee history access; manager reassignment; stale edits; past/future dates; accidental attendance modification; submitted locks; new feature cannot reconstruct changes made before release.

Review: independent reviewer found only the latest-60-record reachability gap. Added a validated own-date lookup with a regression test. Also blocked direct submissions for future work dates. Frontend interaction test and 26 focused backend/navigation tests pass; build passes. Full regression/CI and production verification pending.

Validation edge: clearing optional meeting/people counters previously produced a database error; normalized them to zero with an HTTP/history regression test. Shared navigation reuses the manager lookup and retains the existing query budget.
