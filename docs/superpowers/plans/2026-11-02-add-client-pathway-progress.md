# SDD ledger — plan: docs/superpowers/plans/2026-11-02-add-client-pathway.md

- Approved design: external docs/superpowers/specs/2026-11-02-add-client-pathway-design.md.
- Baseline: 9206ab58, latest existing committed application source. Clean existing isolated worktree reused on codex/add-client-pathway.
- Execution: inline; user approved implementation. No further approval needed for the reversible changes authorized by that approval.
- Pre-flight: Task 2 consumes GET/POST and metadata defined in Task 1. Task 3 verifies both. Interfaces agree.
- Ruling: Education uses a shared assigned_to owner, not a dedicated adviser field. Do not offer an Education adviser selector, because changing it would overwrite the source department's owner. English and Immigration use their existing destination-specific assignee lists.

- Task 1: complete. New route tests first failed with 404; 15 focused backend tests now pass, including replay safety, audit-failure rollback, team notification deduplication, and existing configured automation. Added the actual Education list assertion to the ticket scenario.
- Task 2: complete. Browser tests first failed with the missing component, then exposed focus restoration and end-of-dialog tab behavior. Both fixes are covered; seven Chrome interaction/layout tests pass with production CSS at 1280px and 390px.
- Regression verification: 42 backend tests / 330 assertions passed before the additional Education-list assertion; final verification follows review.
- Production asset build passed. PHP formatting passed. The design detector found no warnings in the new component; warnings in existing large profile components are unchanged incumbent styles outside this feature.
- Ruling: Active pathways in another department show progress without a navigation link for users who cannot enter that department. This preserves portal permissions; those users cannot open the other team's portal directly.
- Ruling: Initialization uses saveQuietly plus explicit history, staff activity, and semantic audit. A handoff must not trigger visa-stage emails or infer a fresh lodgement date. Existing configured Immigration conversion automation runs once after commit.
- Limitation: row locking is implemented for production MySQL, but local automated transaction tests use SQLite and do not prove simultaneous MySQL requests. Replay tests verify the idempotent branch.
