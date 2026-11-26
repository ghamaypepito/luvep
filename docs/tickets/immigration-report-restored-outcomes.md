# Immigration reports: restored outcomes

Period outcomes must describe dated Immigration decisions, not a restoration of a previously recorded value after a stage was cleared. An existing approval or decline followed by an empty Immigration stage and the same outcome previously appeared as a second decision. Department filtering alone could not distinguish it because the restored entry itself was labelled Immigration.

The report scans each case's full Immigration history, retaining the last nonempty state before filtering into the selected period or six-month trend. A repeated terminal outcome without an intervening substantive Immigration stage is excluded. Education/English entries and empty states do not reset that memory; future events do not change it. An undated prior state cannot supply an inferred decision date. Existing pathway-initialization exclusions remain in place.

The same event collection feeds outcome names, activity totals, the automatic conclusion and the monthly trend. Current-stage snapshots remain separate. A real Immigration stage transition followed by a decision remains reportable, as does a fresh decision on a separate visa-application case. Repeated RFI requests retain their existing dated behavior.

The Education edit paths already preserve omitted parallel-stage fields and record department-specific changes. The regression exercises the actual Education dashboard route for an Education-only move and for a legacy clear/restore sequence. This release changes report interpretation only: no saved stages, history, documents, access, notifications or client identities are repaired or rewritten.

Verification: the pre-fix HTTP regression returned three approvals where only one was new. Focused HTTP assertions cover current/historical periods, approvals older than the trend window, clear/empty/repeated approval and decline entries, an actual Education edit sequence, substantive Immigration transitions, repeated RFI requests and unchanged source records. Independent testing, separate review, required CI and live checks are recorded in the ticket resolution and release record.
